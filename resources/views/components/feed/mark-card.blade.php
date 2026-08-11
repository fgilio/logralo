@props(['entry', 'eager' => false, 'reacted' => null])

@php
    use App\Enums\ReactionEmoji;

    $mark = $entry->mark;
    $counts = $mark->reactions
        ->groupBy(fn ($reaction) => $reaction->emoji->value)
        ->map(fn ($group) => $group->count());

    $shareText = $entry->streak > 1
        ? "🔥 {$entry->streak} días seguidos de {$mark->goal->name} — Logralo"
        : "🔥 {$mark->goal->name} — Logralo";
@endphp

<article class="feed-card overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10">
    <header class="flex items-center gap-3 px-3 py-2.5">
        <flux:avatar
            :name="$mark->user->name"
            :initials="$mark->user->initials()"
            color="auto"
            :color:seed="$mark->user->id"
            circle
            size="sm"
        />

        <div class="min-w-0 flex-1 leading-tight">
            <p class="truncate text-sm font-semibold">{{ $mark->user->name }}</p>
            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                {{ $mark->goal->emoji }} {{ $mark->goal->name }}
                <span aria-hidden="true">·</span>
                <time datetime="{{ $mark->created_at?->toIso8601String() }}">
                    {{ $mark->created_at?->diffForHumans(short: true) }}
                </time>
            </p>
        </div>

        <x-flame :days="$entry->streak" size="sm" />

        <div
            x-data="shareCard({
                imageUrl: @js($entry->photo?->fallbackUrl),
                filename: @js(Str::slug($mark->goal->name) . '-logralo.jpg'),
                text: @js($shareText),
                url: @js(route('today')),
            })"
        >
            <flux:button
                variant="subtle"
                size="sm"
                square
                icon="share"
                aria-label="Compartir"
                x-on:pointerdown="prefetch()"
                x-on:click="share()"
                :loading="false"
                data-test="share"
            />
        </div>
    </header>

    @if ($entry->photo !== null)
        <button
            type="button"
            x-data="{ open: false }"
            x-on:click="open = true"
            class="block w-full"
            aria-label="Ver foto completa"
        >
            <x-photo :links="$entry->photo" :alt="$mark->goal->name" :eager="$eager" />

            {{-- Full-screen viewer, opened in place so the feed keeps its scroll. --}}
            <template x-teleport="body">
                <div
                    x-show="open"
                    x-cloak
                    x-transition.opacity
                    x-on:click="open = false"
                    x-on:keydown.escape.window="open = false"
                    class="fixed inset-0 z-50 grid place-items-center bg-black/95 p-4"
                >
                    <img
                        src="{{ $entry->photo->fallbackUrl }}"
                        alt="{{ $mark->goal->name }}"
                        class="max-h-full max-w-full object-contain"
                    >
                </div>
            </template>
        </button>
    @else
        <div class="mx-3 mb-3 flex items-center gap-3 rounded-xl border border-dashed border-ghost/50 bg-ghost/5 px-4 py-5">
            <span class="text-2xl">🌫️</span>
            <div class="leading-tight">
                <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">marcó sin foto</p>
                @if ($entry->ghostRun > 1)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $entry->ghostRun }}ᵃ vez seguida
                    </p>
                @endif
            </div>
        </div>
    @endif

    @if ($mark->note !== null)
        <p class="px-3 pt-3 text-sm text-zinc-700 dark:text-zinc-200">{{ $mark->note }}</p>
    @endif

    <footer class="flex flex-wrap items-center gap-1.5 px-3 py-3">
        @foreach (ReactionEmoji::cases() as $emoji)
            @php
                $count = $counts->get($emoji->value, 0);
                $mine = $reacted === $emoji;
            @endphp

            <button
                type="button"
                wire:click="react('{{ $mark->id }}', '{{ $emoji->value }}')"
                @class([
                    'tap-target flex items-center gap-1 rounded-full border px-2.5 py-1 text-sm transition active:scale-95',
                    'border-accent/40 bg-accent/15' => $mine,
                    'border-zinc-200 dark:border-white/10' => ! $mine,
                    'opacity-45' => $count === 0 && ! $mine,
                ])
                aria-pressed="{{ $mine ? 'true' : 'false' }}"
                data-test="react-{{ $emoji->value }}"
            >
                <span class="leading-none">{{ $emoji->character() }}</span>
                @if ($count > 0)
                    <span class="text-xs tabular-nums">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </footer>
</article>
