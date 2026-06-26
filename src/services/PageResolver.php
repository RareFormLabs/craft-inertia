<?php

namespace rareform\inertia\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use rareform\inertia\helpers\InertiaHelper;
use rareform\inertia\Plugin as Inertia;

class PageResolver extends Component
{
    /**
     * @return array{template: string, uri: string, variables: array}|null
     */
    public function resolveCurrentRequest(): ?array
    {
        $request = Craft::$app->getRequest();
        $urlManager = Craft::$app->getUrlManager();
        $uri = $request->getPathInfo();
        $routeParams = $this->normalizeRouteParams($urlManager->getRouteParams());
        $element = $this->findMatchedElement($uri);

        $explicitTemplate = $routeParams['inertiaTemplate'] ?? null;
        unset($routeParams['inertiaTemplate']);

        if ($explicitTemplate && ($resolvedTemplate = $this->resolveTemplateCandidate($explicitTemplate)) !== null) {
            return [
                'template' => $resolvedTemplate,
                'uri' => $uri,
                'variables' => $this->injectMatchedElementVariables($element, $uri, $routeParams),
            ];
        }

        if (!Inertia::getInstance()->isCatchallRoutingEnabled()) {
            return null;
        }

        if ($element) {
            return $this->resolveElementRequest($element, $uri, $routeParams);
        }

        $template = $this->uriToTemplate($uri);
        if (Craft::$app->getView()->doesTemplateExist($template)) {
            return [
                'template' => $template,
                'uri' => $uri,
                'variables' => $routeParams,
            ];
        }

        return null;
    }

    private function findMatchedElement(string $uri): ?ElementInterface
    {
        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if (!$element && $uri !== '') {
            $element = Craft::$app->getElements()->getElementByUri($uri);
        }

        return $element instanceof ElementInterface ? $element : null;
    }

    private function injectMatchedElementVariables(?ElementInterface $element, string $uri, array $variables): array
    {
        if ($element === null) {
            return $variables;
        }

        if ($element instanceof Entry || $element instanceof Category) {
            $variables = $this->injectElementRouteVariables($element, $uri, $variables);
        }

        $variables[$this->elementVariableName($element)] = $element;

        return $variables;
    }

    /**
     * @return array{template: string, uri: string, variables: array}|null
     */
    public function resolveErrorTemplate(int $statusCode, ?\Throwable $exception = null): ?array
    {
        $request = Craft::$app->getRequest();
        $templateVariables = $this->normalizeRouteParams(Craft::$app->getUrlManager()->getRouteParams());

        if ($exception !== null) {
            $templateVariables['message'] = $exception->getMessage();
        }

        foreach ($this->getErrorTemplateCandidates($request, (string)$statusCode) as $template) {
            $resolvedTemplate = $this->resolveTemplateCandidate($template);
            if ($resolvedTemplate !== null) {
                return [
                    'template' => $resolvedTemplate,
                    'uri' => (string)$statusCode,
                    'variables' => $templateVariables,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{template: string, uri: string, variables: array}|null
     */
    private function resolveElementRequest(ElementInterface $element, string $uri, array $routeParams): ?array
    {
        $sectionOrGroup = $element instanceof Entry ? $element->getSection() : ($element instanceof Category ? $element->getGroup() : null);
        if ($sectionOrGroup === null) {
            return null;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $siteSetting = null;

        foreach ($sectionOrGroup->getSiteSettings() as $setting) {
            if ($setting->siteId === $site->id) {
                $siteSetting = $setting;
                break;
            }
        }

        if ($siteSetting === null || !$siteSetting->template) {
            return null;
        }

        $templateVariables = $this->injectMatchedElementVariables($element, $uri, $routeParams);

        if (!Craft::$app->getView()->doesTemplateExist($siteSetting->template)) {
            return null;
        }

        return [
            'template' => $siteSetting->template,
            'uri' => $uri,
            'variables' => $templateVariables,
        ];
    }

    private function normalizeRouteParams(array $routeParams): array
    {
        if (isset($routeParams['variables']) && is_array($routeParams['variables'])) {
            $routeParams = $routeParams + $routeParams['variables'];
            unset($routeParams['variables']);
        }

        return $routeParams;
    }

    private function injectElementRouteVariables(ElementInterface $element, string $uri, array $variables): array
    {
        $sectionOrGroup = $element instanceof Entry ? $element->getSection() : ($element instanceof Category ? $element->getGroup() : null);
        if ($sectionOrGroup === null) {
            return $variables;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        foreach ($sectionOrGroup->getSiteSettings() as $setting) {
            if ($setting->siteId === $site->id && $setting->uriFormat && str_contains($setting->uriFormat, '{')) {
                return array_merge(
                    $variables,
                    InertiaHelper::extractUriParameters($uri, $setting->uriFormat)
                );
            }
        }

        return $variables;
    }

    private function elementVariableName(ElementInterface $element): string
    {
        return match (true) {
            $element instanceof Entry => 'entry',
            $element instanceof Category => 'category',
            $element instanceof Asset => 'asset',
            default => lcfirst((new \ReflectionClass($element))->getShortName()),
        };
    }

    private function resolveTemplateCandidate(string $template): ?string
    {
        $candidates = [];
        $inertiaDirectory = Inertia::getInstance()->settings->inertiaDirectory;

        if ($inertiaDirectory !== null && !str_starts_with($template, $inertiaDirectory . '/')) {
            $candidates[] = $inertiaDirectory . '/' . $template;
        }

        $candidates[] = $template;

        foreach ($candidates as $candidate) {
            if (Craft::$app->getView()->doesTemplateExist($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function getErrorTemplateCandidates(\craft\web\Request $request, string $statusCode): array
    {
        $candidates = [$statusCode];

        if ($request->getIsSiteRequest()) {
            $prefix = Craft::$app->getConfig()->getGeneral()->errorTemplatePrefix;
            array_unshift($candidates, $prefix . 'error');
            array_unshift($candidates, $prefix . $statusCode);
        }

        return array_values(array_unique($candidates));
    }

    private function uriToTemplate(string $uri): string
    {
        $normalizedUri = trim($uri, '/');
        if ($normalizedUri === '') {
            $normalizedUri = 'index';
        }

        $inertiaDirectory = Inertia::getInstance()->settings->inertiaDirectory;
        if ($inertiaDirectory !== null) {
            return $inertiaDirectory . '/' . $normalizedUri;
        }

        return $normalizedUri;
    }
}
