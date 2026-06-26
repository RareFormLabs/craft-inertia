<?php

namespace rareform\inertia\models;

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

    /** whether inertia's assets versioning shall be used
     * Set to false if this is already handled in your build process
     */
    public bool $useVersioning = true;

    /** Array of directories that will be checked for changed assets if useVersioning = true
     *  Supports environment variables and aliases.
     */
    public array $assetsDirs = ['@webroot/assets'];

    /**
     * Whether Inertia routing is opt-in or catchall.
     */
    public ?string $routingMode = null;

    /**
     * Deprecated alias for routingMode.
     */
    public ?bool $takeoverRouting = null;

    /**
     * * Currently undocumented
     * The template directory where the Inertia backing logic is stored
     * @var string|null
     */
    public ?string $inertiaDirectory = null;

    public function getResolvedRoutingMode(): string
    {
        if ($this->routingMode !== null) {
            return $this->routingMode;
        }

        if ($this->takeoverRouting !== null) {
            return $this->takeoverRouting ? 'catchall' : 'opt-in';
        }

        return 'opt-in';
    }
    protected function defineRules(): array
    {
        return [
            [['routingMode'], 'in', 'range' => ['opt-in', 'catchall'], 'skipOnEmpty' => true],
        ];
    }
}
