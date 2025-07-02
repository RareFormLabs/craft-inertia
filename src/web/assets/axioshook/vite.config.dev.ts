import { defineConfig } from "vite";

export default defineConfig({
  build: {
    minify: false,
    lib: {
      name: "axioshook",
      entry: ["src/index.ts"],
    },
    rollupOptions: {
      output: {
        entryFileNames: `index-dev.js`,
      },
    },
    outDir: "dist-dev",
  },
});
