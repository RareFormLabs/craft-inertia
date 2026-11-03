<?php

namespace rareform\inertia\tests;

use Craft;
use craft\web\Request;
use craft\web\Response;
use craft\web\View;
use PHPUnit\Framework\MockObject\MockObject;
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
    private string $method = 'GET';
    private Settings $settings;
    private SsrGateway&MockObject $gateway;
    private RendererWithoutSharedProps $renderer;

    /**
     * Root template body; each call renders the Twig helpers in order.
     *
     * @var list<callable(Renderer): string>
     */
    private array $template = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApp = Craft::$app;
        $this->settings = new Settings();
        $this->renderer = new RendererWithoutSharedProps();
        $this->template = [
            fn(Renderer $renderer) => $renderer->renderInertiaHead(),
            fn(Renderer $renderer) => $renderer->renderInertiaApp(),
        ];

        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMethod', 'getUrl'])
            ->getMock();
        $request->method('getMethod')->willReturnCallback(fn() => $this->method);
        $request->method('getUrl')->willReturn('/posts');

        $view = $this->getMockBuilder(View::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['registerAssetBundle', 'renderPageTemplate'])
            ->getMock();
        $view->method('renderPageTemplate')->willReturnCallback(fn() => implode('|', array_map(
            fn(callable $part) => $part($this->renderer),
            $this->template,
        )));

        Craft::$app = new RendererTestApplication($request, $view);

        $this->gateway = $this->getMockBuilder(SsrGateway::class)
            ->onlyMethods(['dispatch'])
            ->getMock();

        $plugin = $this->getMockBuilder(Inertia::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInertiaVersion', 'getSettings', 'get', 'has'])
            ->getMock();
        $plugin->method('getInertiaVersion')->willReturn('test');
        $plugin->method('getSettings')->willReturnCallback(fn() => $this->settings);
        $plugin->method('has')
            ->willReturnCallback(fn(string $id, bool $checkInstance = false) => $id === 'ssrGateway');
        $plugin->method('get')
            ->with('ssrGateway')
            ->willReturn($this->gateway);
        Craft::$app->loadedModules[Inertia::class] = $plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->originalApp;

        parent::tearDown();
    }

    public function testItRendersSsrOutputAndDispatchesOnce(): void
    {
        $this->gateway->expects(self::once())
            ->method('dispatch')
            ->with($this->payload())
            ->willReturn(new SsrResponse([
                'head' => '<title>SSR</title>',
                'body' => '<div id="app">SSR</div>',
            ]));

        $response = $this->renderer->renderPage($this->page());

        self::assertSame('<title>SSR</title>|<div id="app">SSR</div>', $response->content);
    }

    public function testItDoesNotDispatchForNonGetRequests(): void
    {
        $this->method = 'POST';
        $this->gateway->expects(self::never())->method('dispatch');

        $response = $this->renderer->renderPage($this->page());

        self::assertStringStartsWith('|<div id="app" data-page=', $response->content);
    }

    public function testItDoesNotDispatchWhenTheTemplateDoesNotUseTheHelpers(): void
    {
        $this->template = [fn() => '<div id="app"></div>'];
        $this->gateway->expects(self::never())->method('dispatch');

        $this->renderer->renderPage($this->page());
    }

    public function testItFallsBackToADataPageAttribute(): void
    {
        $this->gateway->method('dispatch')->willReturn(null);
        $this->template = [fn(Renderer $renderer) => $renderer->renderInertiaApp('root')];

        $response = $this->renderer->renderPage($this->page());

        self::assertSame(
            '<div id="root" data-page="{&quot;component&quot;:&quot;Posts/Index&quot;,&quot;props&quot;:{&quot;title&quot;:&quot;&lt;/script&gt;&quot;},&quot;url&quot;:&quot;/posts&quot;,&quot;version&quot;:&quot;test&quot;}"></div>',
            $response->content,
        );
    }

    public function testItFallsBackToAScriptElement(): void
    {
        $this->settings->useScriptElementForInitialPage = true;
        $this->gateway->method('dispatch')->willReturn(null);
        $this->template = [fn(Renderer $renderer) => $renderer->renderInertiaApp()];

        $response = $this->renderer->renderPage($this->page());

        self::assertSame(
            '<script type="application/json" data-page="app">{"component":"Posts/Index","props":{"title":"\u003C/script\u003E"},"url":"/posts","version":"test"}</script><div id="app"></div>',
            $response->content,
        );
    }

    public function testHelpersRenderNothingOutsideARootTemplate(): void
    {
        $this->gateway->expects(self::never())->method('dispatch');

        self::assertSame('', $this->renderer->renderInertiaHead());
        self::assertSame('', $this->renderer->renderInertiaApp());
    }

    private function page(): InertiaPage
    {
        return new InertiaPage([
            'component' => 'Posts/Index',
            'props' => ['title' => '</script>'],
        ]);
    }

    private function payload(): array
    {
        return [
            'component' => 'Posts/Index',
            'props' => ['title' => '</script>'],
            'url' => '/posts',
            'version' => 'test',
        ];
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
    public string $charset = 'UTF-8';
    private ?Response $response = null;

    public function __construct(
        public Request $request,
        private readonly View $view,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response ??= new Response();
    }

    public function getView(): View
    {
        return $this->view;
    }
}
