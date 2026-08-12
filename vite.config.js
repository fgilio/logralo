import tailwindcss from "@tailwindcss/vite";
import laravel from "laravel-vite-plugin";
import { fontsource } from "laravel-vite-plugin/fonts";
import { defineConfig } from "vite";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/css/app.css", "resources/js/app.js"],
            refresh: true,
            // Self-hosted and preloaded at build time. A phone on the home
            // screen should never wait on a font CDN.
            //
            // The files come from npm rather than the `google()` provider:
            // that one fetches fonts.gstatic.com during the build, and the
            // URLs it resolves are versioned and drift, so a build could fail
            // on a 404 that nothing in this repo caused. Fontsource resolves
            // from node_modules, pinned by package-lock.json.
            fonts: [
                fontsource("Anton", {
                    weights: [400],
                    subsets: ["latin", "latin-ext"],
                }),
                fontsource("Archivo", {
                    weights: [400, 500, 600, 700],
                    subsets: ["latin", "latin-ext"],
                    preload: [{ weight: 400 }, { weight: 600 }],
                }),
            ],
        }),
        tailwindcss(),
    ],
});
