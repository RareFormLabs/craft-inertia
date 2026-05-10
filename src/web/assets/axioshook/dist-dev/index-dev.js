(function(factory) {
	typeof define === "function" && define.amd ? define([], factory) : factory();
})(function() {
	//#region src/index.ts
	var http = null;
	var CSRF_ENDPOINT = "/actions/users/session-info";
	var getSessionInfo = async function() {
		return await fetch(CSRF_ENDPOINT, { headers: { Accept: "application/json" } }).then((response) => response.json());
	};
	var sessionInfo = null;
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
	var getActionPath = (url) => {
		const postPathPathname = new URL(url).pathname;
		const locationPathParts = window.location.pathname.split("/");
		locationPathParts.pop();
		const newPath = locationPathParts.join("/");
		return postPathPathname.replace(newPath, "").replace(/^\//, "");
	};
	var getTokenFromMeta = () => {
		const csrfMetaEl = document.head.querySelector("meta[csrf]");
		if (!csrfMetaEl) return null;
		const tokenName = csrfMetaEl?.getAttribute("name");
		const tokenValue = csrfMetaEl?.getAttribute("content");
		return {
			csrfTokenName: tokenName ?? "CRAFT_CSRF_TOKEN",
			csrfTokenValue: tokenValue ?? ""
		};
	};
	/**
	* Replaces empty arrays in an object with an empty string, up to a max depth.
	* @param obj The object to process
	* @param maxDepth Maximum depth to traverse (default: 10)
	* @param currentDepth Current depth (for internal use)
	*/
	var replaceEmptyArrays = (obj, maxDepth = 10, currentDepth = 0) => {
		if (currentDepth > maxDepth) return obj;
		if (Array.isArray(obj)) return obj.map((item) => replaceEmptyArrays(item, maxDepth, currentDepth + 1));
		else if (typeof obj === "object" && obj !== null) return Object.fromEntries(Object.entries(obj).map(([key, value]) => [key, Array.isArray(value) && value.length === 0 ? "" : replaceEmptyArrays(value, maxDepth, currentDepth + 1)]));
		return obj;
	};
	var getContentType = (headers) => {
		if (typeof headers.get === "function") return headers.get("content-type");
		for (const key in headers) if (key.toLowerCase() === "content-type") return headers[key];
	};
	var setCsrfOnMeta = (csrfTokenName, csrfTokenValue) => {
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
	/**
	* Reads a field value from various data types (FormData, object, JSON string, URLSearchParams)
	* @param data The data to read from
	* @param key The field name
	* @returns The value if found, otherwise undefined
	*/
	var readField = (data, key) => {
		if (data instanceof FormData) return data.get(key);
		if (typeof data === "object" && data !== null) return data[key];
		if (typeof data === "string") try {
			const parsed = JSON.parse(data);
			if (typeof parsed === "object" && parsed !== null) return parsed[key];
		} catch {
			return new URLSearchParams(data).get(key);
		}
	};
	var configureHttpClient = async () => {
		http.onRequest(async (config) => {
			debugger;
			if (config.method !== "post" && config.method !== "put") return config;
			let csrfMeta = getTokenFromMeta();
			if (!csrfMeta) {
				sessionInfo = await getSessionInfo();
				debugger;
				if (sessionInfo.isGuest) {
					debugger;
					setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
					csrfMeta = getTokenFromMeta();
				}
			}
			const csrf = csrfMeta || sessionInfo;
			if (!csrf) throw new Error("Inertia (Craft): CSRF token not found. Ensure session is initialized or meta tag is present.");
			const actionPath = getActionPath(config.url ?? "");
			if (getContentType(config.headers) == void 0) config.headers["Content-Type"] = "application/x-www-form-urlencoded";
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
				if (typeof contentType === "string" && contentType.toLowerCase().includes("multipart/form-data")) data = replaceEmptyArrays(data);
				config.data = data;
			}
			return config;
		});
		http.onResponse(async (response) => {
			let action = null;
			if (response.config.data instanceof FormData) action = response.config.data.get("action");
			else if (typeof response.config.data === "object" && response.config.data !== null) action = response.config.data.action;
			else if (typeof response.config.data === "string") try {
				action = JSON.parse(response.config.data).action;
			} catch {
				action = new URLSearchParams(response.config.data).get("action");
			}
			let shouldRefreshCsrf = false;
			if (action && ["users/login", "users/set-password"].includes(action)) shouldRefreshCsrf = true;
			else if (action && action == "users/save-user") {
				if (!readField(response.config.data, "userId")) shouldRefreshCsrf = true;
			}
			if (shouldRefreshCsrf) await getSessionInfo().then((sessionInfo) => {
				setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
			});
			return response;
		});
	};
	console.log("Inertia (Craft): Configuring HTTP Client...");
	var checkForHttpClient = async () => {
		const MAX_ATTEMPTS = 40;
		let attempts = 0;
		const intervalCheck = setInterval(async () => {
			if (window.inertiaHttp) {
				http = window.inertiaHttp;
				clearInterval(intervalCheck);
				await configureHttpClient();
				console.log("Inertia (Craft): HTTP Client configured successfully.");
				return;
			}
			attempts++;
			if (attempts >= MAX_ATTEMPTS) {
				clearInterval(intervalCheck);
				console.warn("Inertia (Craft): HTTP Client not found after 10 seconds. CSRF protection may not be active.");
			}
		}, 250);
	};
	checkForHttpClient();
	//#endregion
});
