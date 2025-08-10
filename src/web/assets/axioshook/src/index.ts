import type { AxiosInstance } from "axios";

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
    "Content-Type": "multipart/form-data",
  };

  (window.axios as AxiosInstance).interceptors.request.use(async (config) => {
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
        throw new Error("CSRF token not found");
      }

      const actionPath = getActionPath(config.url);

      config.url = "";

      if (config.data instanceof FormData) {
        config.data.append(csrf.csrfTokenName, csrf.csrfTokenValue);
        config.data.append("action", actionPath);
        // NOTE: FormData cannot represent empty arrays. If you need to send empty arrays,
        // add a placeholder value (e.g., an empty string or special marker) when building the FormData.
        // Example:
        // if (myArray.length === 0) formData.append('myArray', '');
      } else {
        // For plain objects, replace empty arrays with empty strings before sending
        const replaceEmptyArrays = (obj: any): any => {
          if (Array.isArray(obj)) {
            return obj.map((item) => replaceEmptyArrays(item));
          } else if (typeof obj === "object" && obj !== null) {
            return Object.fromEntries(
              Object.entries(obj).map(([key, value]) => [
                key,
                Array.isArray(value) && value.length === 0
                  ? ""
                  : replaceEmptyArrays(value),
              ])
            );
          }
          return obj;
        };

        const contentType =
          config.headers["Content-Type"] ||
          config.headers["content-type"] ||
          config.headers["CONTENT-TYPE"];

        let data = {
          [csrf.csrfTokenName]: csrf.csrfTokenValue,
          action: actionPath,
          ...config.data,
        };
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
      if (response.config.data?.get("action") == "users/login") {
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
