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
  const configureAxios = async () => {
    window.axios.defaults.headers = {
      "Content-Type": "multipart/form-data"
    };
    window.axios.interceptors.request.use(async (config) => {
      if (config.method === "post" || config.method === "put") {
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
          throw new Error("CSRF token not found");
        }
        const actionPath = getActionPath(config.url);
        config.url = "";
        if (config.data instanceof FormData) {
          config.data.append(csrf.csrfTokenName, csrf.csrfTokenValue);
          config.data.append("action", actionPath);
        } else {
          const replaceEmptyArrays = (obj) => {
            if (Array.isArray(obj)) {
              return obj.map((item) => replaceEmptyArrays(item));
            } else if (typeof obj === "object" && obj !== null) {
              return Object.fromEntries(
                Object.entries(obj).map(([key, value]) => [
                  key,
                  Array.isArray(value) && value.length === 0 ? "" : replaceEmptyArrays(value)
                ])
              );
            }
            return obj;
          };
          const contentType = config.headers["Content-Type"] || config.headers["content-type"] || config.headers["CONTENT-TYPE"];
          let data = {
            [csrf.csrfTokenName]: csrf.csrfTokenValue,
            action: actionPath,
            ...config.data
          };
          if (typeof contentType === "string" && contentType.toLowerCase().includes("multipart/form-data")) {
            data = replaceEmptyArrays(data);
          }
          config.data = data;
        }
      }
      return config;
    });
    window.axios.interceptors.response.use(
      async (response) => {
        var _a;
        if (((_a = response.config.data) == null ? void 0 : _a.get("action")) == "users/login") {
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
