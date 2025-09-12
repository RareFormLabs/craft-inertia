<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;

use rareform\inertia\Plugin as Inertia;

use Twig\Error\Error as TwigError;
use Twig\Error\LoaderError as TwigLoaderError;
use Twig\Error\RuntimeError as TwigRuntimeError;
use Twig\Error\SyntaxError as TwigSyntaxError;


class ErrorHandler extends Component
{
    public function handleError($exception): craft\web\Response|string|array
    {
        // If this is a Twig Runtime exception, use the previous one instead
        if ($exception instanceof TwigRuntimeError && ($previousException = $exception->getPrevious()) !== null) {
            $exception = $previousException;
        }


        $statusCode = 500;
        // Check if the exception has a statusCode property or method
        if ((is_object($exception) && property_exists($exception, 'statusCode') && $exception->statusCode) || (method_exists($exception, 'getStatusCode') && $exception->getStatusCode())) {
            $statusCode = property_exists($exception, 'statusCode') ? $exception->statusCode : $exception->getStatusCode();
            if (!Craft::$app->getConfig()->getGeneral()->devMode) {
                return $this->renderError(Craft::$app->getRequest(), $statusCode);
            }
            throw $exception;
        }

        Craft::$app->getResponse()->setStatusCode($statusCode);

        if ($exception instanceof TwigRuntimeError) {
            $sourceContext = $exception->getSourceContext();
            $templateFile = $sourceContext ? $sourceContext->getName() : 'unknown template';
            $templateLine = $exception->getTemplateLine();
            Craft::error(
                sprintf(
                    'Template rendering failed: %s in %s on line %d',
                    $exception->getMessage(),
                    $templateFile,
                    $templateLine
                ),
                __METHOD__
            );
        } else {
            Craft::error('Error processing Inertia template: ' . $exception->getMessage(), __METHOD__);
        }

        throw $exception;
    }

    /**
     * Renders an error template for any status code.
     *
     * @param \yii\web\Request $request
     * @param int $statusCode
     * @return craft\web\Response|string|array
     */
    public function renderError($request, int $statusCode): craft\web\Response|string|array
    {
        Craft::$app->getResponse()->setStatusCode($statusCode);
        return $this->errorPageRequest($this->resolveErrorTemplate($request, (string) $statusCode));
    }

    /**
     * Determines the correct error template to use for a given error code.
     *
     * @param \yii\web\Request $request
     * @param string $errorCode
     * @return string
     */
    protected function resolveErrorTemplate($request, string $errorCode): string
    {
        $view = Craft::$app->getView();
        $template = $errorCode;
        if ($request->getIsSiteRequest()) {
            $prefix = Craft::$app->getConfig()->getGeneral()->errorTemplatePrefix;
            if ($view->doesTemplateExist($prefix . $errorCode)) {
                $template = $prefix . $errorCode;
            } elseif ($view->doesTemplateExist($prefix . 'error')) {
                $template = $prefix . 'error';
            }
        }
        return $template;
    }

    public function errorPageRequest($errorCode): craft\web\Response|string|array
    {
        $templateVariables = [];
        $requestParams = Craft::$app->getUrlManager()->getRouteParams();

        // If the $requestParams contains a 'variables' associative array, move its contents to the top level and remove 'variables'.
        // Top-level keys take precedence over 'variables' keys; numeric keys are preserved.
        if (isset($requestParams['variables']) && is_array($requestParams['variables'])) {
            $requestParams = $requestParams + $requestParams['variables'];
            unset($requestParams['variables']);
        }

        $templateVariables = array_merge($requestParams, $templateVariables);
        $inertiaConfiguredDirectory = Inertia::getInstance()->settings->inertiaDirectory ?? null;
        $inertiaTemplatePath = $inertiaConfiguredDirectory ? $inertiaConfiguredDirectory . '/' . $errorCode : $errorCode;

        if (Craft::$app->getView()->doesTemplateExist($inertiaTemplatePath)) {
            return Inertia::getInstance()->renderer->handleMatchedTemplate($inertiaTemplatePath, $errorCode, $templateVariables);
        } else {
            throw new \yii\web\HttpException(500, 'Error template not found');
        }
    }
}