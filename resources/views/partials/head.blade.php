<meta charset="utf-8">
{{-- viewport-fit=cover is what makes env(safe-area-inset-*) non-zero. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

<title>{{ filled($title ?? null) ? $title . ' · Logralo' : 'Logralo' }}</title>
<meta name="description" content="Objetivos diarios, rachas y pruebas con foto. Entre amigos.">

<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f7f4ef">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#1a1714">

{{-- iOS reads none of the manifest's install hints, so these still matter. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Logralo">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png">
<link rel="icon" href="/icons/icon-192.png" sizes="192x192" type="image/png">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">

@include('partials.splash')

@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Must run before first paint, or the app flashes light before going dark. --}}
@fluxAppearance

{{-- Identical inline head scripts are deduped across wire:navigate, so this
     registers exactly once per tab. The worker caches nothing; it exists so
     Chrome on Android offers the install prompt. --}}
<script>
    if ("serviceWorker" in navigator) {
        window.addEventListener("load", () => {
            navigator.serviceWorker.register("/sw.js", { scope: "/" }).catch(() => {});
        });
    }
</script>
