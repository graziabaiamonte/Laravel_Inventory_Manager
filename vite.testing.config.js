import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";

export default defineConfig({
    plugins: [
        laravel({
            input: "resources/js/app.tsx",
            refresh: true,
        }),
        react(),
    ],
    server: {
        host: "laravel.test",   // Listen on all network interfaces for Docker access
        port: 5173,         // Ensure Vite uses port 5173 for assets
        strictPort: true,   // Ensure port 5173 is used and not anything else
      },
});
