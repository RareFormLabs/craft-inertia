<?php

namespace rareform\inertia\web;

use craft\web\UrlRule as CraftUrlRule;

/**
 * InertiaUrlRule extends Craft's UrlRule to support an 'inertia' boolean config option.
 * If 'inertia' => true is set, the route is rewritten to the Inertia controller.
 */
class InertiaUrlRule extends CraftUrlRule
{
  public function __construct(array $config = [])
  {
    // If 'inertia' is set and true, rewrite the route to the Inertia controller
    if (isset($config['inertia']) && $config['inertia'] === true) {
      // Remove 'inertia' from config
      unset($config['inertia']);

      $config['defaults'] = array_merge(
        isset($config['defaults']) && is_array($config['defaults']) ? $config['defaults'] : [],
        ['inertiaTemplate' => $config['template'] ?? null]
      );

      unset($config['template']);
      // Set the route to the Inertia controller
      $config['route'] = 'inertia/base/index';
    }

    parent::__construct($config);
  }
}
