import { defineConfig } from "vite";

export default defineConfig({
  build: {
    // minify: false,
    lib: {
      name: "axioshook",
      entry: ["src/index.ts"],
    },
  },
});
