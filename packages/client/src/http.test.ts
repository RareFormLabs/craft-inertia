import { afterEach, beforeEach, describe, expect, it, mock } from "bun:test";
import {
  createFakeHttp,
  metaToken,
  resetCraftHttp,
  responseError,
  setMetaToken,
} from "../test/fakeHttp.js";
import { configureCraftHttp } from "./http.js";

const realFetch = globalThis.fetch;

beforeEach(resetCraftHttp);
afterEach(() => {
  globalThis.fetch = realFetch;
});

const sentForm = (config: { data?: unknown }) => config.data as FormData;

describe("configureCraftHttp", () => {
  it("sends a POST as form data with the CSRF token from the meta tag", async () => {
    setMetaToken("CRAFT_CSRF_TOKEN", "abc");
    const { http, sent } = createFakeHttp();
    configureCraftHttp(http);

    await http.getClient().request({
      method: "post",
      url: "/account",
      data: { action: "users/save-user", fields: { phone: "555" } },
    });

    const form = sentForm(sent[0]);
    expect(form.get("CRAFT_CSRF_TOKEN")).toBe("abc");
    expect(form.get("action")).toBe("users/save-user");
    expect(form.get("fields[phone]")).toBe("555");
    expect(sent[0].url).toBe("/account");
  });

  it("turns the URL into the action when the body has none", async () => {
    setMetaToken("CRAFT_CSRF_TOKEN", "abc");
    const { http, sent } = createFakeHttp();
    configureCraftHttp(http);

    await http.getClient().request({
      method: "post",
      url: "/account/entries/save-entry",
      data: { title: "Hi" },
    });

    expect(sentForm(sent[0]).get("action")).toBe("entries/save-entry");
    expect(sent[0].url).toBe("");
  });

  it("leaves GET requests alone", async () => {
    const { http, sent } = createFakeHttp();
    configureCraftHttp(http);

    await http.getClient().request({ method: "get", url: "/news" });

    expect(sent[0]).toEqual({ method: "get", url: "/news" });
  });

  it("asks Craft for a guest's token when the page has no meta tag", async () => {
    const fetchMock = mock(async () =>
      Response.json({
        csrfTokenName: "CRAFT_CSRF_TOKEN",
        csrfTokenValue: "guest",
        isGuest: true,
        timeout: 0,
      }),
    );
    globalThis.fetch = fetchMock as unknown as typeof fetch;
    const { http, sent } = createFakeHttp();
    configureCraftHttp(http);

    await http.getClient().request({ method: "post", url: "", data: { action: "a" } });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(sentForm(sent[0]).get("CRAFT_CSRF_TOKEN")).toBe("guest");
    expect(metaToken()).toEqual(["CRAFT_CSRF_TOKEN", "guest"]);
  });

  it("only configures a client once", () => {
    const { http, handlerCount } = createFakeHttp();
    configureCraftHttp(http);
    configureCraftHttp(http);

    expect(handlerCount()).toBe(3);
  });

  it("keeps the meta tag in step with the token Craft sends back", async () => {
    setMetaToken("CRAFT_CSRF_TOKEN", "old");
    const { http } = createFakeHttp(() => ({
      status: 200,
      headers: {
        "x-craft-csrf-token-name": "CRAFT_CSRF_TOKEN",
        "x-craft-csrf-token": "new",
        "x-craft-csrf-token-time": "1000",
      },
    }));
    configureCraftHttp(http);

    await http.getClient().request({ method: "get", url: "/" });

    expect(metaToken()).toEqual(["CRAFT_CSRF_TOKEN", "new"]);
  });

  it("takes the token from a rejected request, but not from one Craft started earlier", async () => {
    setMetaToken("CRAFT_CSRF_TOKEN", "old");
    const headers = (token: string, time: number) => ({
      "x-craft-csrf-token-name": "CRAFT_CSRF_TOKEN",
      "x-craft-csrf-token": token,
      "x-craft-csrf-token-time": String(time),
    });
    let next = headers("fresh", 3000);
    const { http } = createFakeHttp(() => {
      throw responseError({ status: 400, headers: next });
    });
    configureCraftHttp(http);

    await http.getClient().request({ method: "get", url: "/" }).catch(() => {});
    expect(metaToken()).toEqual(["CRAFT_CSRF_TOKEN", "fresh"]);

    next = headers("stale", 2000);
    await http.getClient().request({ method: "get", url: "/" }).catch(() => {});
    expect(metaToken()).toEqual(["CRAFT_CSRF_TOKEN", "fresh"]);
  });
});
