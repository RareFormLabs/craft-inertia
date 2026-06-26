<?php

namespace rareform\inertia\models;

use yii\base\BaseObject;

class InertiaPage extends BaseObject
{
    public ?string $component = null;
    public array $props = [];
    public int $statusCode = 200;
    public ?string $rootView = null;
    public bool $bypass = false;
    public bool $download = false;
    public ?string $content = null;
    public ?string $uri = null;

    public function addProp(string $name, mixed $value): void
    {
        $this->props[$name] = $value;
    }

    public function mergeProps(array $props): void
    {
        $this->props = array_merge($this->props, $props);
    }
}
