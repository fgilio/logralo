@props(['entry'])

@php
    $mark = $entry->mark;
    $links = $entry->photo;

    // Everything the viewer draws, since the viewer itself is one dialog for
    // the whole feed and cannot be rendered per mark. Strings only: a shared
    // dialog can fill itself from these without a round trip.
    $photo = [
        'srcset' => $links->srcset,
        'src' => $links->fallbackUrl,
        'alt' => $entry->photoAlt(),
        'name' => $mark->user->name,
        'goal' => $mark->goal->emoji . ' ' . $mark->goal->name,
        'when' => $mark->created_at?->diffForHumans(short: true),
        'note' => $mark->note,
    ];
@endphp

<button
    type="button"
    x-data
    x-on:click="$dispatch('photo-viewer-open', @js($photo))"
    {{ $attributes->class(['relative block overflow-hidden focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent']) }}
    aria-label="Ver la foto completa de {{ $entry->photoAlt() }}"
    data-test="viewer-open-{{ $mark->id }}"
>
    {{ $slot }}
</button>
