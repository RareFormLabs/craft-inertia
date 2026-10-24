import { beforeEach, expect, it } from "bun:test";
import { http } from "@inertiajs/core";
import { resetCraftHttp, setMetaToken } from "../test/fakeHttp.js";
import { configureCraftHttp, postAction } from "./index.js";

beforeEach(resetCraftHttp);

it("works with Inertia's own http object", async () => {
  setMetaToken("CRAFT_CSRF_TOKEN", "abc");
  let sent: FormData | null = null;
  // A client that skips the network but runs Inertia's handlers, as its own does
  http.setClient({
    request: async (config) => {
      const processed = await http.processRequest(config);
      sent = processed.data as FormData;
      return http.processResponse({
        status: 200,
        data: JSON.stringify({ message: "Saved." }),
        headers: {
          "x-craft-csrf-token-name": "CRAFT_CSRF_TOKEN",
          "x-craft-csrf-token": "next",
        },
      });
    },
  });
  configureCraftHttp(http);

  const result = await postAction("users/save-user", { firstName: "Ada" });

  expect(result).toEqual({ ok: true, message: "Saved." });
  expect(sent!.get("action")).toBe("users/save-user");
  expect(sent!.get("CRAFT_CSRF_TOKEN")).toBe("abc");
  expect(document.head.querySelector("meta[csrf]")?.getAttribute("content")).toBe("next");
});
