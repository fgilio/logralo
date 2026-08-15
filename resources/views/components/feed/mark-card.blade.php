@props(['entry', 'height' => 1, 'eager' => false, 'reacted' => null])

@php
    $mark = $entry->mark;

    // The rung this card stands on, in pixels, so the browser's first guess at
    // a card it has not painted yet matches the ladder. The 3u note band is the
    // one place a card is taller than its rung.
    $rung = match ($height) {
        3 => $mark->note !== null ? 244 + 44 : 244,
        2 => 160,
        default => 76,
    };
@endphp

{{-- The ladder decides the shape, not the content: inside a day the newest mark
     is the cover, the one behind it is the split card, and everything older is
     a row. The frame and the hold-to-react gesture are the same at all three,
     so only what goes inside changes.

     The id is what a shared link lands on: /#mark-{id} scrolls here and :target
     lights the card up, so arriving from WhatsApp puts you on the photo you
     were sent rather than at the top of the feed. --}}
<article
    id="mark-{{ $mark->id }}"
    x-data="reactionPicker({ markId: @js($mark->id) })"
    x-on:pointerdown="press($event)"
    x-on:pointermove="track($event)"
    x-on:pointerup.window="release()"
    x-on:pointercancel="cancel()"
    x-on:contextmenu.prevent
    x-on:click.capture="onClick($event)"
    class="tap-target feed-card relative overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10"
    style="--card-height: {{ $rung }}px"
    data-test="mark-{{ $mark->id }}"
    data-height="{{ $height }}"
>
    @if ($height === 3)
        <x-feed.cover :entry="$entry" :eager="$eager" :reacted="$reacted" />
    @elseif ($height === 2)
        <x-feed.split :entry="$entry" :reacted="$reacted" />
    @else
        <x-feed.row :entry="$entry" :reacted="$reacted" />
    @endif

    <x-feed.reaction-bar :mark="$mark" :reacted="$reacted" />
</article>
