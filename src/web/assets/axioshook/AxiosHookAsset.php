<?php

namespace rareform\inertia\web\assets\axioshook;

use Craft;
use craft\web\AssetBundle;

/**
 * Axios Hook asset bundle
 */
class AxiosHookAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [];
    public $js = ['index.js'];
    public $css = [];

    /**
     * Initializes the bundle.
     */
    public function init()
    {
        // define the path that your publishable resources live
        $this->sourcePath =  __DIR__ . '/dist';

        // define the dependencies
        $this->depends = [];

        $this->js = ['index.js'];

        $this->css = [];

        parent::init();
    }
}
