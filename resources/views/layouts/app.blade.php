<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-dvh bg-zinc-100 font-sans text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        {{ $slot }}

        @persist('toast')
            <flux:toast.group position="bottom center">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
