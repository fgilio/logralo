@props(['mark', 'reacted' => null, 'interactive' => true, 'summary' => true, 'take' => 3, 'tone' => 'default'])

@use('App\Enums\ReactionEmoji')

@php
    $counts = $summary
        ? $mark->reactions
            ->countBy(fn ($reaction): string => $reaction->emoji->value)
            ->sortDesc()
        : collect();

    // The viewer's own reaction holds its place even when louder ones would
    // push it out, because the ring on it is how the card says you reacted.
    $shown = $counts->keys()->take($take);

    if ($reacted !== null && $counts->has($reacted->value) && ! $shown->contains($reacted->value)) {
        $shown = $shown->take($take - 1)->push($reacted->value);
    }

    $tally = $counts
        ->map(fn (int $count, string $value): string => ReactionEmoji::from($value)->label() . ' ' . $count)
        ->join(', ');

    $inverse = $tone === 'inverse';

    $pill = $inverse
        ? 'bg-black/40 text-white ring-white/15'
        : 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10';

    $chip = $inverse ? 'bg-white/15' : 'bg-white dark:bg-zinc-800';

    $add = $inverse
        ? 'border-white/25 bg-black/40 text-white'
        : 'border-zinc-200 text-zinc-500 dark:border-white/15 dark:text-zinc-400';
@endphp

<div {{ $attributes->class(['flex items-center gap-1.5']) }}>
    @if ($counts->isNotEmpty())
        <span
            class="flex items-center gap-1 rounded-full px-1.5 py-0.5 ring-1 {{ $pill }}"
            role="img"
            aria-label="{{ $mark->reactions->count() }} reacciones: {{ $tally }}"
            data-test="reactions-{{ $mark->id }}"
        >
            <span aria-hidden="true" class="flex -space-x-1">
                @foreach ($shown as $value)
                    @php $emoji = ReactionEmoji::from($value); @endphp

                    <span
                        @class([
                            'grid size-4.5 place-items-center rounded-full text-caption leading-none',
                            $chip,
                            'ring-2 ring-accent' => $reacted === $emoji,
                        ])
                    >
                        {{ $emoji->character() }}
                    </span>
                @endforeach
            </span>

            <span aria-hidden="true" class="text-xs font-medium tabular-nums">{{ $mark->reactions->count() }}</span>
        </span>
    @endif

    @if ($interactive)
        {{-- The ring stays 24px so it fits a row. The finger gets 44. --}}
        <button
            type="button"
            x-on:click="toggle()"
            class="tap-target relative grid size-6 place-items-center rounded-full border transition before:absolute before:-inset-2.5 before:content-[''] active:scale-90 {{ $add }}"
            aria-label="Reaccionar"
            aria-haspopup="true"
            :aria-expanded="showing"
            aria-controls="reactions-bar-{{ $mark->id }}"
            data-test="react-open-{{ $mark->id }}"
        >
            <flux:icon name="plus" variant="micro" />
        </button>
    @endif
</div>
