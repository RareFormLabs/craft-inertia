<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use rareform\inertia\Plugin as Inertia;
use Twig\Error\RuntimeError as TwigRuntimeError;

class ErrorHandler extends Component
{
    public function handleError($exception): \craft\web\Response
    {
        if ($exception instanceof TwigRuntimeError && ($previousException = $exception->getPrevious()) !== null) {
            $exception = $previousException;
        }

        $statusCode = $this->determineStatusCode($exception);
        if (Craft::$app->getConfig()->getGeneral()->devMode) {
            throw $exception;
        }

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

        return $this->renderError(Craft::$app->getRequest(), $statusCode, $exception);
    }

    public function renderError($request, int $statusCode, $exception = null): \craft\web\Response
    {
        $resolvedPage = Inertia::getInstance()->pageResolver->resolveErrorTemplate($statusCode, $exception);

        if ($resolvedPage !== null) {
            return Inertia::getInstance()->renderer->renderTemplateResponse(
                $resolvedPage['template'],
                $resolvedPage['uri'],
                $resolvedPage['variables'],
                $statusCode,
                false
            );
        }

        $response = Craft::$app->getResponse();
        $response->setStatusCode($statusCode);
        $response->format = \yii\web\Response::FORMAT_RAW;
        $response->content = $exception?->getMessage() ?? (string)$statusCode;

        return $response;
    }

    private function determineStatusCode(\Throwable $exception): int
    {
        if (property_exists($exception, 'statusCode') && $exception->statusCode) {
            return (int)$exception->statusCode;
        }

        if (method_exists($exception, 'getStatusCode') && $exception->getStatusCode()) {
            return (int)$exception->getStatusCode();
        }

        return 500;
    }
}
