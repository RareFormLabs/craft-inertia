<?php

namespace rareform\inertia\helpers;

use Craft;
use JsonException;
use Illuminate\Support\Arr;

class InertiaHelper
{
    /**
     * Extracts Inertia props from a string using the HTML comment marker format.
     * Optionally merges into an existing array and logs duplicate keys with a custom message.
     *
     * @param string $stringResponse
     * @param array $existingProps
     * @return array
     */
    public static function extractInertiaPropsFromString(string $stringResponse, array $existingProps = []): array
    {
        $jsonProps = [];
        if (preg_match_all('/<!--INERTIA_PROP:(\{.*?\})-->/s', $stringResponse, $matches)) {
            $jsonProps = $matches[1];
        }
        foreach ($jsonProps as $json) {
            try {
                $propArr = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $ex) {
                // Log the error with a snippet of the offending JSON
                $snippet = mb_substr($json, 0, 120) . (mb_strlen($json) > 120 ? '...' : '');
                Craft::error(
                    sprintf(
                        "Failed to decode Inertia prop JSON: %s. Offending JSON: %s",
                        $ex->getMessage(),
                        $snippet
                    ),
                    __METHOD__
                );
                continue; // Skip this prop block
            }
            if (is_array($propArr)) {
                foreach ($propArr as $key => $val) {
                    if (array_key_exists($key, $existingProps)) {
                        Craft::warning("Duplicate Inertia prop '$key' detected in template output. Skipping duplicate to avoid overwriting.", __METHOD__);
                        continue;
                    }
                    $existingProps[$key] = $val;
                }
            }
        }
        return $existingProps;
    }

    /**
     * Resolve the `only` and `except` partial request props.
     */
    public static function resolvePartialProps(array $props, string $view): array
    {
        $isPartial = Craft::$app->request->headers->get('X-Inertia-Partial-Component') === $view;

        if (!$isPartial) {
            return $props;
            // Craft CMS Inertia Adapter doesn't support LazyProps for now
            //     return array_filter($props, static function ($prop) {
            //         return !($prop instanceof LazyProp);
            //     });
        }

        $only = array_filter(explode(',', Craft::$app->request->headers->get('X-Inertia-Partial-Data')));
        $except = array_filter(explode(',', Craft::$app->request->headers->get('X-Inertia-Partial-Except')));

        $props = $only ? Arr::only($props, $only) : $props;

        if ($except) {
            Arr::forget($props, $except);
        }

        return $props;
    }

    public static function extractUriParameters($uri, $uriFormat)
    {
        // Convert the format into a regex pattern
        $pattern = preg_replace('/\{([^}]+)\}/', '([^\/]+)', $uriFormat);
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '#^' . $pattern . '$#'; // Using # as delimiter instead of '/'

        // Extract parameter names from the format
        preg_match_all('/\{([^}]+)\}/', $uriFormat, $paramNames);
        $paramNames = $paramNames[1];

        // Extract values from the URI
        preg_match($pattern, $uri, $matches);

        // Remove the full match
        array_shift($matches);

        // Combine parameter names with their values
        $parameters = array_combine($paramNames, $matches);

        if ($parameters === false) {
            throw new \Exception('Failed to combine parameter names and values.');
        }

        return $parameters;
    }
}