@props(['og'])

<!DOCTYPE html>
{{-- Always dark. The share page is a shop window, not a screen anyone lives
     in, and the photos blaze out of the charcoal. --}}
<html lang="es" class="dark scroll-smooth">
    <head>
        @include('partials.head', ['og' => $og, 'title' => $og['title']])
    </head>

    <body class="min-h-dvh bg-zinc-950 font-sans text-zinc-100 antialiased">
        {{ $slot }}

        @fluxScripts
    </body>
</html>
