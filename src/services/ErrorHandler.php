<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use rareform\inertia\Plugin as Inertia;
use Twig\Error\RuntimeError as TwigRuntimeError;
use yii\web\Response as YiiResponse;

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

        return $this->renderError($statusCode, $exception);
    }

    public function renderError(int $statusCode, $exception = null): \craft\web\Response
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
        $response->format = YiiResponse::FORMAT_RAW;
        $response->content = $this->getFallbackErrorContent($statusCode, $exception);

        return $response;
    }

    private function determineStatusCode(\Throwable $exception): int
    {
        if (method_exists($exception, 'getStatusCode')) {
            $statusCode = $exception->getStatusCode();
            if ($statusCode) {
                return (int)$statusCode;
            }
        }

        $publicProperties = get_object_vars($exception);
        if (!empty($publicProperties['statusCode'])) {
            return (int)$publicProperties['statusCode'];
        }

        return 500;
    }

    private function getFallbackErrorContent(int $statusCode, ?\Throwable $exception): string
    {
        $message = $exception?->getMessage();
        $devMode = Craft::$app->getConfig()->getGeneral()->devMode;
        $isClientError = $statusCode >= 400 && $statusCode < 500;

        if ($devMode && $message) {
            return $message;
        }

        if ($isClientError) {
            return $message ?: (YiiResponse::$httpStatuses[$statusCode] ?? (string)$statusCode);
        }

        return 'An error occurred';
    }
}
