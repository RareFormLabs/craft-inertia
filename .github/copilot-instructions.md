# Copilot Instructions for craft-inertia

## Project Overview

- **Purpose:** Craft-inertia is a Craft CMS plugin that acts as a server-side adapter for Inertia.js, enabling SPA-like experiences using Craft's routing and Twig templating.
- **Architecture:**
  - **Twig templates** define Inertia page responses and shared data, using `{% page('Component') %}` and `{{ prop('key', value) }}` for explicit, cacheable props.
  - **PHP plugin code** (in `src/`) handles routing, protocol, and prop extraction. Prop extraction is DRY: see `extractInertiaPropsFromString()` in `BaseController.php`.
  - **JavaScript/TypeScript** (notably in `src/web/assets/axioshook/`) manages client-side helpers for CSRF and form handling.

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
  - The JS helper (`src/web/assets/axioshook/src/index.ts`) auto-injects CSRF tokens and action params for Craft POSTs.
  - Attach your Axios instance to `window.axios` for the helper to work.

## Developer Workflows

- **Install:** `composer require rareform/craft-inertia` and `php craft plugin/install inertia`
- **Config:** Place `inertia.php` in `/config` to customize routing, variable capture, etc.
- **Frontend:** Follow Inertia.js client setup. Attach Axios to `window.axios`.
- **Saving Data:** Use the adapter's JS helper to avoid manual CSRF/action handling. Example:
  ```js
  import axios from "axios";
  window.axios = axios;
  // ...
  form.post("entries/save-entry");
  ```
- **Troubleshooting:**
  - Ensure only one Axios instance is used (see README for Vite/alias tips).
  - HTTP 400 errors usually mean CSRF or action param is missing.

## Integration Points

- **Twig ↔ JS:** Data flows from Twig templates (via `inertia()`/`page()`/`prop()`) to the JS SPA.
- **Craft Routing:** By default, all routes are handled by the plugin unless `takeoverRouting` is disabled in config.
- **External:** Relies on Inertia.js, Axios, and Craft CMS APIs.

## Key Files & Directories

- `src/Plugin.php` — Main plugin entry point
- `src/web/twig/InertiaExtension.php` — Twig functions for Inertia integration (`page`, `prop`, `prune`, etc.)
- `src/controllers/BaseController.php` — Handles routing, DRY prop extraction, and response formatting
- `src/web/assets/axioshook/src/index.ts` — JS helper for CSRF, action, and form data
- `config/inertia.php` — Plugin configuration
- `templates/` — Twig templates for pages and shared data

---

For more, see the README and Inertia.js documentation. When in doubt, follow the patterns in `templates/` and `src/web/twig/InertiaExtension.php`.
