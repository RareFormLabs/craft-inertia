# Copilot Instructions for craft-inertia

## Project Overview

- **Purpose:** Craft-inertia is a Craft CMS plugin that acts as a server-side adapter for Inertia.js, enabling SPA-like experiences using Craft's routing and Twig templating.
- **Architecture:**
  - **Twig templates** define Inertia page responses and shared data, using `{% page('Component') %}` and `{{ prop('key', value) }}` for explicit, cacheable props.
  - **PHP plugin code** (in `src/`) handles routing, protocol, and prop extraction. Prop extraction is DRY: see `extractInertiaPropsFromString()` in `BaseController.php`.
  - **JavaScript/TypeScript** lives in `packages/client/`, the `@rareformlabs/craft-inertia` npm package: `configureCraftHttp()` (CSRF and form handling on Inertia's `http` client) and `postAction()`. `src/web/assets/axioshook/` is the script the plugin adds to each page; it bundles `configureCraftHttp()` from the package for apps that only set `window.inertiaHttp`.

## Key Patterns & Conventions

- **Inertia Responses:**
  - Use `{{ page('Component') }}` and `{{ prop('key', value) }}` for explicit, per-prop caching.
  - Each `prop()` call outputs an HTML comment marker (`<!--INERTIA_PROP:{...}-->`) for robust extraction.
  - Shared props: Place logic in `templates/_shared/` using `prop()`; these are auto-included.
- **DRY Prop Extraction:**
  - All prop extraction from template output (including shared props) uses the same helper: `extractInertiaPropsFromString()` in `BaseController.php`.
- **Prune Filter:** Use the `prune` filter to limit which fields/props are sent to the frontend.
- **Pull Tag:** Use `{% pull('path/to/file') %}` to import variables from other templates.
- **Per-Prop Caching:** Use `{% cache %}` blocks around individual `prop()` calls for fine-grained caching.
- **CSRF Handling:**
  - `configureCraftHttp()` (`packages/client/src/http.ts`) auto-injects CSRF tokens and action params for Craft POSTs.
  - Apps call `configureCraftHttp(http)` from their entry point, or set `window.inertiaHttp = http` for the plugin's script to pick up. Either way the client is configured once (a `Symbol.for` key on `globalThis`).

## Developer Workflows

- **Install:** `composer require rareform/craft-inertia` and `php craft plugin/install inertia`
- **Config:** Place `inertia.php` in `/config` to customize routing, variable capture, etc.
- **Frontend:** Follow Inertia.js client setup, then call `configureCraftHttp(http)` from `@rareformlabs/craft-inertia`.
- **Saving Data:** Use the adapter's JS helper to avoid manual CSRF/action handling. Example:
  ```js
  import { http } from "@inertiajs/vue3";
  import { configureCraftHttp, postAction } from "@rareformlabs/craft-inertia";
  configureCraftHttp(http);
  // ...
  form.post("entries/save-entry");
  // Actions that return JSON rather than a page:
  const result = await postAction("users/save-user", { firstName });
  ```
- **Checks:** `composer check-cs` (`composer fix-cs` to fix), `composer phpstan`, in `packages/client`, `bun run typecheck` and `bun test`; and in `src/web/assets/axioshook`, `bun run typecheck` and `bun run build`. The page script bundles the package's source, so rebuild it after changing `packages/client/src/http.ts` and commit the rebuilt `dist/` and `dist-dev/` files; CI fails if they're out of date.
- **Releases:** Handled by release-please. PR titles must be Conventional Commits (`feat:` → Added, `fix:` → Fixed) because squash merges use the title as the commit message and it becomes the changelog entry. Don't edit `CHANGELOG.md` by hand; merge the release PR to tag and publish.
- **Troubleshooting:**
  - Ensure the app passes the `http` from the same Inertia adapter it uses (one copy of `@inertiajs/core`).
  - HTTP 400 errors usually mean CSRF or action param is missing.

## Integration Points

- **Twig ↔ JS:** Data flows from Twig templates (via `inertia()`/`page()`/`prop()`) to the JS SPA.
- **Craft Routing:** By default, all routes are handled by the plugin unless `takeoverRouting` is disabled in config.
- **External:** Relies on Inertia.js (3+, for its `http` client) and Craft CMS APIs.

## Key Files & Directories

- `src/Plugin.php` — Main plugin entry point
- `src/web/twig/InertiaExtension.php` — Twig functions for Inertia integration (`page`, `prop`, `prune`, etc.)
- `src/controllers/BaseController.php` — Handles routing, DRY prop extraction, and response formatting
- `packages/client/src/http.ts` — `configureCraftHttp()`: CSRF, action, and form data
- `packages/client/src/action.ts` — `postAction()` for actions that return JSON
- `src/web/assets/axioshook/src/index.ts` — page script that configures `window.inertiaHttp`
- `config/inertia.php` — Plugin configuration
- `templates/` — Twig templates for pages and shared data

---

For more, see the README and Inertia.js documentation. When in doubt, follow the patterns in `templates/` and `src/web/twig/InertiaExtension.php`.
