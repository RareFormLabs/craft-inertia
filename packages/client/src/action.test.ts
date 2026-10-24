import { beforeEach, describe, expect, it } from "bun:test";
import {
  createFakeHttp,
  metaToken,
  resetCraftHttp,
  responseError,
  setMetaToken,
} from "../test/fakeHttp.js";
import { postAction } from "./action.js";
import { configureCraftHttp } from "./http.js";

beforeEach(() => {
  resetCraftHttp();
  setMetaToken("CRAFT_CSRF_TOKEN", "abc");
});

describe("postAction", () => {
  it("posts the action to the current page and returns Craft's JSON", async () => {
    const { http, sent } = createFakeHttp(() => ({
      status: 200,
      data: JSON.stringify({ message: "Saved.", secret: "xyz" }),
    }));
    configureCraftHttp(http);

    const result = await postAction<{ secret: string }>("two-factor/start", {
      password: "hunter2",
      action: "ignored",
    });

    expect(result).toEqual({ ok: true, message: "Saved.", secret: "xyz" });
    expect(sent[0].url).toBe("/account/settings");
    expect(sent[0].headers).toEqual({
      Accept: "application/json",
      "X-Craft-Csrf-Sync": "1",
    });
    const form = sent[0].data as FormData;
    expect(form.get("action")).toBe("two-factor/start");
    expect(form.get("password")).toBe("hunter2");
    expect(form.get("CRAFT_CSRF_TOKEN")).toBe("abc");
  });

  it("accepts a response a swapped-in client already parsed", async () => {
    const { http } = createFakeHttp(() => ({ status: 200, data: { id: 5 } }));
    configureCraftHttp(http);

    expect(await postAction<{ id: number }>("a")).toEqual({ ok: true, id: 5 });
  });

  it("returns a failure's message and errors instead of throwing", async () => {
    const { http } = createFakeHttp(() => {
      throw responseError({
        status: 400,
        data: JSON.stringify({
          message: "Couldn't save.",
          errors: { code: ["Invalid code."] },
        }),
      });
    });
    configureCraftHttp(http);

    expect(await postAction("auth/verify-totp", { code: "1" })).toEqual({
      ok: false,
      message: "Couldn't save.",
      errors: { code: ["Invalid code."] },
    });
  });

  it("falls back to a general message when a failure has none", async () => {
    const { http } = createFakeHttp(() => {
      throw new Error("Network Error");
    });
    configureCraftHttp(http);

    expect(await postAction("a")).toEqual({
      ok: false,
      message: "Something went wrong. Please try again.",
      errors: {},
    });
    expect(await postAction("a", {}, { fallbackMessage: "Try later." })).toEqual({
      ok: false,
      message: "Try later.",
      errors: {},
    });
  });

  it("sets up the client the app exposed for the plugin's script", async () => {
    const { http, sent } = createFakeHttp();
    window.inertiaHttp = http;

    await postAction("a");

    expect((sent[0].data as FormData).get("CRAFT_CSRF_TOKEN")).toBe("abc");
  });

  it("keeps the request's outcome when the action's JSON has its own ok key", async () => {
    const { http } = createFakeHttp(() => ({
      status: 200,
      data: JSON.stringify({ ok: false, reason: "pending" }),
    }));
    configureCraftHttp(http);

    expect(await postAction<{ reason: string }>("a")).toEqual({
      ok: true,
      reason: "pending",
    });
  });

  it("replaces the token with the one Craft sends back", async () => {
    const { http } = createFakeHttp(() => ({
      status: 200,
      data: "{}",
      headers: {
        "x-craft-csrf-token-name": "CRAFT_CSRF_TOKEN",
        "x-craft-csrf-token": "signed-in",
      },
    }));
    configureCraftHttp(http);

    await postAction("auth/verify-totp", { code: "123456" });

    expect(metaToken()).toEqual(["CRAFT_CSRF_TOKEN", "signed-in"]);
  });

  it("asks for configureCraftHttp() when there's no client", async () => {
    await expect(postAction("a")).rejects.toThrow("configureCraftHttp");
  });
});
