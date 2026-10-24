// Type check only: Inertia's own `http` object must satisfy `InertiaHttp`
import type { http } from "@inertiajs/core";
import type { InertiaHttp } from "../src/index.js";

export const inertiaHttpIsCompatible = (client: typeof http): InertiaHttp =>
  client;
