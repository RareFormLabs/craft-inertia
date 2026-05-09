<?php

namespace rareform\inertia\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use rareform\inertia\Plugin as Inertia;

class BaseController extends Controller
{
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function actionIndex(): Response|string|array
    {
        try {
            $resolvedPage = Inertia::getInstance()->pageResolver->resolveCurrentRequest();
        } catch (\Throwable $exception) {
            return Inertia::getInstance()->errorHandler->handleError($exception);
        }

        if ($resolvedPage === null) {
            return Inertia::getInstance()->errorHandler->renderError(Craft::$app->getRequest(), 404);
        }

        try {
            return Inertia::getInstance()->renderer->renderTemplateResponse(
                $resolvedPage['template'],
                $resolvedPage['uri'],
                $resolvedPage['variables']
            );
        } catch (\Throwable $exception) {
            return Inertia::getInstance()->errorHandler->handleError($exception);
        }
    }

    public function render($pageComponent, $params = []): Response|string|array
    {
        try {
            return Inertia::getInstance()->render($pageComponent, $params);
        } catch (\Throwable $exception) {
            return Inertia::getInstance()->errorHandler->handleError($exception);
        }
    }
}
