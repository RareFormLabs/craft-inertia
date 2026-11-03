<?php

namespace rareform\inertia\tests;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use yii\base\Model;
use rareform\inertia\models\Settings;
use rareform\inertia\services\SsrGateway;
use RuntimeException;
use Throwable;

class SsrGatewayTest extends TestCase
{
    public function testItDoesNotDispatchWhenSsrIsDisabled(): void
    {
        $gateway = new FakeSsrGateway();

        self::assertNull($gateway->dispatch($this->page(), $this->settings([
            'ssrEnabled' => false,
        ])));
        self::assertSame(0, $gateway->requestCount);
    }

    public function testItDispatchesAndNormalizesTheResponse(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->response = [200, json_encode([
            'head' => ['<title>Test</title>', '<meta name="description" content="SSR">'],
            'body' => '<div id="app">Rendered</div>',
        ], JSON_THROW_ON_ERROR)];

        $response = $gateway->dispatch($this->page(), $this->settings());

        self::assertNotNull($response);
        self::assertSame("<title>Test</title>\n<meta name=\"description\" content=\"SSR\">", $response->head);
        self::assertSame('<div id="app">Rendered</div>', $response->body);
        self::assertSame('http://127.0.0.1:13714/render', $gateway->lastUrl);
        self::assertSame(2.0, $gateway->lastTimeout);
    }

    public function testItEncodesThePageLikeTheClientPayload(): void
    {
        $gateway = new FakeSsrGateway();
        $page = $this->page();
        $page['props']['entry'] = new SsrGatewayTestArrayable();

        $gateway->dispatch($page, $this->settings());

        self::assertSame(
            '{"component":"Posts/Index","props":{"posts":[],"entry":{"heading":"Café/Menu"}},"url":"/posts","version":"test"}',
            $gateway->lastBody,
        );
    }

    public function testItAcceptsAnSsrUrlThatAlreadyIncludesTheRenderPath(): void
    {
        $gateway = new SsrGateway();

        self::assertSame(
            'http://127.0.0.1:13714/render',
            $gateway->getRenderUrl($this->settings(['ssrUrl' => 'http://127.0.0.1:13714/render/'])),
        );
        self::assertSame(
            'http://render/render',
            $gateway->getRenderUrl($this->settings(['ssrUrl' => 'http://render'])),
        );
        self::assertSame(
            'https://ssr.example.test/render?token=abc',
            $gateway->getRenderUrl($this->settings(['ssrUrl' => 'https://ssr.example.test/?token=abc'])),
        );
        self::assertSame(
            'https://ssr.example.test/render?token=abc',
            $gateway->getRenderUrl($this->settings(['ssrUrl' => 'https://ssr.example.test/render?token=abc'])),
        );
        self::assertSame(
            'https://localhost:5173/__inertia_ssr',
            $gateway->getRenderUrl($this->settings(['ssrUrl' => 'https://localhost:5173/__inertia_ssr'])),
        );
        self::assertNull($gateway->getRenderUrl($this->settings(['ssrUrl' => ' '])));
    }

    public function testItBoundsTheTimeout(): void
    {
        $gateway = new FakeSsrGateway();

        $gateway->dispatch($this->page(), $this->settings(['ssrTimeout' => 0]));
        self::assertSame(0.1, $gateway->lastTimeout);

        $gateway->dispatch($this->page(), $this->settings(['ssrTimeout' => 600]));
        self::assertSame(30.0, $gateway->lastTimeout);
    }

    public function testItQuietlySkipsANullResponseFromTheViteDevEndpoint(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->response = [200, 'null'];

        self::assertNull($gateway->dispatch($this->page(), $this->settings([
            'ssrUrl' => 'http://localhost:5173/__inertia_ssr',
            'ssrThrowOnError' => true,
        ])));
    }

    public function testItRejectsANullResponseFromAnSsrServer(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->response = [200, 'null'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not include a valid body');

        $gateway->dispatch($this->page(), $this->settings(['ssrThrowOnError' => true]));
    }

    public function testItRedactsTheQueryStringFromTransportErrors(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->exception = new RuntimeException(
            'cURL error 7: Failed to connect (see …) for http://127.0.0.1:13714/render?token=s3cret',
        );

        try {
            $gateway->dispatch($this->page(), $this->settings([
                'ssrUrl' => 'http://127.0.0.1:13714?token=s3cret',
                'ssrThrowOnError' => true,
            ]));
            self::fail('Expected the SSR failure to throw.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
            self::assertStringContainsString('/render?[redacted]', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testItSendsRequestOptionsToTheHttpClient(): void
    {
        $gateway = new HandlerSsrGateway();

        $response = $gateway->dispatch($this->page(), $this->settings([
            'ssrRequestOptions' => ['verify' => false],
        ]));

        self::assertSame('<div id="app">Rendered</div>', $response?->body);
        self::assertFalse($gateway->options['verify']);
        self::assertSame(2.0, $gateway->options['timeout']);
        self::assertSame('POST', $gateway->request?->getMethod());
        self::assertSame('http://127.0.0.1:13714/render', (string)$gateway->request?->getUri());
        self::assertSame('application/json', $gateway->request?->getHeaderLine('Content-Type'));
    }

    public function testItGracefullyFallsBackWhenRenderingFails(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->response = [500, json_encode(['error' => 'window is not defined'], JSON_THROW_ON_ERROR)];

        self::assertNull($gateway->dispatch($this->page(), $this->settings()));
    }

    public function testItCanThrowWhenRenderingFails(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->exception = new RuntimeException('Connection refused');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Inertia SSR failed for component [Posts/Index]: Connection refused',
        );

        $gateway->dispatch($this->page(), $this->settings([
            'ssrThrowOnError' => true,
        ]));
    }

    public function testItRejectsMalformedResponses(): void
    {
        $gateway = new FakeSsrGateway();
        $gateway->response = [200, '{"head":[],"body":false}'];

        self::assertNull($gateway->dispatch($this->page(), $this->settings()));
    }

    private function page(): array
    {
        return [
            'component' => 'Posts/Index',
            'props' => ['posts' => []],
            'url' => '/posts',
            'version' => 'test',
        ];
    }

    private function settings(array $config = []): Settings
    {
        return new Settings(array_merge([
            'ssrEnabled' => true,
            'ssrUrl' => 'http://127.0.0.1:13714/',
            'ssrTimeout' => 2.0,
        ], $config));
    }
}

class FakeSsrGateway extends SsrGateway
{
    public int $requestCount = 0;
    public array $response = [200, '{"head":[],"body":""}'];
    public ?Throwable $exception = null;
    public ?string $lastUrl = null;
    public ?float $lastTimeout = null;
    public ?string $lastBody = null;

    protected function sendRequest(string $url, string $body, float $timeout, array $options = []): array
    {
        $this->requestCount++;
        $this->lastUrl = $url;
        $this->lastBody = $body;
        $this->lastTimeout = $timeout;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }
}

class SsrGatewayTestArrayable extends Model
{
    public string $title = 'Café/Menu';

    public function fields(): array
    {
        return ['heading' => 'title'];
    }
}

/**
 * Runs the real request path through a Guzzle handler that records what it receives.
 */
class HandlerSsrGateway extends SsrGateway
{
    public ?RequestInterface $request = null;
    public array $options = [];

    protected function createClient(array $config): Client
    {
        $handler = function(RequestInterface $request, array $options) {
            $this->request = $request;
            $this->options = $options;

            return Create::promiseFor(new Response(200, [], '{"head":[],"body":"<div id=\\"app\\">Rendered</div>"}'));
        };

        return new Client(['handler' => HandlerStack::create($handler)] + $config);
    }
}
