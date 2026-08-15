@props(['entry', 'size' => 'lg'])

@php
    $scale = match ($size) {
        'sm' => 'text-2xl',
        'md' => 'text-4xl',
        default => 'text-7xl',
    };

    // A directive glued to the end of a word stays uncompiled while its @endif
    // compiles, so the caption is built here.
    $caption = $entry->ghostRun > 1
        ? "sin foto · {$entry->ghostRun}ᵃ vez seguida"
        : 'sin foto';
@endphp

{{-- The same hatching the standings use for a half-earned point, so the feed
     and the table explain each other. --}}
<div
    {{ $attributes->class(['hatched grid place-content-center justify-items-center gap-1.5 border border-dashed border-ghost/40']) }}
    role="img"
    aria-label="{{ $entry->mark->goal->name }}, {{ $caption }}"
>
    <span class="{{ $scale }} leading-none">{{ $entry->mark->goal->emoji }}</span>

    @if ($size !== 'sm')
        <p
            aria-hidden="true"
            class="max-w-full px-1 text-center text-caption tracking-wide text-zinc-600 uppercase dark:text-zinc-400"
        >
            {{ $caption }}
        </p>
    @endif
</div>
