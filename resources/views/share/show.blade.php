@php
    use App\ValueObjects\MarkEntry;

    $isMark = $entry instanceof MarkEntry;
    $member = auth()->user();
    $shareable = $entry->shareable();
    $isOwner = $member !== null && (! $isMark || $entry->mark->user_id === $member->id);

    $og = [
        'title' => $isMark ? $entry->shareTitle() : $entry->shareText(),
        'description' => $isMark
            ? 'Una prueba más en Logralo. Objetivos diarios y rachas, entre amigos.'
            : 'Cómo terminó el mes en Logralo.',
        'image' => $shareable->shareCardUrl() ?? route('share.default-card'),
        'type' => 'article',
        'url' => $entry->shareUrl() ?? url('/'),
    ];
@endphp

<x-layouts.share :og="$og">
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
            @if ($isMark)
                @include('share.partials.mark', ['entry' => $entry, 'member' => $member])
            @else
                @include('share.partials.recap', ['entry' => $entry])
            @endif
        </main>

        <footer class="py-8 text-center">
            @if ($member !== null)
                <flux:button
                    href="{{ $isMark ? route('today') . '#mark-' . $entry->mark->id : route('today') }}"
                    variant="primary"
                    class="w-full"
                    data-test="open-app"
                >
                    Abrir en Logralo
                </flux:button>

                @if ($isOwner)
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
</x-layouts.share>
