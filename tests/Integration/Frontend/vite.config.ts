import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Same-origin proxies keep the browser fixtures free of CORS concerns:
// /api/* reaches the PHP endpoint, /runtime reaches the CopilotKit runtime bridge.
export default defineConfig({
  root: "fixtures",
  plugins: [react()],
  server: {
    proxy: {
      "/api": { target: "http://127.0.0.1:8787", rewrite: (path) => path.replace(/^\/api/, "") },
      "/runtime": { target: "http://127.0.0.1:4000" },
    },
  },
});
