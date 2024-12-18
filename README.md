# Inertia.js Craft CMS Adapter

![image](https://github.com/user-attachments/assets/97f925a2-74c8-4cc6-ad47-c4cdaafe915d)

This is a server-side adapter for [Inertia](https://inertiajs.com) built with Craft CMS workflow simplicity in mind.

It utilizes Craft's routing, as well as _Twig_ for crafting Inertia responses, rather than requiring they be written directly in PHP (as a traditional Inertia application does).

[Demo project](https://github.com/rareformlabs/pingcrm)

## Installation

Install via Composer:

```sh
composer require rareform/craft-inertia
php craft plugin/install inertia
```

Be sure to follow the installation instructions for the [client-side framework](https://inertiajs.com/client-side-setup) you use.

## Required Reading

The [Inertia documentation](https://inertiajs.com) is a must-read to understand the protocol, the responsibilities of this adapter, and how to use Inertia on the client-side. The following sections will explain how to use this adapter, but assume you have a basic understanding of Inertia.

## Defining Pages

Every page in your javascript application is backed by a Twig template which returns a [page object](https://inertiajs.com/the-protocol#the-page-object). The page object defines which page component to render, and what prop data is received.

```twig
{# templates/posts/index.twig #}

{% set posts = craft.entries.section('posts').limit(20).all() | map(post => {
    title: post.title,
    body: post.body
}) %}

{# Use the Inertia variable to return the Page component to render, and the props to pass down #}
{{ inertia('Posts/Index', { posts: posts }) }}
```

## Shared Data

Shared data will automatically be passed as props to your application, sparing you the cumbersome tasks of redefining the same prop data in every page response.

Create a `shared.twig` at the root of your `/templates` directory, and use the `inertiaShare` variable:

```twig
{# templates/shared.twig #}

{% set sharedProps = {
   flashes: craft.app.session.getAllFlashes(true),
   csrfTokenValue: craft.app.request.csrfToken,
   csrfTokenName: craft.app.config.general.csrfTokenName
} %}

{% if currentUser %}
  {% set user = {
    id: currentUser.id,
    fullName: currentUser.fullName,
    email: currentUser.email,
  } %}
  {% set sharedProps = sharedProps|merge({currentUser: user}) %}
{% endif %}

{{ inertiaShare(shareProps) }}
```

## Configuration

Create an `inertia.php` file in your Craft `/config` directory. Shown are the default values:

```php
<?php

return [
    // The root template that will be rendered when first loading your Inertia app
    // (https://inertiajs.com/the-protocol#html-responses).
    // Includes the div the inertia app will be rendered to:
    // `<div id="app" data-page="{{ page|json_encode }}"></div>`
    // and calls the Inertia app `<script src="<path_to_app>/app.js"></script>`
    'view' => 'base.twig',

    // Whether inertia's assets versioning shall be used
    // (https://inertiajs.com/the-protocol#asset-versioning)
    'useVersioning' => true,

    // Array of directories that will be checked for changed assets if `useVersioning` => true
    'assetsDirs' => [
        '@webroot/dist/assets'
    ],

    // Whether to inject the element matched from routing automatically into the application
    'injectElementAsProp' => false,

    // Whether all routes will be Inertia responses
    'takeoverRouting' => true,
];
```

## Requirements

This plugin requires Craft CMS 5.4.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Inertia”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require rareform/craft-inertia

# tell Craft to install the plugin
./craft plugin/install inertia
```
