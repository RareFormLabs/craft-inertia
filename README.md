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

The adapter will automatically attach the CSRF token and action param, and send the request as form data, by hooking into Inertia's HTTP client (`http`, Inertia 3+).

Install the client package and set it up in your app's entry point:

```sh
npm install @rareform/craft-inertia
```

```js
import { http } from "@inertiajs/vue3"; // or @inertiajs/react, @inertiajs/svelte
import { configureCraftHttp } from "@rareform/craft-inertia";

configureCraftHttp(http);
```

Without the package, you can instead expose the client on the window, and the script the plugin adds to each page will set it up:

```js
import { http } from "@inertiajs/vue3";
window.inertiaHttp = http;
```

Then post to Craft actions as you would any Inertia endpoint:

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

Either way, the helper updates the token from the `X-Craft-Csrf-Token` header Craft sends with every Inertia response, so it stays valid after logging in or out without a full page load.

### Actions That Return JSON

Some requests don't load a page, such as checking a code or saving a setting from a modal. `postAction()` posts to a Craft controller action through the same client, so the CSRF token is handled for you, and returns the action's JSON (`asSuccess()` / `asJson()`):

```js
import { postAction } from "@rareform/craft-inertia";

const result = await postAction("users/save-user", { firstName: "Ada" });

if (result.ok) {
  console.log(result.message);
} else {
  // Craft's asFailure() / asModelFailure(): a message, and field errors when it has them
  console.log(result.message, result.errors);
}
```

Failures and network errors come back as `{ ok: false, message, errors }` rather than throwing. In TypeScript, pass the shape of the success response: `postAction<{ secret: string }>(...)`.

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
     * Embed the initial page object in a `<script type="application/json">`
     * element (instead of a `data-page` attribute) when `inertia_app()`
     * renders on the client. Required for Inertia v3.
     */
    'useScriptElementForInitialPage' => false,

    /**
     * Enable server-side rendering for initial GET requests.
     */
    'ssrEnabled' => false,

    /**
     * Base URL of a trusted Inertia SSR server. The adapter posts page
     * objects to its `/render` endpoint. Supports environment variables.
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

SSR is opt-in and follows Inertia's HTTP SSR protocol. On a full page load, the adapter sends the page object to a separately running Inertia SSR server and places the returned head and body markup into your root Twig template. Inertia visits (`X-Inertia` requests) still return JSON, and non-GET requests are never sent to the SSR server.

First, render the app element with the Twig helpers in your root template instead of writing it by hand:

```twig
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
```

When SSR is disabled or unavailable, `inertia_app()` renders the client-side mount element with the page object instead, so the same template works with or without SSR. Pass an ID if your app mounts somewhere other than `#app`: `{{ inertia_app('root') }}`. The SSR server is only called when the template uses these helpers.

How the page object is embedded depends on your Inertia version:

- **Inertia v3** reads it from a `<script type="application/json">` element. Set `useScriptElementForInitialPage` to `true`.
- **Inertia v2** reads a `data-page` attribute by default (the adapter's default). If you've enabled `future.useScriptElementForInitialPage` in `createInertiaApp`, set `useScriptElementForInitialPage` to `true` too.

Then:

1. Configure your client entry point to hydrate server-rendered markup.
2. Create and build an SSR entry point using the server package for your client framework.
3. Run the built SSR server as a supervised background process.
4. Set `ssrEnabled` to `true` and point `ssrUrl` at that server.

See Inertia's server-side rendering guide ([v3](https://inertiajs.com/docs/v3/advanced/server-side-rendering), [v2](https://inertiajs.com/docs/v2/advanced/server-side-rendering)) for Vue, React, and Svelte entry-point and hydration examples. The SSR server listens on port 13714 by default.

If the SSR server cannot be reached, returns an error, or returns an invalid response, the adapter logs a warning and falls back to client rendering. Set `ssrThrowOnError` to `true` in automated tests if an SSR failure should fail the request instead.

> [!IMPORTANT]
> The SSR server receives the complete Inertia page object, including any authenticated-user or otherwise sensitive props. Keep the endpoint on loopback or a trusted private network; do not expose it publicly.

## Troubleshooting

### Error HTTP 400 – Bad Request : Unable to verify your data submission

This error is usually caused by the CSRF token not being passed correctly with the form data.

If you manually stored the CSRF token in a meta tag in your base template, make sure you are using the correct name for the token. The default name is `CRAFT_CSRF_TOKEN`, but it can be changed in your Craft config.

Make sure the client is set up: either call `configureCraftHttp(http)` in your entry point, or set `window.inertiaHttp = http` (the browser console warns after 10 seconds if the plugin's script never finds it). Pass the `http` exported by the same Inertia adapter your app uses, so there's only one copy of `@inertiajs/core` in your bundle.
