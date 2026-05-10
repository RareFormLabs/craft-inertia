import { defineConfig } from "vite";

export default defineConfig({
  define: {
    "process.env.NODE_ENV": JSON.stringify("development"),
  },
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
