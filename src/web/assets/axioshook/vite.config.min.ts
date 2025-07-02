import { defineConfig } from "vite";

export default defineConfig({
  build: {
    minify: true,
    lib: {
      name: "axioshook",
      entry: ["src/index.ts"],
    },
    rollupOptions: {
      output: {
        entryFileNames: `index.js`,
      },
    },
  },
});
