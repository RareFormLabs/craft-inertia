<?php

namespace rareform\inertia\tests;

use PHPUnit\Framework\TestCase;
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

    protected function sendRequest(string $url, array $page, float $timeout): array
    {
        $this->requestCount++;
        $this->lastUrl = $url;
        $this->lastTimeout = $timeout;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->response;
    }
}
