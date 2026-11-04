<?php

namespace rareform\inertia\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use rareform\inertia\Plugin as Inertia;
use yii\web\Request;
use yii\web\UrlManager;
use yii\web\UrlRule;

class CatchallUrlRulesTest extends TestCase
{
    #[DataProvider('inertiaPaths')]
    public function testItRoutesSitePathsToInertia(string $path): void
    {
        self::assertSame('inertia/base/index', $this->route($path)[0] ?? null);
    }

    #[DataProvider('wellKnownPaths')]
    public function testItLeavesWellKnownPathsToCraft(string $path): void
    {
        self::assertFalse($this->route($path));
    }

    public static function inertiaPaths(): array
    {
        return [
            'homepage' => [''],
            'page' => ['about'],
            'nested page' => ['news/some-story'],
            'similar prefix' => ['.well-known-ish/page'],
            'well-known later in the path' => ['docs/.well-known/change-password'],
        ];
    }

    public static function wellKnownPaths(): array
    {
        return [
            'change password' => ['.well-known/change-password'],
            'passkey endpoints' => ['.well-known/passkey-endpoints'],
            'AI catalog' => ['.well-known/ai-catalog.json'],
            'OAuth protected resource' => ['.well-known/oauth-protected-resource/admin/mcp'],
            'OAuth authorization server' => ['.well-known/oauth-authorization-server'],
        ];
    }

    /**
     * Returns the route of the first catch-all rule matching the path, or false.
     */
    private function route(string $path): array|false
    {
        $manager = new UrlManager();
        $request = new Request();
        $request->setPathInfo($path);

        foreach (Inertia::CATCHALL_URL_RULES as $pattern => $route) {
            $rule = new UrlRule(['pattern' => $pattern, 'route' => $route]);
            if (($result = $rule->parseRequest($manager, $request)) !== false) {
                return $result;
            }
        }

        return false;
    }
}
