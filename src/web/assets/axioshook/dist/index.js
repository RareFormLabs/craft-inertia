const u = "/actions/users/session-info", s = async function() {
  return await fetch(u, {
    headers: { Accept: "application/json" }
  }).then((t) => t.json());
};
let o = null;
const l = (t) => {
  const e = new URL(t).pathname, a = window.location.pathname.split("/");
  a.pop();
  const i = a.join("/");
  return e.replace(i, "").replace(/^\//, "");
}, r = () => {
  const t = document.head.querySelector("meta[csrf]");
  if (!t)
    return null;
  const n = t == null ? void 0 : t.getAttribute("name"), e = t == null ? void 0 : t.getAttribute("content");
  return {
    csrfTokenName: n ?? "CRAFT_CSRF_TOKEN",
    csrfTokenValue: e ?? ""
  };
}, c = (t, n) => {
  let e = document.head.querySelector("meta[csrf]");
  e ? (e.setAttribute("name", t), e.setAttribute("content", n)) : (e = document.createElement("meta"), e.setAttribute("csrf", ""), e.setAttribute("name", t), e.setAttribute("content", n), document.head.appendChild(e));
}, h = async () => {
  window.axios.defaults.headers = {
    "Content-Type": "multipart/form-data"
  }, window.axios.interceptors.request.use(async (t) => {
    if (t.method === "post" || t.method === "put") {
      let n = r();
      n || (o = await s(), o.isGuest || (c(o.csrfTokenName, o.csrfTokenValue), n = r()));
      const e = n || o;
      if (!e)
        throw new Error("CSRF token not found");
      const a = l(t.url);
      t.url = "", t.data instanceof FormData ? (t.data.append(e.csrfTokenName, e.csrfTokenValue), t.data.append("action", a)) : t.data = {
        [e.csrfTokenName]: e.csrfTokenValue,
        action: a,
        ...t.data
      };
    }
    return t;
  }), window.axios.interceptors.response.use(
    async (t) => (t.config.data.get("action") == "users/login" && await s().then((n) => {
      c(n.csrfTokenName, n.csrfTokenValue);
    }), t)
  );
}, d = async () => {
  let n = 0;
  const e = setInterval(async () => {
    if (window.axios) {
      clearInterval(e), await h();
      return;
    }
    n++, n >= 40 && (clearInterval(e), console.warn(
      "Inertia (Craft): Axios not found after 10 seconds. CSRF protection may not be active."
    ));
  }, 250);
};
d();
