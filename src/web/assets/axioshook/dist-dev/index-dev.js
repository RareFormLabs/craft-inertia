(function(factory) {
  typeof define === "function" && define.amd ? define(factory) : factory();
})(function() {
  "use strict";
  const CSRF_ENDPOINT = "/actions/users/session-info";
  const getSessionInfo = async function() {
    return await fetch(CSRF_ENDPOINT, {
      headers: { Accept: "application/json" }
    }).then((response) => response.json());
  };
  let sessionInfo = null;
  const getActionPath = (url) => {
    const postPathObject = new URL(url);
    const postPathPathname = postPathObject.pathname;
    const locationPathParts = window.location.pathname.split("/");
    locationPathParts.pop();
    const newPath = locationPathParts.join("/");
    const path = postPathPathname.replace(newPath, "");
    const pathWithoutSlash = path.replace(/^\//, "");
    return pathWithoutSlash;
  };
  const getTokenFromMeta = () => {
    const csrfMetaEl = document.head.querySelector("meta[csrf]");
    if (!csrfMetaEl) {
      return null;
    }
    const tokenName = csrfMetaEl == null ? void 0 : csrfMetaEl.getAttribute("name");
    const tokenValue = csrfMetaEl == null ? void 0 : csrfMetaEl.getAttribute("content");
    return {
      csrfTokenName: tokenName ?? "CRAFT_CSRF_TOKEN",
      csrfTokenValue: tokenValue ?? ""
    };
  };
  const replaceEmptyArrays = (obj, maxDepth = 10, currentDepth = 0) => {
    if (currentDepth > maxDepth) {
      return obj;
    }
    if (Array.isArray(obj)) {
      return obj.map(
        (item) => replaceEmptyArrays(item, maxDepth, currentDepth + 1)
      );
    } else if (typeof obj === "object" && obj !== null) {
      return Object.fromEntries(
        Object.entries(obj).map(([key, value]) => [
          key,
          Array.isArray(value) && value.length === 0 ? "" : replaceEmptyArrays(value, maxDepth, currentDepth + 1)
        ])
      );
    }
    return obj;
  };
  const getContentType = (headers) => {
    if (typeof headers.get === "function") {
      return headers.get("content-type");
    }
    for (const key in headers) {
      if (key.toLowerCase() === "content-type") {
        return headers[key];
      }
    }
    return void 0;
  };
  const setCsrfOnMeta = (csrfTokenName, csrfTokenValue) => {
    let csrfMetaEl = document.head.querySelector("meta[csrf]");
    if (csrfMetaEl) {
      csrfMetaEl.setAttribute("name", csrfTokenName);
      csrfMetaEl.setAttribute("content", csrfTokenValue);
    } else {
      csrfMetaEl = document.createElement("meta");
      csrfMetaEl.setAttribute("csrf", "");
      csrfMetaEl.setAttribute("name", csrfTokenName);
      csrfMetaEl.setAttribute("content", csrfTokenValue);
      document.head.appendChild(csrfMetaEl);
    }
  };
  const readField = (data, key) => {
    if (data instanceof FormData) {
      return data.get(key);
    }
    if (typeof data === "object" && data !== null) {
      return data[key];
    }
    if (typeof data === "string") {
      try {
        const parsed = JSON.parse(data);
        if (typeof parsed === "object" && parsed !== null) {
          return parsed[key];
        }
      } catch {
        const params = new URLSearchParams(data);
        return params.get(key);
      }
    }
    return void 0;
  };
  const configureAxios = async () => {
    window.axios.interceptors.request.use(async (config) => {
      if (config.method !== "post" && config.method !== "put") {
        return config;
      }
      let csrfMeta = getTokenFromMeta();
      if (!csrfMeta) {
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
      if (getContentType(config.headers) == void 0) {
        config.headers.set("Content-Type", "application/x-www-form-urlencoded");
      }
      if (config.data instanceof FormData) {
        if (!config.data.has("action")) {
          config.data.append("action", actionPath);
          config.url = "";
        }
        config.data.append(csrf.csrfTokenName, csrf.csrfTokenValue);
      } else {
        let data = {
          [csrf.csrfTokenName]: csrf.csrfTokenValue,
          action: actionPath,
          ...config.data
        };
        const contentType = getContentType(config.headers ?? {});
        if (typeof contentType === "string" && contentType.toLowerCase().includes("multipart/form-data")) {
          data = replaceEmptyArrays(data);
        }
        config.data = data;
      }
      return config;
    });
    window.axios.interceptors.response.use(
      async (response) => {
        let action = null;
        if (response.config.data instanceof FormData) {
          action = response.config.data.get("action");
        } else if (typeof response.config.data === "object" && response.config.data !== null) {
          action = response.config.data.action;
        } else if (typeof response.config.data === "string") {
          try {
            const parsed = JSON.parse(response.config.data);
            action = parsed.action;
          } catch {
            const params = new URLSearchParams(response.config.data);
            action = params.get("action");
          }
        }
        let shouldRefreshCsrf = false;
        const requiresFreshCsrf = ["users/login", "users/set-password"];
        if (action && requiresFreshCsrf.includes(action)) {
          shouldRefreshCsrf = true;
        } else if (action && action == "users/save-user") {
          if (!readField(response.config.data, "userId")) {
            shouldRefreshCsrf = true;
          }
        }
        if (shouldRefreshCsrf) {
          await getSessionInfo().then((sessionInfo2) => {
            setCsrfOnMeta(sessionInfo2.csrfTokenName, sessionInfo2.csrfTokenValue);
          });
        }
        return response;
      }
    );
  };
  const checkForAxios = async () => {
    const MAX_ATTEMPTS = 40;
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
});
