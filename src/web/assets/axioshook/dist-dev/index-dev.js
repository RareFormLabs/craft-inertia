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
		if (!url) return "";
		let postPathPathname = "";
		try {
			postPathPathname = new URL(url, window.location.origin).pathname;
		} catch {
			return "";
		}
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
	var isFormDataLike = (value) => {
		if (typeof FormData !== "undefined" && value instanceof FormData) return true;
		if (!value || typeof value !== "object") return false;
		return typeof value.append === "function" && typeof value.get === "function" && typeof value.has === "function";
	};
	var isRecord = (value) => {
		return typeof value === "object" && value !== null && !Array.isArray(value);
	};
	var composeFormKey = (parent, key) => {
		if (!parent) return key;
		return `${parent}[${key}]`;
	};
	var appendObjectToFormData = (form, key, value) => {
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
		if (value === null || value === void 0) {
			form.append(key, "");
			return;
		}
		if (isRecord(value)) Object.entries(value).forEach(([childKey, childValue]) => {
			appendObjectToFormData(form, composeFormKey(key, childKey), childValue);
		});
	};
	var objectToFormData = (source) => {
		const form = new FormData();
		Object.entries(source).forEach(([key, value]) => {
			appendObjectToFormData(form, key, value);
		});
		return form;
	};
	var toRequestFormData = (data) => {
		if (isFormDataLike(data)) return data;
		if (data instanceof URLSearchParams) return objectToFormData(Object.fromEntries(data.entries()));
		if (typeof data === "string") {
			try {
				const parsed = JSON.parse(data);
				if (isRecord(parsed)) return objectToFormData(parsed);
			} catch {
				return objectToFormData(Object.fromEntries(new URLSearchParams(data).entries()));
			}
			return null;
		}
		if (isRecord(data)) return objectToFormData(replaceEmptyArrays(data));
		return null;
	};
	var setFormDataValue = (form, key, value) => {
		form.delete(key);
		form.append(key, value);
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
	* Reads a header from a response, whether its headers are a plain object with
	* lowercase keys (Inertia's XHR client) or have a get() method (Axios).
	*/
	var readHeader = (response, name) => {
		const headers = response?.headers;
		if (!headers) return null;
		const value = typeof headers.get === "function" ? headers.get(name) : headers[name.toLowerCase()];
		return typeof value === "string" && value !== "" ? value : null;
	};
	/**
	* Craft sends the session's current CSRF token with every Inertia response.
	* Keeping the meta tag in sync with it means a token invalidated by logging in
	* or out is never sent with a later submission.
	*/
	var updateCsrfFromResponse = (response) => {
		const tokenName = readHeader(response, "X-Craft-Csrf-Token-Name");
		const tokenValue = readHeader(response, "X-Craft-Csrf-Token");
		if (tokenName && tokenValue) setCsrfOnMeta(tokenName, tokenValue);
	};
	var configureHttpClient = async () => {
		if (!http) return;
		http.onRequest(async (config) => {
			if (config.method !== "post" && config.method !== "put") return config;
			let csrfMeta = getTokenFromMeta();
			if (!csrfMeta) {
				sessionInfo = await getSessionInfo();
				if (sessionInfo.isGuest) {
					setCsrfOnMeta(sessionInfo.csrfTokenName, sessionInfo.csrfTokenValue);
					csrfMeta = getTokenFromMeta();
				}
			}
			const csrf = csrfMeta || sessionInfo;
			if (!csrf) throw new Error("Inertia (Craft): CSRF token not found. Ensure session is initialized or meta tag is present.");
			const formData = toRequestFormData(config.data);
			if (!formData) return config;
			if (!formData.has("action")) {
				const actionPath = getActionPath(config.url ?? "");
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
	var configureResponseHandlers = () => {
		if (!http) return;
		http.onResponse((response) => {
			updateCsrfFromResponse(response);
			return response;
		});
		http.onError((error) => {
			updateCsrfFromResponse(error.response);
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
				configureResponseHandlers();
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
