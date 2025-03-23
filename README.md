# Inertia.js Craft CMS Adapter

![image](https://github.com/user-attachments/assets/97f925a2-74c8-4cc6-ad47-c4cdaafe915d)

This is a server-side adapter for [Inertia](https://inertiajs.com) built with Craft CMS workflow simplicity in mind.

It utilizes Craft's routing, as well as _Twig_ for crafting Inertia responses, rather than requiring they be written directly in PHP (as a traditional Inertia application does).

[Ping CRM Demo](https://pingcrm.rareformlabs.com) — [Ping CRM Repo](https://github.com/rareformlabs/pingcrm)

## Requirements

This plugin requires Craft CMS 5.4.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Inertia”. Then press “Install”.

#### With Composer:

Open your terminal and run the following commands:

```sh
composer require rareform/craft-inertia
php craft plugin/install inertia
```

Be sure to follow the installation instructions for the [client-side framework](https://inertiajs.com/client-side-setup) you use.

> [!NOTE]
> Upon installing, the Inertia adapter will takeover all routing and expect all templates to respond with inertia protocol responses. To prevent this, you may set the `takeoverRouting` [config option](#configuration) to `false`

> [!IMPORTANT]
>
> ## Required Reading
>
> The [Inertia documentation](https://inertiajs.com) is a must-read to understand the protocol, the responsibilities of this adapter, and how to use Inertia on the client-side. The following sections will explain how to use this adapter, but assume you have a basic understanding of Inertia.

## Defining Pages

Every page in your javascript application is backed by a Twig template which returns a [page object](https://inertiajs.com/the-protocol#the-page-object). The page object defines which page component to render, and what prop data is received.

```twig
{# templates/posts/index.twig #}

{% set posts = craft.entries.section('posts').limit(20).all() | map(post => {
    title: post.title,
    body: post.body
}) %}

{# Use the inertia variable to define the Page component to render and props to pass #}
{{ inertia('Posts/Index', { posts: posts }) }}
```

Note: templates are passed element variables (`entry` or `category`) automatically when the route is matched to either element type. If you want to pass the element as a prop automatically to your page component, set the `injectElementAsProp` configuration to `true`.

## Shared Data

Shared data will automatically be passed as props to your application, sparing you the cumbersome tasks of redefining the same prop data in every page response. You can create multiple files for different shared prop responses.

Create a `_shared` directory at the root of your `/templates` directory, and use the `inertiaShare` variable:

```twig
{# templates/_shared/index.twig #}

{{ inertiaShare({
   flashes: craft.app.session.getAllFlashes(true),
   csrfTokenValue: craft.app.request.csrfToken,
   csrfTokenName: craft.app.config.general.csrfTokenName
}) }}
```

This allows more flexibility for designating responses you may want to cache to reduce unnecessary repetitive queries.

```twig
{# templates/_shared/current-user.twig #}

{% if currentUser %}
  {% cache using key currentUser.email %}
    {% set user = {
      id: currentUser.id,
      fullName: currentUser.fullName,
      email: currentUser.email,
    } %}
    {{ inertiaShare({ currentUser: user }) }}
  {% endcache %}
{% else %}
  {{ inertiaShare({ currentUser: null }) }}
{% endif %}
```

## Automatic Variable Capturing

You can enable automatic capturing of variables set with `{% set %}` in your twig files and have them passed as props to your Inertia components. This provides a cleaner, more intuitive way to pass data to your frontend without explicitly defining props.

Enable this feature in your config:

```php
// config/inertia.php
return [
    // ...other settings
    'autoCaptureVariables' => true,
];
```

## Pull in Variables

Use the `pull` tag to include variables from a specified template and make them available in the current response twig file.

```twig
{# teams/_base.twig #}
{% set teamColor = "#EE4B2B" %}
```

```twig
{# templates/teams/_entry.twig #}
{% pull('teams/_base') %}

{{ inertia('Teams/Entry', { teamColor: teamColor }) }}
```

This is a simple DX alternative to using `extends` and `block` tags to share variables across templates. Note that the `pull` tag is only available in Inertia responses.

## Saving Data

Craft CMS does not use traditional POST, PUT, PATCH, and DELETE requests for saving data, and instead uses the `action` parameter to hit various internal Craft controllers. This means saving data to Craft CMS data is a little different than what is expected in a traditional Inertia application.

```js
const form = useForm({
  sectionId: 1,
  typeId: 2,
  CRAFT_CSRF_TOKEN: csrf.value,
  action: "entries/save-entry",
  title: "My New Post",
  fields: {
    customField: "My Custom Field Value",
  },
});

const saveEntry = () => {
  // Don't specify a POST url, as we're using the action parameter
  form.post("", {
    // Force the request to use form data in order for Craft to process the request
    forceFormData: true,
  });
};
```

> [!TIP]
> If using Vue 3 on your frontend, you can install the `inertia-helper` npm plugin which simplifies the process by automatically handling the CSRF token injection and removing the `forceFormData` option requirement:

```js
import useForm from "inertia-helper";
```

```js
const form = useForm({
  title: "My New Post",
  sectionId: 1,
  typeId: 2,
  fields: {
    customField: "My Custom Field Value",
  },
});

const saveEntry = () => form.post("entries/save-entry");
```

## Configuration

Create an `inertia.php` file in your Craft `/config` directory. Shown are the default values:

```php
<?php

return [
    /**
     * The root template that will be rendered when first loading your Inertia app
     * (https://inertiajs.com/the-protocol#html-responses).
     * Includes the div the inertia app will be rendered to:
     * `<div id="app" data-page="{{ page|json_encode }}"></div>`
     * and calls the Inertia app `<script src="<path_to_app>/app.js"></script>`
     */
    'view' => 'base.twig',

    /**
     * Whether inertia's assets versioning shall be used
     * (https://inertiajs.com/the-protocol#asset-versioning)
     */
    'useVersioning' => true,

    /**
     * Array of directories that will be checked for changed assets if `useVersioning` => true
     */
    'assetsDirs' => [
        '@webroot/dist/assets'
    ],

    /**
     * Whether to inject the route matched element (`entry` or `category`) automatically into the application
     */
    'injectElementAsProp' => false,

    /**
     * Whether to takeover all routing and forward to Inertia
     * If set to false, you can use Inertia in parallel to normal twig templates
     * Route rules will need to be set in config/routes.php, eg:
     * '' => 'inertia/base/index',
     * '<catchall:.+>' => 'inertia/base/index',
     */
    'takeoverRouting' => true,

    /**
     * Whether to enable automatic capturing of variables set with `{% set %}` in your twig files
     * and have them passed as props to your Inertia components.
     */
    'autoCaptureVariables' => false,
];
```
