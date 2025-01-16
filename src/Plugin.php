<?php

namespace rareform\inertia;

use Craft;
use rareform\inertia\models\Settings;
use rareform\inertia\web\twig\InertiaExtension;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\SetElementRouteEvent;
use craft\events\ModelEvent;
use craft\helpers\App;
use craft\web\Application;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class Plugin extends BasePlugin
{
    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function init()
    {
        parent::init();

        $this->attachEventHandlers();

        // Don't do anything if it is not a frontend request
        if (Craft::$app->request->isSiteRequest) {
            // Unset header since at least yii\web\ErrorAction is testing it
            Craft::$app->request->headers->set('X-Requested-With', null);
            Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'applicationAfterRequestHandler']);
            Craft::$app->response->on(Response::EVENT_BEFORE_SEND, [$this, 'responseBeforeSendHandler']);
        }

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function () { /** ... */});
        Craft::$app->view->registerTwigExtension(new InertiaExtension());
    }

    /**
     * Set Location header for redirects
     *
     * @param Event $event
     */
    public function applicationAfterRequestHandler($event): void
    {
        $response = Craft::$app->getResponse();
        if ($response->getHeaders()->has('X-Redirect')) {
            $url = $response->headers->get('X-Redirect', null, true);
            $response->headers->set('Location', $url);
        }
    }

    /**
     * Handle Inertia headers
     * see https://inertiajs.com/the-protocol
     *
     * @param Event $event
     */
    public function responseBeforeSendHandler($event): void
    {
        $request = Craft::$app->getRequest();
        $method = $request->getMethod();

        /** @var Response $response */
        $response = $event->sender;

        // Set fresh CSRF Token in first request
        if (!$request->headers->has('X-Inertia')) {
            if ($request->enableCsrfValidation) {
                $request->getCsrfToken(true);
            }
            return;
        }

        // XHR-Request: Return as JSON
        if ($response->isOk) {
            $response->format = Response::FORMAT_JSON;
            $response->headers->set('X-Inertia', 'true');
        }

        // Check for changed assets
        if ($method === 'GET') {
            if ($request->headers->has('X-Inertia-Version')) {
                $version = $request->headers->get('X-Inertia-Version', null, true);
                if ($version !== $this->getInertiaVersion()) {
                    $response->setStatusCode(409);
                    $response->headers->set('X-Inertia-Location', $request->getAbsoluteUrl());
                    return;
                }
            }
        }

        // Adjust Statuscode
        if ($response->getIsRedirection()) {
            if ($response->getStatusCode() === 302) {
                if (in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
                    $response->setStatusCode(303);
                }
            }
        }
    }

    /**
     * Get versioning finger print
     *
     * @return string
     */
    public function getInertiaVersion(): string
    {
        if (!$this->settings->useVersioning) {
            return '__noversioning__';
        }

        $hashes = [];
        foreach ($this->settings->assetsDirs as $assetDir) {
            $hashes[] = $this->hashDirectory(App::parseEnv($assetDir));
        }

        return md5(implode('', $hashes));
    }

    /**
     * Generate an MD5 hash string from the contents of a directory.
     *
     * @param string $directory
     * @return boolean|string
     */
    private function hashDirectory(string $directory): bool|string
    {
        $files = [];
        if (!is_dir($directory)) {
            return '';
        }
        $dir = dir($directory);
        while (($file = $dir->read()) !== false) {
            if ($file != '.' and $file != '..') {
                if (is_dir($directory . '/' . $file)) {
                    $files[] = $this->hashDirectory($directory . '/' . $file);
                } else {
                    $files[] = md5_file($directory . '/' . $file);
                }
            }
        }
        $dir->close();
        return md5(implode('', $files));
    }

    /*
     * Plugin settings
     */
    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                if (!$this->settings->takeoverRouting) {
                    return;
                }
                $event->rules = array_merge($event->rules, [
                    '' => 'inertia/base/index',
                    '<catchall:.+>' => 'inertia/base/index',
                ]);
            }
        );

        // Enable use of default template on the frontend
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['inertia'] = __DIR__ . '/templates';
            }
        );


        // Catch element routes set in Craft's CP
        // and route them to the Inertia controller
        Event::on(
            Element::class,
            Element::EVENT_SET_ROUTE,
            function (SetElementRouteEvent $event) {
                $event->route = 'inertia/base/index';

                // Explicitly tell the element that a route has been set,
                // and prevent other event handlers from running
                $event->handled = true;
            }
        );


        // After validation, set the current element to be used in the controller
        // so that validation errors can be injected into the template
        Event::on(
            Element::class,
            Element::EVENT_AFTER_VALIDATE,
            function (Event $event) {
                $element = $event->sender;
                Craft::$container->set('currentElement', $element);
            }
        );


        Event::on(
            Element::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                $element = $event->sender;
                if (!Craft::$app->request->isConsoleRequest) {
                    Craft::$app->session->set('recentElementSave', $element->id);
                }
            }
        );
    }
}
