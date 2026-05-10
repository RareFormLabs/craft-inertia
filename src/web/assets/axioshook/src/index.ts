// import type { AxiosInstance, AxiosHeaders } from "axios";
// import { http } from "@inertiajs/vue3";
//
let http = null;

declare global {
  interface Window {
    inertiaHttp: any;
  }
}

type SessionInfo = {
  csrfTokenName: string;
  csrfTokenValue: string;
  isGuest: boolean;
  timeout: number;
  email?: string;
  id?: number;
  uid?: string;
  username?: string;
};

type csrfMeta = {
  csrfTokenName: string;
  csrfTokenValue: string;
};

const CSRF_ENDPOINT = "/actions/users/session-info";

const getSessionInfo = async function (): Promise<SessionInfo> {
  return await fetch(CSRF_ENDPOINT, {
    headers: { Accept: "application/json" },
  }).then((response) => response.json());
};

// Don't store the promise, store the session info once it's resolved
let sessionInfo: SessionInfo | null = null;

/**
 * Craft CMS submission requirements:
 *
 * - If specifying application/json as content-type header:
 *   - Using Inertia's Form component, include the "action" parameter
 *     - <Form method="post" action="/actions/...">
 *   - Or POST directly to a /actions/ endpoint.
 *     - (useForm) form.post("/actions/...")
 *
 * - Default: If using application/x-www-form-urlencoded content-type header:
 *   - Include "action" parameter in the form data (no /actions/ prefix)
 *     - <input type="hidden" name="action" value="entries/save-entry">
 *   - Or POST to the current URL ("")
 *     - (useForm) form.post("")
 */

const getActionPath = (url: string) => {
  const postPathObject: URL = new URL(url);
  const postPathPathname: string = postPathObject.pathname;

  // Get window.location.pathname without the last part
  const locationPathParts = window.location.pathname.split("/");
  locationPathParts.pop();
  const newPath = locationPathParts.join("/");

  const path = postPathPathname.replace(newPath, "");

  // Remove '/' from the beginning of the path
  const pathWithoutSlash = path.replace(/^\//, "");

  return pathWithoutSlash;
};

const getTokenFromMeta = (): csrfMeta | null => {
  const csrfMetaEl = document.head.querySelector("meta[csrf]");
  if (!csrfMetaEl) {
    return null;
  }
  const tokenName = csrfMetaEl?.getAttribute("name");
  const tokenValue = csrfMetaEl?.getAttribute("content");
  return {
    csrfTokenName: tokenName ?? "CRAFT_CSRF_TOKEN",
    csrfTokenValue: tokenValue ?? "",
  };
};

const isFormDataLike = (value: unknown): value is FormData => {
  if (typeof FormData !== "undefined" && value instanceof FormData) {
    return true;
  }

  if (!value || typeof value !== "object") {
    return false;
  }

  return (
    typeof (value as FormData).append === "function" &&
    typeof (value as FormData).get === "function" &&
    typeof (value as FormData).has === "function"
  );
};

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === "object" && value !== null && !Array.isArray(value);
};

const composeFormKey = (parent: string | null, key: string): string => {
  if (!parent) {
    return key;
  }

  return `${parent}[${key}]`;
};

const appendObjectToFormData = (
  form: FormData,
  key: string,
  value: unknown,
): void => {
  if (Array.isArray(value)) {
    value.forEach((item, index) => {
      appendObjectToFormData(form, composeFormKey(key, index.toString()), item);
    });
    return;
  }

  if (value instanceof Date) {
    form.append(key, value.toISOString());
    return;
  }

  if (typeof File !== "undefined" && value instanceof File) {
    form.append(key, value, value.name);
    return;
  }

  if (value instanceof Blob) {
    form.append(key, value);
    return;
  }

  if (typeof value === "boolean") {
    form.append(key, value ? "1" : "0");
    return;
  }

  if (typeof value === "string") {
    form.append(key, value);
    return;
  }

  if (typeof value === "number") {
    form.append(key, `${value}`);
    return;
  }

  if (value === null || value === undefined) {
    form.append(key, "");
    return;
  }

  if (isRecord(value)) {
    Object.entries(value).forEach(([childKey, childValue]) => {
      appendObjectToFormData(form, composeFormKey(key, childKey), childValue);
    });
  }
};

const objectToFormData = (source: Record<string, unknown>): FormData => {
  const form = new FormData();

  Object.entries(source).forEach(([key, value]) => {
    appendObjectToFormData(form, key, value);
  });

  return form;
};

const toRequestFormData = (data: unknown): FormData | null => {
  if (isFormDataLike(data)) {
    return data;
  }

  if (data instanceof URLSearchParams) {
    return objectToFormData(Object.fromEntries(data.entries()));
  }

  if (typeof data === "string") {
    try {
      const parsed = JSON.parse(data);
      if (isRecord(parsed)) {
        return objectToFormData(parsed);
      }
    } catch {
      return objectToFormData(
        Object.fromEntries(new URLSearchParams(data).entries()),
      );
    }

    return null;
  }

  if (isRecord(data)) {
    return objectToFormData(replaceEmptyArrays(data));
  }

  return null;
};

const setFormDataValue = (form: FormData, key: string, value: string): void => {
  form.delete(key);
  form.append(key, value);
};

/**
 * Replaces empty arrays in an object with an empty string, up to a max depth.
 * @param obj The object to process
 * @param maxDepth Maximum depth to traverse (default: 10)
 * @param currentDepth Current depth (for internal use)
 */
const replaceEmptyArrays = (obj: any, maxDepth = 10, currentDepth = 0): any => {
  if (currentDepth > maxDepth) {
    return obj;
  }
  if (Array.isArray(obj)) {
    return obj.map((item) =>
      replaceEmptyArrays(item, maxDepth, currentDepth + 1),
    );
  } else if (typeof obj === "object" && obj !== null) {
    return Object.fromEntries(
      Object.entries(obj).map(([key, value]) => [
        key,
        Array.isArray(value) && value.length === 0
          ? ""
          : replaceEmptyArrays(value, maxDepth, currentDepth + 1),
      ]),
    );
  }
  return obj;
};

const setCsrfOnMeta = (csrfTokenName: string, csrfTokenValue: string): void => {
  // Check if a CSRF meta element already exists
  let csrfMetaEl = document.head.querySelector("meta[csrf]");

  if (csrfMetaEl) {
    // Update existing meta element
    csrfMetaEl.setAttribute("name", csrfTokenName);
    csrfMetaEl.setAttribute("content", csrfTokenValue);
  } else {
    // Create and append a new meta element
    csrfMetaEl = document.createElement("meta");
    csrfMetaEl.setAttribute("csrf", "");
    csrfMetaEl.setAttribute("name", csrfTokenName);
    csrfMetaEl.setAttribute("content", csrfTokenValue);
    document.head.appendChild(csrfMetaEl);
  }
};

/**
 * Reads a field value from various data types (FormData, object, JSON string, URLSearchParams)
 * @param data The data to read from
 * @param key The field name
 * @returns The value if found, otherwise undefined
 */
const readField = (data: any, key: string): any => {
  if (isFormDataLike(data)) {
    return data.get(key);
  }
  if (typeof data === "object" && data !== null) {
    return data[key];
  }
  if (typeof data === "string") {
    // Try JSON parse
    try {
      const parsed = JSON.parse(data);
      if (typeof parsed === "object" && parsed !== null) {
        return parsed[key];
      }
    } catch {
      // Not JSON, try URLSearchParams
      const params = new URLSearchParams(data);
      return params.get(key);
    }
  }
  return undefined;
};

const shouldRefreshCsrfForData = (data: any): boolean => {
  const action = readField(data, "action");
  const requiresFreshCsrf = ["users/login", "users/set-password"];

  if (action && requiresFreshCsrf.includes(action)) {
    return true;
  }

  if (action === "users/save-user" && !readField(data, "userId")) {
    return true;
  }

  return false;
};

const configureHttpClient = async () => {
  http.onRequest(async (config) => {
    if (config.method !== "post" && config.method !== "put") {
      return config;
    }

    let csrfMeta = getTokenFromMeta();
    if (!csrfMeta) {
      // Wait for the session info to be resolved before configuring axios
      sessionInfo = await getSessionInfo();
      if (sessionInfo.isGuest) {
        setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
        csrfMeta = getTokenFromMeta();
      }
    }

    const csrf = csrfMeta || sessionInfo;

    if (!csrf) {
      throw new Error(
        "Inertia (Craft): CSRF token not found. Ensure session is initialized or meta tag is present.",
      );
    }

    const actionPath = getActionPath(config.url ?? "");
    const formData = toRequestFormData(config.data);

    if (!formData) {
      return config;
    }

    if (!formData.has("action")) {
      formData.append("action", actionPath);
      config.url = "";
    }

    setFormDataValue(formData, csrf.csrfTokenName, csrf.csrfTokenValue);

    /** NOTE: FormData cannot represent empty arrays. If you need to send empty arrays as values,
     * add a placeholder value (e.g., an empty string or special marker) when building the FormData.
     * eg, if (myArray.length === 0) formData.append('myArray', '');
     */
    config.data = formData;

    return config;
  });
};

const configureFinishListener = () => {
  document.addEventListener("inertia:finish", async (event: Event) => {
    const visit = (event as CustomEvent).detail?.visit;

    if (!visit || visit.cancelled || visit.interrupted || !visit.completed) {
      return;
    }

    if (!shouldRefreshCsrfForData(visit.data)) {
      return;
    }

    await getSessionInfo().then((sessionInfo) => {
      setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
    });
  });
};

console.log("Inertia (Craft): Configuring HTTP Client...");

const checkForHttpClient = async () => {
  const MAX_ATTEMPTS = 40; // 10 seconds total (50 * 200ms)
  let attempts = 0;

  const intervalCheck = setInterval(async () => {
    if (window.inertiaHttp) {
      http = window.inertiaHttp;
      clearInterval(intervalCheck);
      await configureHttpClient();
      configureFinishListener();
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
