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
                $element = Craft::$container->get('currentElement');
                $templateVariables['element'] = $element;
            }

            try {
                $stringResponse = Craft::$app->getView()->renderTemplate($template, $templateVariables);
            } catch (\Exception $e) {
                Craft::error('Template rendering failed: ' . $e->getMessage(), __METHOD__);
                throw new \Exception('An error occurred while rendering the template.');
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

        // First request: Return full template
        return Craft::$app->view->renderTemplate($template, [
            'page' => $params
        ]);
    }

    /**
     * Merge shared props and individual request props
     *
     * @param array $params
     * @return array
     */
    private function getInertiaProps($params = [], $view): array
    {
        $sharedProps = $this->getSharedPropsFromTemplate();
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

    private function getSharedPropsFromTemplate()
    {
        $inertiaConfiguredDirectory = Inertia::getInstance()->settings->inertiaDirectory ?? null;
        $inertiaTemplatePath = $inertiaConfiguredDirectory ? $inertiaConfiguredDirectory . '/shared' : 'shared';

        $matchesTwigTemplate = Craft::$app->getView()->doesTemplateExist($inertiaTemplatePath);

        if (!$matchesTwigTemplate) {
            return [];
        }

        $stringResponse = Craft::$app->getView()->renderTemplate($inertiaTemplatePath);

        // Decode JSON object from $stringResponse
        $jsonData = json_decode($stringResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to decode JSON response: ' . json_last_error_msg());
        }

        return $jsonData;
    }

}
