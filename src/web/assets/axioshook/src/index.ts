/**
 * The script the plugin adds to every Inertia page. It configures the HTTP
 * client the app exposes as `window.inertiaHttp`, for apps that don't import
 * `@rareform/craft-inertia` and call `configureCraftHttp(http)` themselves.
 */
import {
  configureCraftHttp,
  getConfiguredHttp,
} from "../../../../../packages/client/src/http.ts";

const checkForHttpClient = () => {
  const MAX_ATTEMPTS = 40; // 10 seconds total (40 * 250ms)
  let attempts = 0;

  const intervalCheck = setInterval(() => {
    // The app configured it itself
    if (getConfiguredHttp()) {
      clearInterval(intervalCheck);
      return;
    }

    if (window.inertiaHttp) {
      clearInterval(intervalCheck);
      configureCraftHttp(window.inertiaHttp);
      console.log("Inertia (Craft): HTTP Client configured successfully.");
      return;
    }

    attempts++;
    if (attempts >= MAX_ATTEMPTS) {
      clearInterval(intervalCheck);
      console.warn(
        "Inertia (Craft): HTTP Client not found after 10 seconds. CSRF protection may not be active.",
      );
    }
  }, 250);
};

checkForHttpClient();
