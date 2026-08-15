@props(['mark', 'reacted' => null, 'interactive' => true, 'summary' => true, 'tone' => 'plain'])

@php
    use App\Enums\ReactionEmoji;

    // Ordered by how many people picked it, so the stack leads with the loudest
    // thing anyone said about the mark. A card that already shows the summary
    // elsewhere — an opened row, whose collapsed line still carries it — asks
    // for the button on its own.
    $counts = $summary
        ? $mark->reactions
            ->groupBy(fn ($reaction): string => $reaction->emoji->value)
            ->map(fn ($group): int => $group->count())
            ->sortDesc()
        : collect();

    $scrim = $tone === 'scrim';

    $pill = $scrim
        ? 'bg-black/40 text-white ring-white/15'
        : 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10';

    $chip = $scrim ? 'bg-white/15' : 'bg-white dark:bg-zinc-800';

    $add = $scrim
        ? 'border-white/25 bg-black/40 text-white'
        : 'border-zinc-200 text-zinc-500 dark:border-white/15 dark:text-zinc-400';
@endphp

{{-- Showing and adding are two different problems. The summary costs about
     20px, which is what lets it survive on a 1u row; adding happens in the bar,
     which is opened from here or by holding the card. --}}
<div {{ $attributes->class(['flex items-center gap-1.5']) }}>
    @if ($counts->isNotEmpty())
        <span
            class="flex items-center gap-1 rounded-full px-1.5 py-0.5 ring-1 {{ $pill }}"
            data-test="reactions-{{ $mark->id }}"
        >
            <span class="flex -space-x-1">
                @foreach ($counts->keys() as $value)
                    @php $emoji = ReactionEmoji::from($value); @endphp

                    <span
                        @class([
                            'grid size-4.5 place-items-center rounded-full text-[11px] leading-none',
                            $chip,
                            'ring-2 ring-accent' => $reacted === $emoji,
                        ])
                    >
                        {{ $emoji->character() }}
                    </span>
                @endforeach
            </span>

            <span class="text-xs font-medium tabular-nums">{{ $mark->reactions->count() }}</span>
        </span>
    @endif

    @if ($interactive)
        <button
            type="button"
            x-on:pointerdown.stop
            x-on:click="toggle()"
            class="tap-target grid size-6 place-items-center rounded-full border transition active:scale-90 {{ $add }}"
            aria-label="Reaccionar"
            data-test="react-open-{{ $mark->id }}"
        >
            <flux:icon name="plus" variant="micro" />
        </button>
    @endif
</div>
