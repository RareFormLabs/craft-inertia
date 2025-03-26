<?php

namespace rareform\inertia\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{

    /** The template that will be rendered on first calls.
     *
     *  Includes the div the inertia app will be rendered to:
     *  <div id="app" data-page="{{ page|json_encode }}"></div>
     *
     * and calls the inertia js app
     * <script src="<path_to_app>/app.js"></script>
     *
     */
    public string $view = 'base.twig';

    /** The key the adapter uses for handling shared props */
    public string $shareKey = '__inertia__';

    /** whether inertia's assets versioning shall be used
     * Set to false if this is already handled in your build process
     */
    public bool $useVersioning = true;

    /** Array of directories that will be checked for changed assets if useVersioning = true
     *  Supports environment variables and aliases.
     */
    public array $assetsDirs = ['@webroot/assets'];

    /**
     * Whether to inject the element (`entry` or `category`) automatically into the frontend response
     * @var bool
     */
    public bool $injectElementAsProp = false;

    /**
     * Whether to take over all routing and forward to Inertia
     * If set to false, you can use Inertia in parallel to normal twig templates
     * Route rules will need to be set in config/routes.php, eg:
     * '' => 'inertia/base/index',
     * '<catchall:.+>' => 'inertia/base/index',
     * @var bool
     */
    public bool $takeoverRouting = true;

    /**
     * * Currently undocumented
     * The template directory where the Inertia backing logic is stored
     * @var string|null
     */
    public string|null $inertiaDirectory = null;

    /**
     * * Currently undocumented and undeveloped
     * The path to a Shared backing template
     * @var string|null
     */
    public string|null $sharedPath = null;

    /**
     * Whether to capture template variables set via {% set %} and make them available as props
     * @var bool
     */
    public bool $autoCaptureVariables = false;
}
