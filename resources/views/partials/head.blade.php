<meta charset="utf-8">
{{-- viewport-fit=cover is what makes env(safe-area-inset-*) non-zero. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

@php
    // Every page unfurls as something. Without these a pasted link showed
    // WhatsApp the login page's title over a favicon-sized tile, identically
    // for every share anyone in the group ever sent.
    // The tab always carries the suffix. A page that supplies its own og:title
    // keeps it bare, because a chat preview already says where it came from.
    $pageTitle = filled($title ?? null) ? $title . ' · Logralo' : 'Logralo';

    $og = ($og ?? []) + [
        'title' => $pageTitle,
        'description' => 'Objetivos diarios, rachas y pruebas con foto. Entre amigos.',
        'image' => route('share.default-card'),
        'width' => 1200,
        'height' => 630,
        'url' => url()->current(),
        'type' => 'website',
    ];
@endphp

<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $og['description'] }}">

{{-- WhatsApp reads the OpenGraph tags and nothing else. It only draws the
     large preview when og:image is present with its dimensions declared;
     without them it falls back to the small tile beside the text. --}}
<meta property="og:site_name" content="Logralo">
<meta property="og:type" content="{{ $og['type'] }}">
<meta property="og:title" content="{{ $og['title'] }}">
<meta property="og:description" content="{{ $og['description'] }}">
<meta property="og:url" content="{{ $og['url'] }}">
<meta property="og:image" content="{{ $og['image'] }}">
<meta property="og:image:width" content="{{ $og['width'] }}">
<meta property="og:image:height" content="{{ $og['height'] }}">
<meta property="og:locale" content="es_UY">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $og['title'] }}">
<meta name="twitter:description" content="{{ $og['description'] }}">
<meta name="twitter:image" content="{{ $og['image'] }}">

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
