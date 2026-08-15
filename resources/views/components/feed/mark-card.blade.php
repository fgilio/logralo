@props(['entry', 'rung' => 1, 'eager' => false, 'reacted' => null])

@php
    $mark = $entry->mark;

    // The 3u note band is the one place a card is taller than its rung.
    $height = match ($rung) {
        3 => $mark->note !== null ? 'calc(var(--rung-3) + var(--rung-note))' : 'var(--rung-3)',
        2 => 'var(--rung-2)',
        default => 'var(--rung-1)',
    };
@endphp

{{-- The id is what a shared link lands on: /#mark-{id} scrolls here and :target
     lights the card up, so arriving from WhatsApp puts you on the photo you
     were sent rather than at the top of the feed. --}}
<article
    id="mark-{{ $mark->id }}"
    x-data="reactionPicker({ markId: @js($mark->id) })"
    x-on:pointerdown="start($event)"
    x-on:pointermove="move($event)"
    x-on:pointerup.window="end($event)"
    x-on:pointercancel="cancel()"
    x-on:lostpointercapture="cancel()"
    x-on:contextmenu.prevent
    x-on:click.capture="onClick($event)"
    x-on:reaction-bar-opened.window="$event.detail !== markId && close()"
    {{ $attributes->class(['tap-target feed-card relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10']) }}
    {{-- The compositor decides at the first move whether it is panning, and
         nothing after that wins the gesture back. Vertical is the page's,
         sideways along the bar is the picker's. --}}
    style="--card-height: {{ $height }}; touch-action: pan-y"
    data-rung="{{ $rung }}"
>
    @if ($rung === 3)
        <x-feed.cover :entry="$entry" :eager="$eager" :reacted="$reacted" />
    @elseif ($rung === 2)
        <x-feed.split :entry="$entry" :reacted="$reacted" />
    @else
        <x-feed.row :entry="$entry" :reacted="$reacted" />
    @endif

    <x-feed.reaction-bar :mark="$mark" :reacted="$reacted" />
</article>
