<?php

namespace rareform\inertia\web\twig;

use Craft;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use craft\helpers\Json;
use rareform\Prune;

/**
 * Twig extension
 */
class InertiaExtension extends AbstractExtension
{

    public function getFilters()
    {
        return [
            new TwigFilter('recursive_merge', [$this, 'recursiveMergeFilter']),
            new TwigFilter('set', [$this, 'setFilter']),
            new TwigFilter('add', [$this, 'addFilter']),
            new TwigFilter('prune', [$this, 'pruneDataFilter']),
        ];
    }

    /**
     * Outputs a JSON-like marker for controller parsing and stores the prop for controller collection.
     */
    public function prop($name, $value = null)
    {
        // Output a marker as an HTML comment for controller parsing
        $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($jsonValue === false) {
            // Handle encoding error: throw or fallback
            $error = json_last_error_msg();
            throw new \RuntimeException("Failed to encode Inertia prop '$name' as JSON: $error");
        }
        return "<!--INERTIA_PROP:{\"$name\":$jsonValue}-->";
    }

    public function getFunctions()
    {
        return [
            // Legacy inertia() function
            new TwigFunction('inertia', function ($page, $props = []) {
                // Store in global context for controller to pick up
                Craft::$app->params['inertiaPage'] = $page;
                Craft::$app->params['inertiaProps'] = $props;
                return Json::encode([
                    'component' => $page,
                    'props' => $props,
                ]);
            }, ['is_safe' => ['html']]),

            new TwigFunction('page', function ($page) {
                Craft::$app->params['__inertia_page'] = $page;
                return '';
            }),

            new TwigFunction('prop', [$this, 'prop'], ['is_safe' => ['html']]),

            new TwigFunction('prune', [$this, 'pruneDataFilter']),
        ];
    }

    public function getTests()
    {
        return [];
    }

    /**
     * Prunes data according to the provided definition.
     * Preserves original data structure (array or single object).
     *
     * Examples:
     * - Simple field list: {{ entry|prune(['title', 'url']) }}
     * - Object syntax: {{ entry|prune({title: true, body: true}) }}
     * - Related fields: {{ entry|prune({author: ['name', 'email']}) }}
     * - Matrix fields: {{ entry|prune({contentBlocks: {$limit: 10, _text: {body: true}}}) }}
     *
     * @param mixed $data Data to be pruned 
     * @param mixed $pruneDefinition Definition that determines what data to keep
     * @return mixed Pruned data in original format (array or single object)
     */
    public function pruneDataFilter($data, $pruneDefinition)
    {
        $helper = new Prune();
        $result = $helper->pruneData($data, $pruneDefinition);
        
        // The PruneHelper always returns an array
        // If original data was not an array, return the first element
        if (!is_array($data)) {
            return $result[0] ?? null;
        }
        
        return $result;
    }

    /**
     * Recursively merges an array into the element, replacing existing values.
     *
     * {{ form|recursive_merge( {'element': {'attributes': {'placeholder': 'Label'}}} ) }}
     */
    public static function recursiveMergeFilter($element, $array): array
    {
        if (!is_iterable($element)) {
            throw new RuntimeError(sprintf('The recursive_merge filter only works on arrays or "Traversable" objects, got "%s".', gettype($element)));
        }

        return array_replace_recursive($element, $array);
    }

    /**
     * Sets a deeply-nested property on an array.
     *
     * If the deeply-nested property exists, the existing data will be replaced
     * with the new value.
     * 
     * {{ form|set( 'element.#attributes.placeholder', 'Label' ) }}
     *
     * Or using named arguments:
     * 
     * {{ form|set( at='element.#attributes.placeholder', value='Label' ) }}
     */
    public static function setFilter($element, string $at, $value)
    {
        if (!is_iterable($element)) {
            throw new RuntimeError(sprintf('The "set" filter only works on arrays or "Traversable" objects, got "%s".', gettype($element)));
        }

        return self::addOrSetFilter($element, $at, $value);
    }

    /**
     * Adds a deeply-nested property on an array.
     *
     * If the deeply-nested property exists, the existing data will be replaced
     * with the new value, unless the existing data is an array. In which case,
     * the new value will be merged into the existing array.
     *
     * {{ form|add( 'element.#attributes.class', 'new-class' ) }}
     *
     * Or using named arguments:
     * {{ form|add( at='element.#attributes.class', value='new-class' ) }}
     * 
     * {# We accept the plural form of "values" as a grammatical convenience. #}
     * {{ form|add( at='element.#attributes.class', values=['new-class', 'new-class-2'] ) }}
     */
    public static function addFilter($element, string $at, $value = NULL, $values = NULL)
    {
        if (!is_iterable($element)) {
            throw new RuntimeError(sprintf('The "add" filter only works on arrays or "Traversable" objects, got "%s".', gettype($element)));
        }

        return self::addOrSetFilter($element, $at, !is_null($values) ? $values : $value, TRUE);
    }

    /**
     * Helper function for the set/add filters.
     *
     * @param array|iterable|\Traversable $element
     *   The parent renderable array to merge into.
     * @param string $at
     *   The dotted-path to the deeply nested element to replace.
     * @param mixed $value
     *   The value to set.
     * @param bool $isAddFilter
     *   Which filter is being called.
     *
     * @return array
     *   The merged renderable array.
     */
    protected static function addOrSetFilter($element, string $at, $value, $isAddFilter = FALSE)
    {
        if ($element instanceof \ArrayAccess) {
            $filteredElement = self::toArray($element);
        } else {
            $filteredElement = $element;
        }

        // Convert the dotted path into an array of keys.
        $path = explode('.', $at);
        $lastPath = array_pop($path);

        // Traverse the element down the path, creating arrays as needed.
        $childElement =& $filteredElement;
        foreach ($path as $childPath) {
            if (!isset($childElement[$childPath])) {
                $childElement[$childPath] = [];
            }
            $childElement =& $childElement[$childPath];
        }

        // If this is the add() filter and if the targeted child element is an
        // array, add the value to it.
        if ($isAddFilter && isset($childElement[$lastPath]) && is_array($childElement[$lastPath])) {
            if (is_array($value)) {
                $childElement[$lastPath] = array_merge($childElement[$lastPath], $value);
            } else {
                $childElement[$lastPath][] = $value;
            }
        } else {
            // Otherwise, replace the target element with the given value.
            $childElement[$lastPath] = $value;
        }

        return $filteredElement;
    }

    private static function toArray($object)
    {
        if (is_array($object)) {
            return $object;
        }
        if (method_exists($object, 'toArray')) {
            return $object->toArray();
        }
        if ($object instanceof \JsonSerializable) {
            return (array) $object;
        }
        return get_object_vars($object);
    }
}
