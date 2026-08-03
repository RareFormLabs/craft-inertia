<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use craft\web\Response;
use craft\web\View;
use craft\elements\Entry;
use craft\elements\Category;
use rareform\inertia\models\InertiaPage;
use rareform\inertia\models\SsrResponse;
use rareform\inertia\Plugin as Inertia;
use rareform\inertia\helpers\InertiaHelper;
use rareform\inertia\web\assets\axioshook\AxiosHookAsset;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\Json;

class Renderer extends Component
{
    private array $captureStack = [];
    private ?array $rootPagePayload = null;
    private ?SsrResponse $ssrResponse = null;

    public function setPageComponent(string $component): void
    {
        $page = $this->getOrCreateCapture();
        $page->component = $component;
    }

    public function addProp(string $name, mixed $value): void
    {
        $this->getOrCreateCapture()->addProp($name, $value);
    }

    public function mergeProps(array $props): void
    {
        $this->getOrCreateCapture()->mergeProps($props);
    }

    public function markBypass(bool $download = false): void
    {
        $page = $this->getOrCreateCapture();
        $page->bypass = true;
        $page->download = $download;
    }

    public function renderTemplateResponse(
        string $template,
        string $uri,
        array $templateVariables = [],
        int $statusCode = 200,
        bool $handleErrors = true,
    ): Response {
        try {
            $page = $this->renderTemplateToPage($template, $uri, $templateVariables);
            $page->statusCode = $statusCode;

            return $this->renderPage($page);
        } catch (\Throwable $exception) {
            if (!$handleErrors) {
                throw $exception;
            }

            return Inertia::getInstance()->errorHandler->handleError($exception);
        }
    }

    public function renderComponent(
        string $component,
        array $props = [],
        int $statusCode = 200,
        bool $handleErrors = true,
    ): Response {
        try {
            $page = new InertiaPage([
                "component" => $component,
                "props" => $props,
                "statusCode" => $statusCode,
            ]);

            return $this->renderPage($page);
        } catch (\Throwable $exception) {
            if (!$handleErrors) {
                throw $exception;
            }

            return Inertia::getInstance()->errorHandler->handleError($exception);
        }
    }

    public function renderPage(InertiaPage $page): Response
    {
        $request = Craft::$app->getRequest();

        if (!$page->bypass) {
            if ($this->isVersionConflictRequest($request)) {
                return $this->createVersionConflictResponse($request);
            }

            $page->props = $this->getInertiaProps($page->component ?? "", $page->props);
        }

        if ($page->bypass) {
            return $this->createRawResponse($page);
        }

        $response = Craft::$app->getResponse();
        $response->setStatusCode($page->statusCode);
        $response->headers->add("Vary", "X-Inertia");

        $payload = [
            "component" => $page->component,
            "props" => $page->props,
            "url" => Craft::$app->request->getUrl(),
            "version" => $this->getInertiaVersion(),
        ];

        if ($request->headers->has("X-Inertia")) {
            $response->format = Response::FORMAT_JSON;
            $response->data = $payload;
            $response->headers->set("X-Inertia", "true");

            return $response;
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(AxiosHookAsset::class, View::POS_END);

        $this->rootPagePayload = $payload;
        $this->ssrResponse = $request->getMethod() === 'GET'
            ? Inertia::getInstance()->ssrGateway->dispatch($payload)
            : null;

        $response->format = Response::FORMAT_RAW;
        $response->content = $view->renderPageTemplate($this->resolveRootView($page->rootView), [
            "page" => $payload,
        ]);

        return $response;
    }

    public function renderInertiaHead(): string
    {
        return $this->ssrResponse?->head ?? '';
    }

    public function renderInertiaApp(): string
    {
        if ($this->ssrResponse !== null) {
            return $this->ssrResponse->body;
        }

        if ($this->rootPagePayload === null) {
            return '';
        }

        return Html::tag('div', '', [
            'id' => 'app',
            'data-page' => Json::encode(
                $this->rootPagePayload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ]);
    }

    public function getInertiaProps(string $component, array $params = []): array
    {
        $sharedProps = $this->getSharedPropsFromTemplates();
        $mergedParams = array_merge($sharedProps, $params);
        return InertiaHelper::resolvePartialProps($mergedParams, $component);
    }

    private function renderTemplateToPage(string $template, string $uri, array $templateVariables): InertiaPage
    {
        [$page, $stringResponse] = $this->captureTemplateOutput(
            $template,
            $this->injectCurrentElement($templateVariables),
        );

        $page->uri = $uri;

        if ($page->bypass) {
            $page->content = $stringResponse;
            return $page;
        }

        $page->props = $this->extractCapturedProps($stringResponse, $page);

        if ($page->component === null) {
            $this->applyLegacyResponseFallback($page, $stringResponse, $uri);
        }

        return $page;
    }

    private function getSharedPropsFromTemplates(): array
    {
        $inertiaConfiguredDirectory = Inertia::getInstance()->settings->inertiaDirectory;
        $sharedDir = $inertiaConfiguredDirectory ? $inertiaConfiguredDirectory . "/_shared" : "_shared";
        $templatesPath = Craft::$app->getPath()->getSiteTemplatesPath();
        $sharedPath = $templatesPath . DIRECTORY_SEPARATOR . $sharedDir;

        if (!is_dir($sharedPath)) {
            return [];
        }

        $allSharedProps = [];

        $files = array_merge(
            glob($sharedPath . DIRECTORY_SEPARATOR . "*.twig") ?: [],
            glob($sharedPath . DIRECTORY_SEPARATOR . "*.html") ?: [],
        );

        foreach ($files as $file) {
            $templatePath = $sharedDir . DIRECTORY_SEPARATOR . basename($file);

            if (Craft::$app->getView()->doesTemplateExist($templatePath)) {
                [$page, $stringResponse] = $this->captureTemplateOutput($templatePath);
                $props = $this->extractCapturedProps($stringResponse, $page);
                $allSharedProps = array_merge($allSharedProps, $props);
            }
        }

        return $allSharedProps;
    }

    /**
     * Asset version finger print
     *
     * @return string
     */
    private function getInertiaVersion(): string
    {
        return Inertia::getInstance()->getInertiaVersion();
    }

    /**
     * Process any template pull tags in the template
     */
    private function processTemplatePulls(string $template): string
    {
        $view = Craft::$app->getView();

        $originalMode = $view->getTemplateMode();
        $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

        try {
            $templatePath = $view->resolveTemplate($template);
            if (!$templatePath) {
                throw new \Exception("Template not found: {$template}");
            }

            $templateContent = file_get_contents($templatePath);
            $pattern = "/\{%\s*pull\s*(?:\(\s*([^\)]+)\s*\)|([^%]+))%\}/";

            $processedContent = preg_replace_callback(
                $pattern,
                function ($matches) use ($view) {
                    $pullPath = isset($matches[1]) && $matches[1] !== "" ? trim($matches[1]) : trim($matches[2]);
                    $directPath = trim($pullPath, "'\"");
                    $referencedPath = $view->resolveTemplate($directPath);

                    if (!$referencedPath) {
                        Craft::warning("Template not found: {$pullPath}", __METHOD__);
                        return "";
                    }

                    return file_get_contents($referencedPath);
                },
                $templateContent,
            );

            return $processedContent;
        } finally {
            $view->setTemplateMode($originalMode);
        }
    }

    private function injectCurrentElement(array $templateVariables): array
    {
        if (Craft::$container->has("currentElement")) {
            $element = Craft::$container->get("currentElement");
            if ($element instanceof Entry) {
                $templateVariables["entry"] = $element;
            } elseif ($element instanceof Category) {
                $templateVariables["category"] = $element;
            }
        }
        return $templateVariables;
    }

    private function captureTemplateOutput(string $template, array $templateVariables = []): array
    {
        $this->captureStack[] = new InertiaPage();

        try {
            $stringResponse = Craft::$app
                ->getView()
                ->renderString($this->processTemplatePulls($template), $templateVariables);
        } finally {
            $page = array_pop($this->captureStack) ?? new InertiaPage();
        }

        return [$page, $stringResponse];
    }

    private function applyLegacyResponseFallback(InertiaPage $page, string $stringResponse, string $uri): void
    {
        $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

        if ($extension && !in_array($extension, ["html", "twig", "php"], true) && empty($page->props)) {
            $page->bypass = true;
            $page->content = $stringResponse;
            return;
        }

        $jsonData = json_decode($stringResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::warning(
                "JSON decoding failed: " . json_last_error_msg() . ". Using default page component and props.",
                __METHOD__,
            );
            $page->component = $uri ?: "Index";
            $page->props = [];
            return;
        }

        $page->component = $jsonData["component"] ?? ($uri ?: "Index");
        $page->props = $jsonData["props"] ?? [];
    }

    private function extractCapturedProps(string $stringResponse, InertiaPage $page): array
    {
        return array_merge(InertiaHelper::extractInertiaPropsFromString($stringResponse), $page->props);
    }

    private function createVersionConflictResponse(\craft\web\Request $request): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->content = "";
        $response->setStatusCode(409);
        $response->headers->set("X-Inertia-Location", $request->getAbsoluteUrl());
        $response->headers->add("Vary", "X-Inertia");

        return $response;
    }

    private function isVersionConflictRequest(\craft\web\Request $request): bool
    {
        if (
            !$request->headers->has("X-Inertia") ||
            $request->getMethod() !== "GET" ||
            !$request->headers->has("X-Inertia-Version")
        ) {
            return false;
        }

        $version = $request->headers->get("X-Inertia-Version", null, true);

        return $version !== $this->getInertiaVersion();
    }

    private function createRawResponse(InertiaPage $page): Response
    {
        $response = Craft::$app->getResponse();
        $response->setStatusCode($page->statusCode);
        $response->format = Response::FORMAT_RAW;
        $response->content = $page->content ?? "";
        $response->headers->add("Vary", "X-Inertia");

        $this->prepareStringResponse($page);

        return $response;
    }

    private function prepareStringResponse(InertiaPage $page): void
    {
        $uri = $page->uri ?? "";
        $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
        if (!$extension) {
            return;
        }

        $response = Craft::$app->getResponse();
        $headers = $response->getHeaders();

        if ($headers->has("Content-Disposition") || $headers->has("Content-Type")) {
            return;
        }

        $mimeType = FileHelper::getMimeTypeByExtension($uri) ?: "application/octet-stream";
        if ($page->download || $this->shouldForceAttachment($extension)) {
            $response->setDownloadHeaders(pathinfo($uri, PATHINFO_BASENAME), $mimeType, false);
            return;
        }

        $headers->setDefault("Content-Type", $mimeType);
    }

    private function shouldForceAttachment(string $extension): bool
    {
        return in_array(
            $extension,
            ["zip", "tar", "gz", "tgz", "bz2", "xz", "7z", "rar", "pdf", "exe", "dmg", "pkg", "msi"],
            true,
        );
    }

    private function resolveRootView(?string $rootView): string
    {
        $view = $rootView ?? Inertia::getInstance()->settings->view;
        $inertiaDirectory = Inertia::getInstance()->settings->inertiaDirectory;

        if ($inertiaDirectory !== null && !str_starts_with($view, $inertiaDirectory . "/")) {
            return $inertiaDirectory . "/" . $view;
        }

        return $view;
    }

    private function getOrCreateCapture(): InertiaPage
    {
        $page = $this->getCurrentCapture();
        if ($page !== null) {
            return $page;
        }

        $page = new InertiaPage();
        $this->captureStack[] = $page;

        return $page;
    }

    private function getCurrentCapture(): ?InertiaPage
    {
        if ($this->captureStack === []) {
            return null;
        }

        return $this->captureStack[array_key_last($this->captureStack)];
    }
}
