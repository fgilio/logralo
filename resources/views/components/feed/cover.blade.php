@props(['entry', 'eager' => false, 'reacted' => null])

@php
    $mark = $entry->mark;

    // A scrim is only legible over a photograph, so a mark without one wears
    // the card's own colours.
    $onPhoto = $entry->photo !== null;
    $tone = $onPhoto ? 'inverse' : 'default';
    $alt = $mark->user->name . ': ' . $mark->goal->name;
@endphp

<div class="mark-3u relative">
    @if ($onPhoto)
        <x-feed.viewer :links="$entry->photo" :alt="$alt" class="size-full">
            <x-photo :links="$entry->photo" :alt="$alt" :eager="$eager" fill wide />
        </x-feed.viewer>
    @else
        <x-feed.ghost :entry="$entry" size="lg" class="size-full" />
    @endif

    <div
        @class([
            'pointer-events-none absolute inset-x-0 top-0 flex items-center gap-2.5 px-3 pt-3 pb-8',
            'bg-linear-to-b from-black/70 via-black/25 to-transparent text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.45)]' => $onPhoto,
        ])
    >
        <flux:avatar
            :name="$mark->user->name"
            :initials="$mark->user->initials()"
            color="auto"
            :color:seed="$mark->user->id"
            circle
            size="sm"
        />

        <div class="min-w-0 flex-1 leading-tight">
            <p class="truncate text-sm font-semibold">{{ $mark->user->name }}</p>
            <x-feed.goal-line :mark="$mark" :class="$onPhoto ? 'text-white/90' : 'text-zinc-500 dark:text-zinc-400'" />
        </div>

        <x-flame :days="$entry->streak" size="sm" class="shrink-0" />
    </div>

    <div
        @class([
            'pointer-events-none absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 px-3 pt-8 pb-3',
            'bg-linear-to-t from-black/70 via-black/25 to-transparent' => $onPhoto,
        ])
    >
        <x-feed.reactions :mark="$mark" :reacted="$reacted" :tone="$tone" class="pointer-events-auto" />

        <x-feed.share :entry="$entry" :tone="$tone" />
    </div>
</div>

@if ($mark->note !== null)
    <p
        x-data="{ full: false }"
        x-on:pointerdown.stop
        x-on:click="full = ! full"
        class="flex min-h-(--rung-note) items-center px-3 py-1.5 text-note text-zinc-700 select-text dark:text-zinc-200"
    >
        <span :class="full || 'line-clamp-2'">{{ $mark->note }}</span>
    </p>
@endif
