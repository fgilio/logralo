@props(['entry'])

@php
    $mark = $entry->mark;
    $links = $entry->photo;

    // Everything the viewer draws, since the viewer itself is one dialog for
    // the whole feed and cannot be rendered per mark. Scalars and one small
    // tally: a shared dialog can fill itself from these without a round trip.
    //
    // The thread is deliberately not here. Comments change after the card was
    // drawn, and one copy per card is the weight the shared dialog exists to
    // avoid — so the viewer asks for those when it opens.
    $photo = [
        'markId' => $mark->id,
        'srcset' => $links->srcset,
        'src' => $links->fallbackUrl,
        'alt' => $entry->photoAlt(),
        'name' => $mark->user->name,
        'goal' => $mark->goal->emoji . ' ' . $mark->goal->name,
        'when' => $mark->created_at?->diffForHumans(short: true),
        'note' => $mark->note,
        // The flame this card carries: the streak that ended on its own day.
        'streak' => $entry->streak,
        // Both read off the reactions already eager-loaded for the card, so
        // neither costs a query.
        'reactions' => $mark->reactions
            ->countBy(fn ($reaction): string => $reaction->emoji->value)
            ->all(),
        'reacted' => $mark->reactions->firstWhere('user_id', auth()->id())?->emoji->value,
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
