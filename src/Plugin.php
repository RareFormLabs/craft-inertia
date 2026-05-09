<?php

namespace rareform\inertia;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\base\Element;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\RegisterUrlRulesEvent;
use craft\events\SetElementRouteEvent;
use craft\helpers\App;
use craft\web\Application;
use craft\web\UrlManager;
use rareform\inertia\models\Settings;
use rareform\inertia\web\twig\InertiaExtension;
use yii\base\Event;
use yii\web\Response;

class Plugin extends BasePlugin
{
    public static function config(): array
    {
        return [
            'components' => [
                'renderer' => \rareform\inertia\services\Renderer::class,
                'errorHandler' => \rareform\inertia\services\ErrorHandler::class,
                'pageResolver' => \rareform\inertia\services\PageResolver::class,
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

        if (Craft::$app->request->isSiteRequest) {
            Craft::$app->on(Application::EVENT_AFTER_REQUEST, [$this, 'applicationAfterRequestHandler']);
            Craft::$app->response->on(Response::EVENT_BEFORE_SEND, [$this, 'responseBeforeSendHandler']);
        }

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

        /** @var Response $response */
        $response = $event->sender;

        if (!$request->headers->has('X-Inertia')) {
            if ($request->enableCsrfValidation) {
                $request->getCsrfToken(true);
            }
            return;
        }

        if (
            $response->getIsRedirection() &&
            $response->getStatusCode() === 302 &&
            in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            $response->setStatusCode(303);
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

    public function getRoutingMode(): string
    {
        return $this->settings->getResolvedRoutingMode();
    }

    public function isCatchallRoutingEnabled(): bool
    {
        return $this->getRoutingMode() === 'catchall';
    }

    public function render(string $component, array $props = []): Response
    {
        return $this->renderer->renderComponent($component, $props);
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                foreach ($event->rules as &$rule) {
                    if (is_array($rule) && !empty($rule['inertia'])) {
                        $rule['class'] = 'rareform\inertia\web\InertiaUrlRule';
                    }
                }

                if ($this->isCatchallRoutingEnabled()) {
                    $event->rules = array_merge($event->rules, [
                        '' => 'inertia/base/index',
                        '<catchall:.+>' => 'inertia/base/index',
                    ]);
                }
            }
        );

        // Catch element routes set in Craft's CP
        // and route them to the Inertia controller
        Event::on(
            Element::class,
            Element::EVENT_SET_ROUTE,
            function (SetElementRouteEvent $event) {
                if (!$this->isCatchallRoutingEnabled()) {
                    return;
                }

                $element = $event->sender;
                if (!$element) {
                    return;
                }

                $isCraftElement = $element instanceof Entry || $element instanceof Category;
                if (!$isCraftElement) {
                    return;
                }

                $event->route = 'inertia/base/index';
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
    }
}
