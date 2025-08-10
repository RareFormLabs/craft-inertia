<?php

namespace rareform\inertia\web\assets\axioshook;

use Craft;
use craft\web\AssetBundle;

/**
 * Axios Hook asset bundle
 */
class AxiosHookAsset extends AssetBundle
{
    public $sourcePath;
    public $depends = [];
    public $js = [];
    public $css = [];

    /**
     * Initializes the bundle.
     */
    public function init()
    {
        // Use Craft's devMode config to determine environment
        $isDev = Craft::$app->config->general->devMode;
        if ($isDev) {
            $this->sourcePath = __DIR__ . '/dist-dev';
            $this->js = ['index-dev.js'];
        } else {
            $this->sourcePath = __DIR__ . '/dist';
            $this->js = ['index.js'];
        }
        $this->depends = [];
        $this->css = [];
        parent::init();
    }
}
