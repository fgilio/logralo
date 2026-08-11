import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { google } from "laravel-vite-plugin/fonts";
import { defineConfig } from "vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
            // Self-hosted and preloaded at build time. A phone on the home
            // screen should never wait on a font CDN.
            fonts: [
                google("Anton", {
                    weights: [400],
                    subsets: ["latin", "latin-ext"],
                }),
                google("Archivo", {
                    weights: [400, 500, 600, 700],
                    subsets: ["latin", "latin-ext"],
                    preload: [{ weight: 400 }, { weight: 600 }],
                }),
            ],
        }),
        tailwindcss(),
    ],
});
