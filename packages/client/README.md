# @rareformlabs/craft-inertia

Client helpers for the [Inertia adapter for Craft CMS](https://github.com/RareFormLabs/craft-inertia). Requires Inertia 3 or later.

```sh
npm install @rareformlabs/craft-inertia
```

## `configureCraftHttp(http)`

Sets up Inertia's HTTP client for Craft: POST and PUT requests are sent as form data with the CSRF token and an `action` param, and the token is kept in sync with the one Craft sends back (so it stays valid after logging in or out). Call it once from your app's entry point:

```js
import { http } from "@inertiajs/vue3"; // or @inertiajs/react, @inertiajs/svelte
import { configureCraftHttp } from "@rareformlabs/craft-inertia";

configureCraftHttp(http);
```

Then `form.post("entries/save-entry")` and `router.post(...)` reach Craft's controller actions with no extra setup.

## `postAction(action, data?, options?)`

Posts to a Craft controller action through the same client and returns its JSON, for requests that don't load a page:

```ts
import { postAction } from "@rareformlabs/craft-inertia";

const result = await postAction<{ secret: string }>("my-module/two-factor/start", { password });

if (result.ok) {
  showQrCode(result.secret);
} else {
  showErrors(result.message, result.errors); // from Craft's asFailure() / asModelFailure()
}
```

Failures and network errors come back as `{ ok: false, message, errors }` rather than throwing. The CSRF token is replaced with the one Craft sends back, so an action that logs someone in or out leaves a valid token for the next request. Pass `{ fallbackMessage }` as the third argument to change the message used when a failure has none of its own (default "Something went wrong. Please try again.").

See the [plugin's README](https://github.com/RareFormLabs/craft-inertia#saving-data) for more.
