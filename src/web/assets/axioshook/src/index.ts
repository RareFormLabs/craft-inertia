import type { AxiosInstance, AxiosHeaders } from "axios";

declare global {
  interface Window {
    axios: any;
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

/**
 * Replaces empty arrays in an object with an empty string, up to a max depth.
 * @param obj The object to process
 * @param maxDepth Maximum depth to traverse (default: 10)
 * @param currentDepth Current depth (for internal use)
 */
export const replaceEmptyArrays = (
  obj: any,
  maxDepth = 10,
  currentDepth = 0
): any => {
  if (currentDepth > maxDepth) {
    return obj;
  }
  if (Array.isArray(obj)) {
    return obj.map((item) =>
      replaceEmptyArrays(item, maxDepth, currentDepth + 1)
    );
  } else if (typeof obj === "object" && obj !== null) {
    return Object.fromEntries(
      Object.entries(obj).map(([key, value]) => [
        key,
        Array.isArray(value) && value.length === 0
          ? ""
          : replaceEmptyArrays(value, maxDepth, currentDepth + 1),
      ])
    );
  }
  return obj;
};

const getContentType = (
  headers: AxiosHeaders | Record<string, any>
): string | undefined => {
  // AxiosHeaders may have a .get() method, otherwise treat as plain object
  if (typeof (headers as any).get === "function") {
    return (headers as any).get("content-type");
  }
  for (const key in headers) {
    if (key.toLowerCase() === "content-type") {
      return headers[key];
    }
  }
  return undefined;
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

const configureAxios = async () => {
  window.axios.defaults.headers = {
    "Content-Type": "application/x-www-form-urlencoded",
  };

  (window.axios as AxiosInstance).interceptors.request.use(async (config) => {
    // debugger;
    if (config.method === "post" || config.method === "put") {
      let csrfMeta = getTokenFromMeta();
      if (!csrfMeta) {
        // Wait for the session info to be resolved before configuring axios
        sessionInfo = await getSessionInfo();
        if (!sessionInfo.isGuest) {
          setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
          csrfMeta = getTokenFromMeta();
        }
      }

      const csrf = csrfMeta || sessionInfo;

      if (!csrf) {
        throw new Error(
          "Inertia (Craft): CSRF token not found. Ensure session is initialized or meta tag is present."
        );
      }

      const actionPath = getActionPath(config.url ?? "");

      if (config.data instanceof FormData) {
        if (!config.data.has("action")) {
          config.data.append("action", actionPath);
        }
        config.url = "";
        config.data.append(csrf.csrfTokenName, csrf.csrfTokenValue);

        // NOTE: FormData cannot represent empty arrays. If you need to send empty arrays,
        // add a placeholder value (e.g., an empty string or special marker) when building the FormData.
        // eg, if (myArray.length === 0) formData.append('myArray', '');
      } else {
        let data = {
          [csrf.csrfTokenName]: csrf.csrfTokenValue,
          action: actionPath,
          ...config.data,
        };

        const contentType = getContentType(config.headers);
        if (
          typeof contentType === "string" &&
          contentType.toLowerCase().includes("multipart/form-data")
        ) {
          data = replaceEmptyArrays(data);
        }
        config.data = data;
      }
    }
    return config;
  });

  // Add a response interceptor
  (window.axios as AxiosInstance).interceptors.response.use(
    async (response) => {
      // debugger;
      // Support both FormData and plain object/stringified data
      let action = null;
      if (response.config.data instanceof FormData) {
        action = response.config.data.get("action");
      } else if (
        typeof response.config.data === "object" &&
        response.config.data !== null
      ) {
        action = response.config.data.action;
      } else if (typeof response.config.data === "string") {
        // Try to parse as JSON or URL-encoded
        try {
          const parsed = JSON.parse(response.config.data);
          action = parsed.action;
        } catch {
          // Try URLSearchParams
          const params = new URLSearchParams(response.config.data);
          action = params.get("action");
        }
      }
      if (action === "users/login") {
        await getSessionInfo().then((sessionInfo) => {
          setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
        });
      }
      return response;
    }
  );
};

const checkForAxios = async () => {
  const MAX_ATTEMPTS = 40; // 10 seconds total (50 * 200ms)
  let attempts = 0;

  const intervalCheck = setInterval(async () => {
    if (window.axios) {
      clearInterval(intervalCheck);
      await configureAxios();
      console.log("Inertia (Craft): Axios configured successfully.");
      return;
    }

    attempts++;
    if (attempts >= MAX_ATTEMPTS) {
      clearInterval(intervalCheck);
      console.warn(
        "Inertia (Craft): Axios not found after 10 seconds. CSRF protection may not be active."
      );
    }
  }, 250);
};

checkForAxios();
