<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use JsonException;
use rareform\inertia\models\Settings;
use rareform\inertia\models\SsrResponse;
use rareform\inertia\Plugin as Inertia;
use RuntimeException;
use Throwable;

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

        try {
            [$statusCode, $body] = $this->sendRequest(
                $renderUrl,
                $page,
                $settings->ssrTimeout,
            );
        } catch (Throwable $exception) {
            return $this->fail($page, $exception->getMessage(), $settings, $exception);
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
        $baseUrl = trim((string)App::parseEnv($settings->ssrUrl));

        return $baseUrl === '' ? null : rtrim($baseUrl, '/') . '/render';
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function sendRequest(string $url, array $page, float $timeout): array
    {
        $client = Craft::createGuzzleClient([
            'connect_timeout' => $timeout,
            'http_errors' => false,
            'timeout' => $timeout,
        ]);

        $response = $client->post($url, [
            'headers' => ['Accept' => 'application/json'],
            'json' => $page,
        ]);

        return [$response->getStatusCode(), (string)$response->getBody()];
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
    ): ?SsrResponse {
        $component = is_string($page['component'] ?? null) ? $page['component'] : 'unknown';
        $fullMessage = "Inertia SSR failed for component [{$component}]: {$message}";

        Craft::warning($fullMessage, __METHOD__);

        if ($settings->ssrThrowOnError) {
            throw new RuntimeException($fullMessage, 0, $previous);
        }

        return null;
    }
}
