<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use craft\web\Controller as Controller;
use yii\web\View;

use rareform\inertia\Plugin as Inertia;
use rareform\inertia\helpers\InertiaHelper;
use rareform\inertia\web\assets\axioshook\AxiosHookAsset;

class Renderer extends Component
{
    public function handleMatchedTemplate($template, $uri, $templateVariables): craft\web\Response|string|array
    {
        $templateVariables = $this->injectCurrentElement($templateVariables);

        try {
            // Process any pulls in the template first
            $processedTemplate = $this->processTemplatePulls($template);
        } catch (\Exception $e) {
            return Inertia::getInstance()->errorHandler->handleError($e);
        }

        try {
            $pageComponent = null;
            $props = [];
            // Get the final captured variables from template context after rendering
            $stringResponse = '';

            try {
                // Render the processed template
                $stringResponse = Craft::$app->getView()->renderString($processedTemplate, $templateVariables);
            } catch (\Exception $exception) {
                return Inertia::getInstance()->errorHandler->handleError($exception);
            }

            // New pattern: collect from Craft::$app->params
            $pageComponent = Craft::$app->params['__inertia_page'] ?? null;

            $props = InertiaHelper::extractInertiaPropsFromString($stringResponse);

            // Fallback: try to parse from output as before
            if ($pageComponent === null) {
                // Decode JSON object from $stringResponse
                $jsonData = json_decode($stringResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // If we can't decode JSON, log it and use default values
                    Craft::warning('JSON decoding failed: ' . json_last_error_msg() . '. Using default page component and props.', __METHOD__);
                    // Set default page component based on route
                    $pageComponent = $uri ?: 'Index';
                    $props = [];
                } else {
                    $pageComponent = $jsonData['component'] ?? ($uri ?: 'Index');
                    $props = $jsonData['props'] ?? [];
                }
            }

            // Merge variables in priority order: 
            // 1. Template variables passed from controller (lowest)
            // 2. Explicitly defined props in JSON response or via inertia()/page/prop (highest)
            $props = array_merge($templateVariables, $props);

            // return Inertia::getInstance()->renderer->render($pageComponent, params: $props);
            return [$pageComponent, $props];

        } catch (\Exception $e) {
            return Inertia::getInstance()->errorHandler->handleError($e);
        }
    }

    /**
     * Merge shared props and individual request props
     *
     * @param array $params
     * @return array
     */
    public function getInertiaProps($params = [], $view): array
    {
        $session = Craft::$app->session;

        if ($session->has('recentElementSave')) {
            $elementId = $session->get('recentElementSave');
            $params['recentElementSave'] = $elementId;
            $session->remove('recentElementSave');
        }

        $sharedProps = $this->getSharedPropsFromTemplates();
        $mergedParams = array_merge($sharedProps, $params);
        return InertiaHelper::resolvePartialProps($mergedParams, $view);
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
            glob($sharedPath . DIRECTORY_SEPARATOR . '*.twig') ?: [],
            glob($sharedPath . DIRECTORY_SEPARATOR . '*.html') ?: []
        );

        foreach ($files as $file) {
            $templatePath = $sharedDir . DIRECTORY_SEPARATOR . basename($file);

            if (Craft::$app->getView()->doesTemplateExist($templatePath)) {
                $stringResponse = Craft::$app->getView()->renderTemplate($templatePath);
                $allSharedProps = InertiaHelper::extractInertiaPropsFromString($stringResponse, $allSharedProps);
            }
        }

        return $allSharedProps;
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


            // This pattern will match both {% pull('path') %} and {% pull 'path' %}
            $pattern = '/\{%\s*pull\s*(?:\(\s*([^\)]+)\s*\)|([^%]+))%\}/';

            $processedContent = preg_replace_callback($pattern, function ($matches) use ($view) {
                // $matches[1] is for paren, $matches[2] is for no paren
                $pullPath = isset($matches[1]) && $matches[1] !== '' ? trim($matches[1]) : trim($matches[2]);

                $directPath = trim($pullPath, "'\"");
                $referencedPath = $view->resolveTemplate($directPath);

                if (!$referencedPath) {
                    Craft::warning("Template not found: {$pullPath}", __METHOD__);
                    return '';
                }

                return file_get_contents($referencedPath);
            }, $templateContent);

            return $processedContent;

        } finally {
            // Restore original template mode
            $view->setTemplateMode($originalMode);
        }
    }

    private function injectCurrentElement(array $templateVariables): array
    {
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
        return $templateVariables;
    }

    /* Unused. Kept for reference. */
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

        /*
         * Alternative Method
         * // Capture toolbar HTML
         * $toolbarHtml = $debug->getToolbarHtml();
         *
         * // Get assets
         * $yiiDebugPath = Craft::getAlias('@vendor/yiisoft/yii2-debug/src');
         * $toolbarCss = file_get_contents($yiiDebugPath . '/assets/css/toolbar.css');
         * $toolbarJs = file_get_contents($yiiDebugPath . '/assets/js/toolbar.js');
         *
         * // Combine and inject
         * $debugAssets = $toolbarHtml . "<style>{$toolbarCss}</style><script>{$toolbarJs}</script>";
         * $output = str_replace('</body>', $debugAssets . '</body>', $output);
         *
         * return $output;
         */
    }
}