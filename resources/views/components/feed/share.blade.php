@props(['entry', 'tone' => 'plain'])

@php
    $mark = $entry->mark;
    $isOwner = $mark->isManagedBy(auth()->user());
@endphp

{{-- The press never reaches the card: holding the share button opens its own
     menu, and that hold is not a reaction gesture. --}}
<div x-on:pointerdown.stop {{ $attributes->class(['pointer-events-auto flex items-center gap-2']) }}>
    {{-- Only the person who shared it sees the count, and only once somebody
         has actually opened the link. It is there to tell them the message
         landed, not to turn the feed into a leaderboard of who gets clicked. --}}
    @if ($isOwner && $mark->share_views > 0)
        <span
            @class([
                'text-xs tabular-nums',
                'text-white/60' => $tone === 'scrim',
                'text-zinc-400 dark:text-zinc-500' => $tone !== 'scrim',
            ])
            title="Gente que abrió el link que compartiste"
            data-test="share-views"
        >
            {{-- Spelled out rather than inflected, for the reason
                 share/show.blade.php gives. --}}
            {{ $mark->share_views }} {{ $mark->share_views === 1 ? 'visita' : 'visitas' }}
        </span>
    @endif

    <x-share-button :entry="$entry" :tone="$tone === 'scrim' ? 'inverse' : 'default'" />
</div>
