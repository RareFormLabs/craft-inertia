<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use JsonException;
use rareform\inertia\models\Settings;
use rareform\inertia\models\SsrResponse;
use rareform\inertia\Plugin as Inertia;
use RuntimeException;
use Throwable;
use yii\helpers\Json;

class SsrGateway extends Component
{
    /**
     * Dispatch an Inertia page object to the configured SSR server.
     */
    public function dispatch(array $page, ?Settings $settings = null): ?SsrResponse
    {
        $settings ??= Inertia::getInstance()->settings;

        if (!$settings->ssrEnabled) {
            return null;
        }

        $renderUrl = $this->getRenderUrl($settings);
        if ($renderUrl === null) {
            return $this->fail($page, 'The SSR URL is empty.', $settings);
        }

        $queryPos = strpos($renderUrl, '?');
        $query = $queryPos === false ? '' : substr($renderUrl, $queryPos + 1);

        try {
            [$statusCode, $body] = $this->sendRequest(
                $renderUrl,
                $this->encodePage($page),
                // Guzzle treats 0 as "no timeout", so never let config bypass the bounds.
                min(max($settings->ssrTimeout, 0.1), 30.0),
                $settings->ssrRequestOptions,
            );
        } catch (Throwable $exception) {
            if ($query === '') {
                return $this->fail($page, $exception->getMessage(), $settings, $exception);
            }

            // Transport errors include the full URL, so keep query-string credentials
            // out of the log and don't chain the original exception.
            return $this->fail($page, str_replace($query, '[redacted]', $exception->getMessage()), $settings);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return $this->fail(
                $page,
                $this->getHttpErrorMessage($statusCode, $body),
                $settings,
            );
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->fail($page, 'The SSR server returned invalid JSON.', $settings, $exception);
        }

        // Vite's dev endpoint answers null while its module graph warms up.
        if ($data === null && $this->isViteDevEndpoint($renderUrl)) {
            return null;
        }

        if (!is_array($data) || !array_key_exists('body', $data) || !is_string($data['body'])) {
            return $this->fail($page, 'The SSR response did not include a valid body.', $settings);
        }

        $head = $this->normalizeHead($data['head'] ?? []);
        if ($head === null) {
            return $this->fail($page, 'The SSR response did not include a valid head.', $settings);
        }

        return new SsrResponse([
            'head' => $head,
            'body' => $data['body'],
        ]);
    }

    public function getRenderUrl(Settings $settings): ?string
    {
        $url = trim((string)App::parseEnv($settings->ssrUrl));

        if ($url === '') {
            return null;
        }

        // Keep any query string (e.g. an auth token) after the /render path.
        [$baseUrl, $query] = array_pad(explode('?', explode('#', $url, 2)[0], 2), 2, null);
        $baseUrl = rtrim($baseUrl, '/');
        $path = rtrim((string)parse_url($baseUrl, PHP_URL_PATH), '/');

        // Vite's dev endpoint renders at its own path.
        if (!str_ends_with($path, '/render') && !$this->isViteDevEndpoint($baseUrl)) {
            $baseUrl .= '/render';
        }

        return $query === null ? $baseUrl : "$baseUrl?$query";
    }

    /**
     * Whether the URL points at the @inertiajs/vite dev server's SSR endpoint.
     */
    private function isViteDevEndpoint(string $url): bool
    {
        return str_ends_with(rtrim((string)parse_url($url, PHP_URL_PATH), '/'), '/__inertia_ssr');
    }

    /**
     * Encodes the page object exactly as the client receives it, so SSR markup
     * hydrates against the same props (e.g. Arrayable objects via toArray()).
     */
    public function encodePage(array $page): string
    {
        return Json::encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function sendRequest(string $url, string $body, float $timeout, array $options = []): array
    {
        $client = $this->createClient(array_merge($options, [
            'connect_timeout' => $timeout,
            'http_errors' => false,
            'timeout' => $timeout,
        ]));

        $response = $client->post($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]);

        return [$response->getStatusCode(), (string)$response->getBody()];
    }

    protected function createClient(array $config): Client
    {
        return Craft::createGuzzleClient($config);
    }

    private function normalizeHead(mixed $head): ?string
    {
        if (is_string($head)) {
            return $head;
        }

        if (!is_array($head)) {
            return null;
        }

        foreach ($head as $element) {
            if (!is_string($element)) {
                return null;
            }
        }

        return implode("\n", $head);
    }

    private function getHttpErrorMessage(int $statusCode, string $body): string
    {
        $message = "The SSR server returned HTTP {$statusCode}.";

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $message;
        }

        if (is_array($data) && isset($data['error']) && is_string($data['error'])) {
            $message .= ' ' . $data['error'];
        }

        return $message;
    }

    private function fail(
        array $page,
        string $message,
        Settings $settings,
        ?Throwable $previous = null,
    ): null {
        $component = is_string($page['component'] ?? null) ? $page['component'] : 'unknown';
        $fullMessage = "Inertia SSR failed for component [{$component}]: {$message}";

        Craft::warning($fullMessage, __METHOD__);

        if ($settings->ssrThrowOnError) {
            throw new RuntimeException($fullMessage, 0, $previous);
        }

        return null;
    }
}
