import { defineConfig } from "vite";

export default defineConfig({
  define: {
    "process.env.NODE_ENV": JSON.stringify("production"),
  },
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
