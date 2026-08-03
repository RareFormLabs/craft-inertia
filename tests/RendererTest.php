<?php

namespace rareform\inertia\tests;

use Craft;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use PHPUnit\Framework\TestCase;
use rareform\inertia\models\InertiaPage;
use rareform\inertia\models\Settings;
use rareform\inertia\models\SsrResponse;
use rareform\inertia\Plugin as Inertia;
use rareform\inertia\services\Renderer;
use rareform\inertia\services\SsrGateway;

class RendererTest extends TestCase
{
    private mixed $originalApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApp = Craft::$app;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->originalApp;

        parent::tearDown();
    }

    public function testItOnlyDispatchesSsrForGetRequests(): void
    {
        $method = 'GET';
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod', 'getUrl'])
            ->getMock();
        $request->method('getMethod')->willReturnCallback(function () use (&$method) {
            return $method;
        });
        $request->method('getUrl')->willReturn('/posts');

        $response = new Response();
        $view = $this->getMockBuilder(View::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['registerAssetBundle', 'renderPageTemplate'])
            ->getMock();
        $view->method('renderPageTemplate')->willReturn('<html></html>');

        Craft::$app = new RendererTestApplication($request, $response, $view);

        $payload = [
            'component' => 'Posts/Index',
            'props' => ['posts' => []],
            'url' => '/posts',
            'version' => 'test',
        ];
        $ssrResponse = new SsrResponse([
            'head' => '<title>SSR</title>',
            'body' => '<div id="app">SSR</div>',
        ]);

        $gateway = $this->getMockBuilder(SsrGateway::class)
            ->onlyMethods(['dispatch'])
            ->getMock();
        $gateway->expects(self::once())
            ->method('dispatch')
            ->with($payload)
            ->willReturn($ssrResponse);

        $plugin = $this->getMockBuilder(Inertia::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInertiaVersion', 'getSettings', 'get', 'has'])
            ->getMock();
        $plugin->method('getInertiaVersion')->willReturn('test');
        $plugin->method('getSettings')->willReturn(new Settings());
        $plugin->method('has')
            ->willReturnCallback(fn(string $id, bool $checkInstance = false) => $id === 'ssrGateway');
        $plugin->method('get')
            ->with('ssrGateway')
            ->willReturn($gateway);
        Craft::$app->loadedModules[Inertia::class] = $plugin;

        $renderer = new RendererWithoutSharedProps();
        $renderer->renderPage($this->page());

        self::assertSame('<title>SSR</title>', $renderer->renderInertiaHead());
        self::assertSame('<div id="app">SSR</div>', $renderer->renderInertiaApp());

        $method = 'POST';
        $renderer->renderPage($this->page());

        self::assertSame('', $renderer->renderInertiaHead());
    }

    private function page(): InertiaPage
    {
        return new InertiaPage([
            'component' => 'Posts/Index',
            'props' => ['posts' => []],
        ]);
    }
}

class RendererWithoutSharedProps extends Renderer
{
    public function getInertiaProps(string $component, array $params = []): array
    {
        return $params;
    }
}

class RendererTestApplication
{
    public array $loadedModules = [];

    public function __construct(
        public Request $request,
        private readonly Response $response,
        private readonly View $view,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function getView(): View
    {
        return $this->view;
    }
}
