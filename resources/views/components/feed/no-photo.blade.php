@props(['entry', 'size' => 'lg'])

@php
    $emoji = match ($size) {
        'sm' => 'text-2xl',
        'md' => 'text-4xl',
        default => 'text-6xl',
    };
@endphp

{{-- A mark without a photo is still a mark: the goal's own emoji stands in for
     the picture, on ghost-tinted ground, rather than the grey apology this used
     to be. --}}
<div
    {{ $attributes->class(['grid place-content-center justify-items-center gap-1.5 border border-dashed border-ghost/40 bg-ghost/10']) }}
    role="img"
    aria-label="{{ $entry->mark->goal->name }}, sin foto"
>
    <span class="{{ $emoji }} leading-none">{{ $entry->mark->goal->emoji }}</span>

    @if ($size !== 'sm')
        <p class="text-[11px] tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
            sin foto@if ($entry->ghostRun > 1) · {{ $entry->ghostRun }}ᵃ seguida @endif
        </p>
    @endif
</div>
