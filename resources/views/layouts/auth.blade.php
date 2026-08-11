<!DOCTYPE html>
<html lang="es">
    <head>
        @include('partials.head')
    </head>

    <body
        class="min-h-dvh bg-zinc-100 font-sans text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-100"
    >
        <div
            class="relative flex min-h-dvh flex-col justify-center overflow-hidden px-6 pt-safe-t pb-safe-b"
        >
            {{-- Ember glow behind the card: the only light in the room. --}}
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -top-40 left-1/2 h-96 w-96 -translate-x-1/2 rounded-full bg-accent/25 blur-3xl"
            ></div>

            <div class="relative mx-auto w-full max-w-sm">
                <a href="{{ route('login') }}" class="mb-10 flex items-center gap-3" wire:navigate>
                    <x-brand-mark class="size-11" />
                    <span class="font-display text-3xl tracking-wide text-zinc-900 dark:text-white">
                        Logralo
                    </span>
                </a>

                {{ $slot }}
            </div>
        </div>

        @persist('toast')
            <flux:toast.group position="bottom center">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
