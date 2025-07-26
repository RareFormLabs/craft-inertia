<?php

namespace rareform\inertia\controllers;

use Craft;
use craft\web\UrlManager;
use Illuminate\Support\Arr;
use craft\elements\Entry;
use craft\elements\Category;
use craft\services\Elements;

use yii\web\NotFoundHttpException;
use yii\web\View;

use craft\web\Controller as Controller;
use rareform\inertia\Plugin as Inertia;
use rareform\inertia\web\assets\axioshook\AxiosHookAsset;

/**
 * Controller controller
 */
class BaseController extends Controller
{
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * inertia/controller action
     */

    public function actionIndex(): craft\web\Response|string|array
    {
        $request = Craft::$app->getRequest();
        $uri = $request->getPathInfo();

        $urlManager = Craft::$app->getUrlManager();
        $element = $urlManager->getMatchedElement() ?: Craft::$app->getElements()->getElementByUri($uri);

        $templateVariables = [];
        $matchesTwigTemplate = false;

        if ($element) {
            [$matchesTwigTemplate, $specifiedTemplate, $templateVariables] = $this->handleElementRequest($element, $uri);
        } else {
            $inertiaConfiguredDirectory = Inertia::getInstance()->settings->inertiaDirectory ?? null;
            $inertiaTemplatePath = $inertiaConfiguredDirectory ? $inertiaConfiguredDirectory . '/' . $uri : $uri;

            $matchesTwigTemplate = Craft::$app->getView()->doesTemplateExist($inertiaTemplatePath);
        }

        if ($matchesTwigTemplate) {
            $template = $specifiedTemplate ?? $inertiaTemplatePath;

            if (Craft::$container->has('currentElement')) {
                // We're probably validating an element in a form
                // And need to give to give it the user to get validation errors
                $element = Craft::$container->get('currentElement');
                if ($element instanceof Entry) {
                    $templateVariables['entry'] = $element;
                } else if ($element instanceof Category) {
                    $templateVariables['category'] = $element;
                }
            }

            try {
                // Process any pulls in the template first
                $processedTemplate = $this->processTemplatePulls($template);
                
                // Will store template variables that get set during rendering
                $capturedVariables = [];
                $component = null;
                $explicitProps = [];
                // Get the final captured variables from template context after rendering
                $stringResponse = '';
                try {
                    // Check if variable capturing is enabled in settings
                    $captureVariables = Inertia::getInstance()->settings->autoCaptureVariables ?? false;
                    // Only add variable capturing if the setting is enabled
                    if ($captureVariables) {
                        // Auto-capture: rewrite all {% set foo = ... %} to {% set foo = prop('foo', ...) %}
                        $processedTemplate = preg_replace(
                            '/\{%\s*set\s+([a-zA-Z0-9_]+)\s*=\s*(.*?)\s*%\}/ms',
                            '{% set $1 = prop("$1", $2) %}',
                            $processedTemplate
                        );
                    }
                    // Render the processed template
                    // $stringResponse = Craft::$app->getView()->renderString($processedTemplate, []);
                    $stringResponse = Craft::$app->getView()->renderString($processedTemplate, $templateVariables);

                    // Legacy inertia() function support
                    $legacyComponent = \Craft::$app->has('inertiaComponent') ? \Craft::$app->get('inertiaComponent') : null;
                    $legacyExplicitProps = \Craft::$app->has('inertiaExplicitProps') ? \Craft::$app->get('inertiaExplicitProps') : [];

                    if ($legacyComponent) {
                        $component = $legacyComponent;
                        $explicitProps = $legacyExplicitProps;
                    } else {
                        // New pattern: collect from Craft::$app->params
                        $component = Craft::$app->params['__inertia_component'] ?? null;
                        $explicitProps = Craft::$app->params['__inertia_props'] ?? [];
                    }

                    // Fallback: try to parse from output as before
                    if ($component === null) {
                        // Decode JSON object from $stringResponse
                        $jsonData = json_decode($stringResponse, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            // If we can't decode JSON, log it and use default values
                            Craft::warning('JSON decoding failed: ' . json_last_error_msg() . '. Using default component and props.', __METHOD__);
                            // Set default component based on route
                            $component = $uri ?: 'Index';
                            $explicitProps = [];
                        } else {
                            $component = $jsonData['component'] ?? ($uri ?: 'Index');
                            $explicitProps = $jsonData['props'] ?? [];
                        }
                    }
                } catch (\Twig\Error\RuntimeError $e) {
                    $sourceContext = $e->getSourceContext();
                    $templateFile = $sourceContext ? $sourceContext->getName() : 'unknown template';
                    $templateLine = $e->getTemplateLine();
                    Craft::error(
                        sprintf(
                            'Template rendering failed: %s in %s on line %d',
                            $e->getMessage(),
                            $templateFile,
                            $templateLine
                        ),
                        __METHOD__
                    );
                    throw $e;
                } catch (\Exception $e) {
                    Craft::error($e->__toString(), __METHOD__);
                    throw $e;
                }

                if (Inertia::getInstance()->settings->injectElementAsProp !== true) {
                    unset($templateVariables['entry']);
                    unset($templateVariables['category']);
                }

                // Merge variables in priority order: 
                // 1. Template variables passed from controller (lowest)
                // 2. Variables set in the template itself via {% set %}
                // 3. Explicitly defined props in JSON response or via inertia()/component/prop (highest)
                $props = array_merge($templateVariables, $capturedVariables, $explicitProps);

                return $this->render($component, params: $props);
            } catch (\Exception $e) {
                Craft::error('Error processing Inertia template: ' . $e->getMessage(), __METHOD__);
                throw $e;
            }
            
            throw new NotFoundHttpException('No matching template found');
        }
    }



    private ?string $only = '';


    /*
     * Capture request for partial reload
     */
    public function beforeAction($action): bool
    {
        if (Craft::$app->request->headers->has('X-Inertia-Partial-Data')) {
            $this->only = Craft::$app->request->headers->get('X-Inertia-Partial-Data');
        }

        return true;
    }

    /**
     * @param string $view
     * @param array $params
     * @return array|string
     */
    public function render($view, $params = []): craft\web\Response|string|array
    {
        // Set params as expected in Inertia protocol
        // https://inertiajs.com/the-protocol
        $params = [
            'component' => $view,
            'props' => $this->getInertiaProps($params, $view),
            'url' => $this->getInertiaUrl(),
            'version' => $this->getInertiaVersion()
        ];

        // XHR-Request: just return params
        if (Craft::$app->request->headers->has('X-Inertia')) {
            return $params;
        }

        $inertiaDirectory = Inertia::getInstance()->settings->inertiaDirectory;
        $baseView = Inertia::getInstance()->settings->view;
        $template = $inertiaDirectory ? $inertiaDirectory . '/' . $baseView : $baseView;

        $view = Craft::$app->getView();

        // Register our asset bundle
        $view->registerAssetBundle(AxiosHookAsset::class, View::POS_END);

        return parent::renderTemplate($template, [
            'page' => $params
        ]);
    }

    private function injectYiiDebugToolbar($debug, string $input, $view): string
    {
        // Start output buffering
        ob_start();

        // Set up minimal debug module to get toolbar HTML
        $debug = Craft::$app->getModule('debug', false);

        // Get debug toolbar
        $event = new \yii\base\Event();
        $event->sender = $view;
        $debug->renderToolbar($event);

        // Get all buffered content
        $fullOutput = ob_get_clean();

        // Insert debug output before closing body tag
        return str_replace('</body>', $fullOutput . '</body>', $input);

        // Alternative Method
        // // Capture toolbar HTML
        // $toolbarHtml = $debug->getToolbarHtml();

        // // Get assets
        // $yiiDebugPath = Craft::getAlias('@vendor/yiisoft/yii2-debug/src');
        // $toolbarCss = file_get_contents($yiiDebugPath . '/assets/css/toolbar.css');
        // $toolbarJs = file_get_contents($yiiDebugPath . '/assets/js/toolbar.js');

        // // Combine and inject
        // $debugAssets = $toolbarHtml . "<style>{$toolbarCss}</style><script>{$toolbarJs}</script>";
        // $output = str_replace('</body>', $debugAssets . '</body>', $output);

        // return $output;
    }

    /**
     * Merge shared props and individual request props
     *
     * @param array $params
     * @return array
     */
    private function getInertiaProps($params = [], $view): array
    {
        $session = Craft::$app->session;

        if ($session->has('recentElementSave')) {
            $elementId = $session->get('recentElementSave');
            $params['recentElementSave'] = $elementId;
            $session->remove('recentElementSave');
        }

        $sharedProps = $this->getSharedPropsFromTemplates();
        $mergedParams = array_merge($sharedProps, $params);
        return $this->resolvePartialProps($mergedParams, $view);
    }

    /**
     * Request URL
     *
     * @return string
     */
    private function getInertiaUrl(): string
    {
        return Craft::$app->request->getUrl();
    }

    /**
     * Asset version finger print
     *
     * @return string
     */
    private function getInertiaVersion(): string
    {
        return Inertia::getInstance()->getInertiaVersion();
    }

    /**
     * Resolve the `only` and `except` partial request props.
     */
    public function resolvePartialProps(array $props, string $view): array
    {
        $isPartial = Craft::$app->request->headers->get('X-Inertia-Partial-Component') === $view;

        if (!$isPartial) {
            return $props;
            // Craft CMS Inertia Adapter doesn't support LazyProps for now
            //     return array_filter($props, static function ($prop) {
            //         return !($prop instanceof LazyProp);
            //     });
        }

        $only = array_filter(explode(',', Craft::$app->request->headers->get('X-Inertia-Partial-Data')));
        $except = array_filter(explode(',', Craft::$app->request->headers->get('X-Inertia-Partial-Except')));

        $props = $only ? Arr::only($props, $only) : $props;

        if ($except) {
            Arr::forget($props, $except);
        }

        return $props;
    }

    private function extractUriParameters($uri, $uriFormat)
    {
        // Convert the format into a regex pattern
        $pattern = preg_replace('/\{([^}]+)\}/', '([^\/]+)', $uriFormat);
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '#^' . $pattern . '$#'; // Using # as delimiter instead of '/'

        // Extract parameter names from the format
        preg_match_all('/\{([^}]+)\}/', $uriFormat, $paramNames);
        $paramNames = $paramNames[1];

        // Extract values from the URI
        preg_match($pattern, $uri, $matches);

        // Remove the full match
        array_shift($matches);

        // Combine parameter names with their values
        $parameters = array_combine($paramNames, $matches);

        if ($parameters === false) {
            throw new \Exception('Failed to combine parameter names and values.');
        }

        return $parameters;
    }

    private function handleElementRequest($element, $uri)
    {
        $sectionOrGroup = $element instanceof Entry ? $element->getSection() : $element->getGroup();

        $site = Craft::$app->getSites()->getCurrentSite();
        $siteID = $site->id;

        /** @var array $siteSettings */
        $siteSettings = $sectionOrGroup->getSiteSettings();
        $siteSetting = null;
        foreach ($siteSettings as $setting) {
            if ($setting->siteId === $siteID) {
                $siteSetting = $setting;
                break;
            }
        }

        if ($siteSetting === null) {
            throw new \Exception('No section site setting found for the current site.');
        }

        $uriFormat = $siteSetting->uriFormat;

        $specifiedTemplate = $siteSetting->template;
        $templateVariables = $this->extractUriParameters($uri, $uriFormat);

        if  ($element instanceof Entry) {
            $templateVariables['entry'] = $element;
        } elseif ($element instanceof Category) {
            $templateVariables['category'] = $element;
        }

        $matchesTwigTemplate = Craft::$app->getView()->doesTemplateExist($specifiedTemplate);
        return [$matchesTwigTemplate, $specifiedTemplate, $templateVariables];
    }

    private function getSharedPropsFromTemplates()
    {
        $inertiaConfiguredDirectory = Inertia::getInstance()->settings->inertiaDirectory ?? null;
        $sharedDir = $inertiaConfiguredDirectory ? $inertiaConfiguredDirectory . '/_shared' : '_shared';

        // Get the path to templates directory
        $templatesPath = Craft::$app->getPath()->getSiteTemplatesPath();
        $sharedPath = $templatesPath . DIRECTORY_SEPARATOR . $sharedDir;

        if (!is_dir($sharedPath)) {
            return [];
        }

        $allSharedProps = [];

        // Read all .twig and .html files in the _shared directory
        $files = array_merge(
            glob($sharedPath . DIRECTORY_SEPARATOR . '*.twig'),
            glob($sharedPath . DIRECTORY_SEPARATOR . '*.html')
        );

        foreach ($files as $file) {
            $templatePath = $sharedDir . DIRECTORY_SEPARATOR . basename($file);

            if (Craft::$app->getView()->doesTemplateExist($templatePath)) {
                $stringResponse = Craft::$app->getView()->renderTemplate($templatePath);

                // Decode JSON object from each template
                $jsonData = json_decode($stringResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Craft::warning('Failed to decode JSON response from ' . basename($file) . ': ' . json_last_error_msg(), __METHOD__);
                    continue;
                }

                $allSharedProps = array_merge($allSharedProps, $jsonData);
            }
        }

        return $allSharedProps;
    }

    /**
     * Process any template pull tags in the template
     */
    private function processTemplatePulls(string $template): string
    {
        $view = Craft::$app->getView();
        
        // Store original template mode and switch to site mode
        $originalMode = $view->getTemplateMode();
        $view->setTemplateMode($view::TEMPLATE_MODE_SITE);
        
        try {
            // Find the actual template file
            $templatePath = $view->resolveTemplate($template);
            if (!$templatePath) {
                throw new \Exception("Template not found: {$template}");
            }
            
            // Read the template contents
            $templateContent = file_get_contents($templatePath);

            // This pattern will match {% pull('path') %}
            $pattern = '/\{%\s*pull\s*\(\s*([^\)]+)\s*\)\s*%\}/';

            $processedContent = preg_replace_callback($pattern, function($matches) use ($view) {
                $pullPath = trim($matches[1]);

                $directPath = trim($pullPath, "'\"");
                $referencedPath = $view->resolveTemplate($directPath);
                
                if (!$referencedPath) {
                    Craft::warning("Template not found: {$pullPath}", __METHOD__);
                    return ''; // Or handle the error as needed
                }
                
                return file_get_contents($referencedPath);
            }, $templateContent);
            
            return $processedContent;
            
        } finally {
            // Restore original template mode
            $view->setTemplateMode($originalMode);
        }
    }

}
