@props(['entry', 'tone' => 'plain'])

@php
    $mark = $entry->mark;

    $shareText = $entry->streak > 1
        ? "🔥 {$entry->streak} días seguidos de {$mark->goal->name} — Logralo"
        : "🔥 {$mark->goal->name} — Logralo";
@endphp

{{-- The press never reaches the card: holding the share button is not a
     reaction gesture. --}}
<div
    x-data="shareCard({
        imageUrl: @js($entry->photo?->fallbackUrl),
        filename: @js(Str::slug($mark->goal->name) . '-logralo.jpg'),
        text: @js($shareText),
        url: @js(route('today')),
    })"
    x-on:pointerdown.stop="prefetch()"
    {{ $attributes->class(['pointer-events-auto']) }}
>
    <flux:button
        variant="subtle"
        size="sm"
        square
        icon="share"
        aria-label="Compartir"
        x-on:click="share()"
        :class="$tone === 'scrim' ? 'text-white!' : null"
        data-test="share"
    />
</div>
