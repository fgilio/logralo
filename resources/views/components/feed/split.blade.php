@props(['entry', 'reacted' => null])

@php
    $mark = $entry->mark;

    // Below 360px the column needs the 28px back, or the reaction summary and
    // the share button do not fit beside it.
    $box = 'size-35 shrink-0 self-center rounded-xl max-[359px]:size-28';
@endphp

<div class="mark-2u flex gap-2.5 p-2.5">
    @if ($entry->photo !== null)
        <x-feed.viewer :links="$entry->photo" :alt="$mark->user->name . ': ' . $mark->goal->name" class="{{ $box }}">
            <x-photo :links="$entry->photo" :alt="$mark->goal->name" fill sizes="(max-width: 359px) 112px, 140px" />
        </x-feed.viewer>
    @else
        <x-feed.ghost :entry="$entry" size="md" class="{{ $box }}" />
    @endif

    <div class="flex min-w-0 flex-1 flex-col justify-center gap-1">
        <div class="flex items-center gap-2">
            <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ $mark->user->name }}</p>
            <x-flame :days="$entry->streak" size="sm" class="shrink-0" />
        </div>

        <x-feed.goal-line :mark="$mark" class="text-zinc-500 dark:text-zinc-400" />

        @if ($mark->note !== null)
            <p class="line-clamp-2 text-note text-zinc-700 select-text dark:text-zinc-200">{{ $mark->note }}</p>
        @endif

        <div class="flex items-center justify-between gap-2">
            <x-feed.reactions :mark="$mark" :reacted="$reacted" />
            <x-feed.share :entry="$entry" :label="false" />
        </div>
    </div>
</div>
