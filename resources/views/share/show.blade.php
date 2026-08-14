@php
    $member = auth()->user();
    $shareable = $entry->shareable();
    $anchor = $entry->shareAnchor();
    $openUrl = route('today') . ($anchor === null ? '' : '#' . $anchor);

    // Reached only through a live token, so the card and the link are never
    // null here — `SharedEntry` matches on `share_token` and revoking clears it.
    //
    // The unfurl is the picture and nothing else. The card already says the
    // goal, the day and the streak, so a title repeating it and a description
    // pitching the app were two lines of grey under a photo that had already
    // made the point. Only the site name stays, which is what tells the reader
    // where the link goes.
    $og = [
        'title' => 'Logralo',
        'description' => '',
        'image' => $shareable->shareCardUrl(),
        'type' => 'article',
        'url' => $entry->shareUrl(),
    ];
@endphp

{{-- Written out rather than wrapped in a layout component: this is the only
     public page in the app, and `layouts::` is Livewire's namespace for the
     two internal ones rather than a Blade component directory. --}}
<!DOCTYPE html>
{{-- Always dark. The share page is a shop window, not a screen anyone lives
     in, and the photos blaze out of the charcoal. --}}
<html lang="es" class="dark scroll-smooth">
    <head>
        {{-- The tab still names what the reader is looking at; only the unfurl
             goes quiet. --}}
        @include('partials.head', ['og' => $og, 'title' => $entry->shareTitle()])
    </head>

    <body class="min-h-dvh bg-zinc-950 font-sans text-zinc-100 antialiased">
        <div class="mx-auto flex min-h-dvh w-full max-w-md flex-col px-4 pt-safe-t pb-safe-b">
            <header class="flex items-center justify-between py-4">
                <a href="{{ url('/') }}" class="flex items-center gap-2">
                    <x-brand-mark class="size-6" />
                    <span class="font-display text-xl tracking-wide">Logralo</span>
                </a>

                <span class="text-xs tracking-[0.2em] text-zinc-500 uppercase">
                    {{ $entry->day()->translatedFormat('j M') }}
                </span>
            </header>

            <main class="flex-1">
                {{-- The partials are named for the two kinds an entry answers
                     with, so the page needs no branch of its own. --}}
                @include('share.partials.' . $entry->shareKind(), ['entry' => $entry])
            </main>

            <footer class="py-8 text-center">
                @if ($member !== null)
                    <flux:button
                        href="{{ $openUrl }}"
                        variant="primary"
                        class="w-full"
                        data-test="open-app"
                    >
                        Abrir en Logralo
                    </flux:button>

                    @if ($shareable->isManagedBy($member))
                        <p class="mt-6 text-xs text-zinc-500">
                            Cualquiera con este link ve esta página.
                            @if ($shareable->share_views > 0)
                                La abrieron {{ $shareable->share_views }}
                                {{ Str::plural('vez', $shareable->share_views, 'veces') }}.
                            @endif
                        </p>

                        {{-- Revoking from the page the link opens: the only place
                             the decision is made looking at what everyone else
                             sees. --}}
                        <form method="POST" action="{{ route('share.revoke', $shareable->share_token) }}" class="mt-2">
                            @csrf
                            <button
                                type="submit"
                                class="text-xs tracking-[0.2em] text-zinc-500 uppercase transition hover:text-red-400"
                                data-test="share-revoke"
                            >
                                Dejar de compartir
                            </button>
                        </form>
                    @endif
                @else
                    {{-- No login wall. Someone who is not in the group came here
                         from a friend's chat, and a password box is a rude answer
                         to a photo. --}}
                    <p class="text-sm text-zinc-400">
                        Logralo es la app de objetivos diarios de un grupo de amigos.
                        Rachas, fotos como prueba, y una tabla que cierra cada mes.
                    </p>
                    <a
                        href="{{ route('login') }}"
                        class="mt-4 inline-block text-xs tracking-[0.2em] text-zinc-500 uppercase transition hover:text-accent"
                    >
                        Ya soy del grupo
                    </a>
                @endif
            </footer>
        </div>

        @fluxScripts
    </body>
</html>
