import { configureCraftHttp, getConfiguredHttp, type InertiaHttp } from "./http.js";

export type ActionResult<T extends object = object> =
  | ({ ok: true; message?: string } & T)
  | { ok: false; message: string; errors: Record<string, string[]> };

export type PostActionOptions = {
  /** Shown when a failure has no `message` of its own */
  fallbackMessage?: string;
  /** The client to send it with, if not the one `configureCraftHttp()` set up */
  http?: InertiaHttp;
};

const FALLBACK_MESSAGE = "Something went wrong. Please try again.";

/**
 * Posts to a Craft controller action and returns its JSON, for requests that
 * don't load an Inertia page (checking a code, saving a setting from a modal).
 *
 *   const result = await postAction("users/save-user", { firstName });
 *   if (!result.ok) showErrors(result.errors);
 *
 * It goes through Inertia's HTTP client, so the CSRF token is added the same
 * way as for page visits. Failures (Craft's `asFailure()`, a 400 with a
 * `message` and maybe `errors`) come back as `ok: false` rather than throwing,
 * as do network errors.
 */
export async function postAction<T extends object = object>(
  action: string,
  data: Record<string, unknown> = {},
  options: PostActionOptions = {},
): Promise<ActionResult<T>> {
  const http = options.http ?? resolveHttp();

  try {
    const response = await http.getClient().request({
      method: "post",
      // With `action` in the body, Craft runs it whatever the URL
      url: window.location.pathname,
      data: { ...data, action },
      headers: { Accept: "application/json" },
    });
    return { ok: true, ...(parse(response.data) as T & { message?: string }) };
  } catch (error) {
    const body = parse(
      (error as { response?: { data?: unknown } } | null)?.response?.data,
    );
    return {
      ok: false,
      message:
        typeof body.message === "string"
          ? body.message
          : (options.fallbackMessage ?? FALLBACK_MESSAGE),
      errors: (body.errors as Record<string, string[]> | undefined) ?? {},
    };
  }
}

// The app may have only exposed the client as `window.inertiaHttp` for the
// plugin's script, which can still be waiting to pick it up
const resolveHttp = (): InertiaHttp => {
  const configured = getConfiguredHttp();
  if (configured) {
    return configured;
  }

  if (typeof window !== "undefined" && window.inertiaHttp) {
    configureCraftHttp(window.inertiaHttp);
    return window.inertiaHttp;
  }

  throw new Error(
    "Inertia (Craft): Call configureCraftHttp(http) before postAction().",
  );
};

// Inertia's own client returns the response text; a client swapped in with
// `http.setClient()` (such as Axios) may have parsed it already
const parse = (data: unknown): Record<string, unknown> => {
  if (data && typeof data === "object") {
    return data as Record<string, unknown>;
  }
  try {
    return typeof data === "string" && data ? JSON.parse(data) : {};
  } catch {
    return {};
  }
};
