@props(['entry', 'tone' => 'default', 'label' => true])

@php
    $mark = $entry->mark;
    $views = $mark->share_views;

    // The 2u column is 146px at its narrowest, which fits neither the word nor
    // the count beside the reaction summary.
    $showsViews = $label && $views > 0 && $mark->isManagedBy(auth()->user());
@endphp

{{-- Holding this opens its own menu, so the press must not reach the card. --}}
<div x-on:pointerdown.stop {{ $attributes->class(['pointer-events-auto flex items-center gap-2']) }}>
    {{-- Only the owner sees it, and only once somebody opened the link. --}}
    @if ($showsViews)
        <span
            @class([
                'text-xs tabular-nums',
                'text-white/80' => $tone === 'inverse',
                'text-zinc-500 dark:text-zinc-400' => $tone !== 'inverse',
            ])
            data-test="share-views"
        >
            {{-- Spelled out rather than inflected, for the reason
                 share/show.blade.php gives. --}}
            <span aria-hidden="true">{{ $views }} {{ $views === 1 ? 'visita' : 'visitas' }}</span>
            <span class="sr-only">
                {{ $views }} {{ $views === 1 ? 'persona abrió' : 'personas abrieron' }} el link que compartiste
            </span>
        </span>
    @endif

    <x-share-button :entry="$entry" :tone="$tone" :label="$label" />
</div>
