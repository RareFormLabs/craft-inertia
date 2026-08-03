# Inertia.js Craft CMS Adapter

![image](https://github.com/user-attachments/assets/97f925a2-74c8-4cc6-ad47-c4cdaafe915d)

This is a server-side adapter for [Inertia](https://inertiajs.com) built with Craft CMS workflow simplicity in mind.

It utilizes Craft's routing, and supports both _Twig_ and PHP-backed controller responses for crafting Inertia pages.

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
> The recommended mode is explicit opt-in routing. Inertia routes can coexist with normal Craft routing and normal Twig rendering without taking over the whole site.

> [!IMPORTANT]
>
> ## Required Reading
>
> The [Inertia documentation](https://inertiajs.com) is a must-read to understand the protocol, the responsibilities of this adapter, and how to use Inertia on the client-side. The following sections will explain how to use this adapter, but assume you have a basic understanding of Inertia.

## Defining Pages

Every page in your javascript application is backed by a Twig template which returns a [page object](https://inertiajs.com/the-protocol#the-page-object). The page object defines which page component to render, and what prop data is received.

```twig
{# templates/posts/index.twig #}

{{ prop('posts', craft.entries.section('posts').limit(20).all()|map(post => {
    title: post.title,
    body: post.body
})) }}

{# Or use the prune filter #}
{{ prop('posts', craft.entries.section('posts').limit(20).all()|prune(['title','body'])) }}

{# Use the page variable to define the Page component to render and props to pass #}
{{ page('Posts/Index') }}
```

Note: templates are passed element variables (`entry` or `category`) automatically when the route is matched to either element type. If your page component needs that data, pass it explicitly with `prop()`.

## PHP Controllers

You can also return Inertia pages directly from PHP controllers:

```php
use rareform\inertia\Plugin as Inertia;

class EventsController extends \craft\web\Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public function actionShow(): \craft\web\Response
    {
        return Inertia::getInstance()->render('Events/Show', [
            'event' => [
                'title' => 'Launch Party',
            ],
        ]);
    }
}
```

## Shared Data

Shared data will automatically be passed as props to your application, sparing you the cumbersome tasks of redefining the same prop data in every page response. You can optionally separate any shared data logic into multiple files for organization.

Create a `_shared` directory at the root of your `/templates` directory:

```twig
{# templates/_shared/index.twig #}

{{ prop('flashes', craft.app.session.getAllFlashes(true)) }}

{# Not typically necessary #}
{{ prop('csrfTokenName', craft.app.config.general.csrfTokenName) }}
{{ prop('csrfTokenValue', craft.app.request.csrfToken) }}
```

You may want to separate shared data logic or cache to reduce unnecessary repetitive queries:

```twig
{# templates/_shared/current-user.twig #}

{% if currentUser %}
  {% cache using key currentUser.email %}
    {% set user = {
      id: currentUser.id,
      fullName: currentUser.fullName,
      email: currentUser.email,
    } %}
    {{ prop('currentUser', user) }}
  {% endcache %}
{% else %}
  {{ prop('currentUser', null) }}
{% endif %}
```

### Prune Filter

You're inevitably going to have some large objects that you don't want to pass to your frontend. You can use the `prune` filter or function to remove properties from objects that are passed to your Inertia component.

```twig
{# Basic usage: simply pass an array of fields #}
{{ prop('post', craft.entries.section('blog').all()|prune(["title", "author", "body", "url", "featuredImage"])) }}

{# Advanced object syntax #}
{{ prop('post', craft.entries.section('blog').all()|prune(
  {
    title: true,
    id: true,
    uri: true,
    <!-- Related fields simple array syntax -->
    author: ["username", "email"],
    <!-- Related fields object syntax -->
    mainImage: {
      url: true,
      uploader: {
        <!-- Nested related fields -->
        email: true,
        username: true,
      },
    },
    <!-- Matrix fields -->
    contentBlocks: {
      <!-- Denote query traits with $ prefix -->
      <!-- https://www.yiiframework.com/doc/api/2.0/yii-db-querytrait -->
      "$limit": 10,
      <!-- Designate distinct prune fields per type with _ prefix -->
      _body: {
        body: true,
        intro: true,
      },
      _fullWidthImage: {
        image: ["url", "alt"],
      },
    },
  }
)) }}
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

{{ page('Teams/Entry') }}
{{ prop('teamColor', teamColor) }}
```

This is a simple DX alternative to using `extends` and `block` tags to share variables across templates. Note that the `pull` tag is only available in Inertia responses.

## Saving Data

Craft CMS does not use traditional POST, PUT, PATCH, and DELETE requests for saving data, and instead uses the `action` parameter to when POSTing to various internal Craft controllers. This means saving data to Craft CMS data is a little different than what is expected in a traditional Inertia application.

Here's an example of how you could save an entry using Inertia's `useForm` helper **without** using the adapter's javascript helper:

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

Attaching the CSRF token, the action param, and the `forceFormData` option is required for Craft to process the request correctly, **but it's recommended to use the adapter's helper to remove this repetitive boilerplate.**

### Using the Adapter's Helper

The adapter will automatically attach the CSRF token, action param, and set the `forceFormData` option to `true` for you, but needs access to the same Axios instance used by Inertia's native library.

For the majority of projects, all that's needed on your end is to attach Axios to the window object. The adapter will take care of the rest. (Having issues? Visit troubleshooting section below)

```js
import axios from "axios";
window.axios = axios;
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

This looks much better. You can optionally reduce one extra step the helper takes by rendering the CSRF token info in your base template's head:

```twig
<meta csrf name="{{ craft.app.config.general.csrfTokenName }}" content="{{ craft.app.request.csrfToken }}">
```

This extra step reduces additional fetch requests to Craft's sessions endpoint to get the CSRF token manually for unauthenticated users.

## Configuration

Create an `inertia.php` file in your Craft `/config` directory. Shown are the default values:

```php
<?php

return [
    /**
     * The root template that will be rendered when first loading your Inertia app
     * (https://inertiajs.com/the-protocol#html-responses).
     * Includes the inertia_head() and inertia_app() Twig helpers,
     * plus your application's client-side assets.
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
     * Routing mode:
     * - 'opt-in' keeps normal Craft routing as the default
     * - 'catchall' makes Inertia handle all site routes
     */
    'routingMode' => 'opt-in',

    /**
     * Deprecated alias for routingMode:
     * true => 'catchall'
     * false => 'opt-in'
     */
    'takeoverRouting' => null,

    /**
     * Enable server-side rendering for initial GET requests.
     */
    'ssrEnabled' => false,

    /**
     * Base URL of a trusted Inertia SSR server. The adapter posts page
     * objects to the /render endpoint.
     */
    'ssrUrl' => 'http://127.0.0.1:13714',

    /**
     * Request timeout, in seconds, before falling back to client rendering.
     */
    'ssrTimeout' => 2.0,

    /**
     * Throw SSR errors instead of falling back. This is useful in automated
     * tests, but is not recommended in production.
     */
    'ssrThrowOnError' => false,

];
```

## Server-Side Rendering

SSR is opt-in and follows Inertia's HTTP SSR protocol. The adapter sends the normal page object to a separately running Inertia SSR server and places the returned head and body markup into your root Twig template. Partial Inertia visits continue to return JSON and are never sent to the SSR server.

First, replace the hand-written app element in your root template with the Twig helpers:

~~~twig
<!doctype html>
<html>
  <head>
    {# Your normal head markup and client assets #}
    {{ inertia_head() }}
  </head>
  <body>
    {{ inertia_app() }}
  </body>
</html>
~~~

The inertia_app() helper also renders the existing client-side app element and escaped page payload whenever SSR is disabled or unavailable, so the same template supports both SSR and client rendering.

Then:

1. Configure your client entry point to hydrate server-rendered markup.
2. Create and build an SSR entry point using the server package for your client framework.
3. Run the built SSR server as a supervised background process.
4. Set ssrEnabled to true and point ssrUrl at that server.

See Inertia's [server-side rendering guide](https://inertiajs.com/docs/v3/advanced/server-side-rendering) for current Vue, React, and Svelte entry-point and hydration examples. Inertia v3's SSR server requires Node.js 22 or later and listens on port 13714 by default.

If the SSR server cannot be reached, returns an error, or returns an invalid response, the adapter logs a warning and gracefully falls back to client rendering. Set ssrThrowOnError to true in automated tests if an SSR failure should fail the request instead.

> [!IMPORTANT]
> The SSR server receives the complete Inertia page object, including any authenticated-user or otherwise sensitive props. Keep the endpoint on loopback or a trusted private network; do not expose it publicly.

## Troubleshooting

### Error HTTP 400 – Bad Request : Unable to verify your data submission

This error is usually caused by the CSRF token not being passed correctly with the form data.

If you manually stored the CSRF token in a meta tag in your base template, make sure you are using the correct name for the token. The default name is `CRAFT_CSRF_TOKEN`, but it can be changed in your Craft config.

[If you attached Axios to the window object](#using-the-adapters-Helper), make sure you are using the same instance of Axios that Inertia is using. If you have multiple versions of Axios installed (independently or through other packages) and are using Vite, you can resolve this by adding an alias to your Vite config:

```js
import { defineConfig } from "vite";
import path from "path";

export default defineConfig({
  // ...other config
  resolve: {
    alias: {
      axios: path.resolve(
        __dirname,
        "node_modules/@inertiajs/core/node_modules/axios"
      ),
    },
  },
});
```

Another way to resolve this is to use the `resolutions` field in your `package.json` file to force all packages to use the same version of Axios.

```json
{
  "resolutions": {
    "axios": "^1.x.x" // Specify the exact version Inertia uses
  }
}
```
