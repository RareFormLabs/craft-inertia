<?php

namespace rareform\inertia\models;

use craft\base\Model;

class Settings extends Model
{
    /** The template that will be rendered on initial requests.
     *
     * Includes the inertia_head() and inertia_app() Twig helpers,
     * plus the application's client-side assets.
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
     * Whether initial GET requests should be rendered by an Inertia SSR server.
     */
    public bool $ssrEnabled = false;

    /**
     * Base URL for the Inertia SSR server.
     * Supports environment variables.
     */
    public string $ssrUrl = 'http://127.0.0.1:13714';

    /**
     * Maximum number of seconds to wait for the SSR server.
     */
    public float $ssrTimeout = 2.0;

    /**
     * Throw SSR errors instead of gracefully falling back to client rendering.
     */
    public bool $ssrThrowOnError = false;

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
            [['ssrUrl'], 'string'],
            [['ssrTimeout'], 'number', 'min' => 0.1],
        ];
    }
}
