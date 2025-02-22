<?php

namespace rareform\inertia\controllers;

use Craft;
use craft\web\UrlManager;
use Illuminate\Support\Arr;
use craft\services\Elements;

use yii\web\NotFoundHttpException;

use craft\web\Controller as Controller;
use rareform\inertia\Plugin as Inertia;

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

    public function actionIndex(): array|string
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
                // We're probably validating an element in a form
                // And need to give to give it the user to get validation errors
                $element = Craft::$container->get('currentElement');
                $templateVariables['element'] = $element;
            }

            try {
                // Process any inheritance in the template first
                $processedTemplate = $this->processTemplateInheritance($template);
                
                // Render the processed template
                $stringResponse = Craft::$app->getView()->renderString($processedTemplate, $templateVariables);
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

            // Decode JSON object from $stringResponse
            $jsonData = json_decode($stringResponse, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Craft::error('JSON decoding failed: ' . json_last_error_msg(), __METHOD__);
                throw new \Exception('Failed to decode JSON response.');
            }

            $component = $jsonData['component'];
            $props = $jsonData['props'] ?? [];

            if (Inertia::getInstance()->settings->injectElementAsProp !== true) {
                unset($templateVariables['element']);
            }

            // Merge $props with $templateVariables, $props takes precedence
            $props = array_merge($templateVariables, $props);

            return $this->render($component, params: $props);
        } else {
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
    public function render($view, $params = []): array|string
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

        // First request: Return full template
        $output = $view->renderTemplate($template, [
            'page' => $params
        ]);

        // Get debug module
        $debug = Craft::$app->getModule('debug', false);
        if ($debug) {
            // Inject debug toolbar
            $output = $this->injectYiiDebugToolbar($debug, $output, $view);
        }

        return $output;
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
        $section = $element->getSection();

        $site = Craft::$app->getSites()->getCurrentSite();
        $siteID = $site->id;

        /** @var array $sectionSiteSettings */
        $sectionSiteSettings = $section->getSiteSettings();
        $sectionSiteSetting = null;
        foreach ($sectionSiteSettings as $setting) {
            if ($setting->siteId === $siteID) {
                $sectionSiteSetting = $setting;
                break;
            }
        }

        if ($sectionSiteSetting === null) {
            throw new \Exception('No section site setting found for the current site.');
        }

        $uriFormat = $sectionSiteSetting->uriFormat;

        $specifiedTemplate = $sectionSiteSetting->template;
        $templateVariables = $this->extractUriParameters($uri, $uriFormat);
        $templateVariables['element'] = $element;

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

    private function processTemplateInheritance(string $template): string
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

            // This pattern will match {% inherit('path') %}
            $pattern = '/\{%\s*inherit\s*\(\s*([^\)]+)\s*\)\s*%\}/';

            $processedContent = preg_replace_callback($pattern, function($matches) use ($view) {
                $inheritPath = trim($matches[1]);

                $directPath = trim($inheritPath, "'\"");
                $referencedPath = $view->resolveTemplate($directPath);
                
                if (!$referencedPath) {
                    Craft::warning("Template not found: {$inheritPath}", __METHOD__);
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
