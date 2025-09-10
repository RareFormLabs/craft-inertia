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
use rareform\inertia\helpers\InertiaHelper;
use rareform\inertia\web\assets\axioshook\AxiosHookAsset;
use rareform\inertia\services\ErrorHandler;

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

        $requestParams = Craft::$app->getUrlManager()->getRouteParams();

        // If the $requestParams contains a 'variables' associative array, move its contents to the top level and remove 'variables'.
        // Top-level keys take precedence over 'variables' keys; numeric keys are preserved.
        if (isset($requestParams['variables']) && is_array($requestParams['variables'])) {
            $requestParams = $requestParams + $requestParams['variables'];
            unset($requestParams['variables']);
        }

        $templateVariables = array_merge($requestParams, $templateVariables);
        $matchesTwigTemplate = false;
        $specifiedTemplate = null;
        $inertiaConfiguredDirectory = Inertia::getInstance()->settings->inertiaDirectory ?? null;
        $inertiaTemplatePath = $inertiaConfiguredDirectory ? $inertiaConfiguredDirectory . '/' . $uri : $uri;

        // 1. If an element matches, use the element logic
        if ($element) {
            [$matchesTwigTemplate, $specifiedTemplate, $templateVariables] = $this->handleElementRequest($element, $uri);
        } else {
            // 2. Check for explicit template param (e.g., from routes.php) passed via 'template' (handled by InertiaUrlRule)
            $explicitTemplate = Craft::$app->UrlManager->getRouteParams()['inertiaTemplate'] ?? null;
            if ($explicitTemplate && Craft::$app->getView()->doesTemplateExist($explicitTemplate)) {
                $matchesTwigTemplate = true;
                $specifiedTemplate = $explicitTemplate;
                // Merge all route params except inertiaTemplate itself
                $templateVariables = array_merge($templateVariables, array_diff_key($request->getBodyParams() + $request->getQueryParams(), ['inertiaTemplate' => true]));
            } else if (Craft::$app->getView()->doesTemplateExist($inertiaTemplatePath)) {
                $matchesTwigTemplate = true;
            } else {
                // 3. Try to match a route from routes.php (for legacy/other cases)
                $routeMatch = $urlManager->parseRequest($request);
                if ($routeMatch && is_array($routeMatch) && isset($routeMatch[0])) {
                    $routeTemplate = $routeMatch[0];
                    $routeParams = isset($routeMatch[1]) && is_array($routeMatch[1]) ? $routeMatch[1] : [];
                    $matchesTwigTemplate = Craft::$app->getView()->doesTemplateExist($routeTemplate);
                    $specifiedTemplate = $routeTemplate;
                    $templateVariables = array_merge($templateVariables, $routeParams);
                } else {
                    // 4. Fallback to URI-based template lookup
                    $matchesTwigTemplate = Craft::$app->getView()->doesTemplateExist($inertiaTemplatePath);
                }
            }
        }

        if ($matchesTwigTemplate) {
            [$pageComponent, $props] = Inertia::getInstance()->renderer->handleMatchedTemplate($specifiedTemplate ?? $inertiaTemplatePath, $uri, $templateVariables);
        } else {
            [$pageComponent, $props] = Inertia::getInstance()->errorHandler->renderError($request, 404);
        }

        return $this->render($pageComponent, $props);
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
        $templateVariables = InertiaHelper::extractUriParameters($uri, $uriFormat);

        if ($element instanceof Entry) {
            $templateVariables['entry'] = $element;
        } elseif ($element instanceof Category) {
            $templateVariables['category'] = $element;
        }

        $matchesTwigTemplate = Craft::$app->getView()->doesTemplateExist($specifiedTemplate);
        return [$matchesTwigTemplate, $specifiedTemplate, $templateVariables];
    }

    /**
     * @param string $pageComponent
     * @param array $params
     * @return craft\web\Response|string|array
     */
    public function render($pageComponent, $params = []): \craft\web\Response|string|array
    {
        // Set params as expected in Inertia protocol
        // https://inertiajs.com/the-protocol
        $params = [
            'component' => $pageComponent,
            'props' => Inertia::getInstance()->renderer->getInertiaProps($params, $pageComponent),
            'url' => Craft::$app->request->getUrl(),
            'version' => $this->getInertiaVersion()
        ];

        // XHR-Request: just return params
        if (Craft::$app->request->headers->has('X-Inertia')) {
            return $params;
        }

        $inertiaDirectory = Inertia::getInstance()->settings->inertiaDirectory;
        $baseView = Inertia::getInstance()->settings->view;
        $template = $inertiaDirectory ? $inertiaDirectory . '/' . $baseView : $baseView;

        // Register our asset bundle
        $view = Craft::$app->getView();
        $view->registerAssetBundle(AxiosHookAsset::class, View::POS_END);

        return parent::renderTemplate($template, [
            'page' => $params
        ]);
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

}
