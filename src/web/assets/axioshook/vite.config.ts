import { defineConfig } from "vite";

export default defineConfig({
  build: {
    lib: {
      name: "axioshook",
      entry: ["src/index.ts"],
    },
  },
});
