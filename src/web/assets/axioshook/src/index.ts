const getSessionInfo = async function () {
  return await fetch("/actions/users/session-info", {
    headers: {
      Accept: "application/json",
    },
  }).then((response) => response.json());
};

// Don't store the promise, store the session info once it's resolved
let sessionInfo = null;

const getActionPath = (url) => {
  const postPathObject = URL.parse(url);
  const postPathPathname = postPathObject.pathname;

  // Get window.location.pathname without the last part
  const locationPathParts = window.location.pathname.split("/");
  locationPathParts.pop();
  const newPath = locationPathParts.join("/");

  const path = postPathPathname.replace(newPath, "");

  // Remove '/' from the beginning of the path
  const pathWithoutSlash = path.replace(/^\//, "");

  return pathWithoutSlash;
};

const configureAxios = async () => {
  /** Get Token From Meta Tag
    const getCsrfMetaEl = () => document.head.querySelector("meta[csrf]");

    const getCsrfName = (csrfMeta) => {
      const meta = csrfMeta ?? getCsrfMetaEl();
      const tokenName = meta?.getAttribute("name");
      return tokenName ?? null;
    };

    const getCsrfToken = (csrfMeta) => {
      const meta = csrfMeta ?? getCsrfMetaEl();
      const tokenValue = meta?.getAttribute("content");
      return tokenValue;
    };
    */

  window.axios.defaults.headers = {
    "Content-Type": "multipart/form-data",
  };

  window.axios.interceptors.request.use(async (config) => {
    if (config.method === "post" || config.method === "put") {
      // Wait for the session info to be resolved before configuring axios
      sessionInfo = await getSessionInfo();

      const actionPath = getActionPath(config.url);

      config.url = "";

      if (config.data instanceof FormData) {
        config.data.append(
          sessionInfo.csrfTokenName,
          sessionInfo.csrfTokenValue
        );
        config.data.append("action", actionPath);
      } else {
        config.data = {
          [sessionInfo.csrfTokenName]: sessionInfo.csrfTokenValue,
          action: actionPath,
          ...config.data,
        };
      }
    }
    return config;
  });
};

const checkForAxios = async () => {
  const intervalCheck = setInterval(async () => {
    if (window.axios) {
      clearInterval(intervalCheck);
      await configureAxios();
    }
  }, 100);
};

checkForAxios();
