@props(['entry', 'reacted' => null])

@php
    $mark = $entry->mark;
@endphp

{{-- 2u: the second most recent mark of the day. A 140px square of photo and a
     column beside it, which stays exactly 2u whether or not there is a note. --}}
<div class="mark-2u flex gap-2.5 p-2.5">
    @if ($entry->photo !== null)
        <x-feed.viewer :links="$entry->photo" :alt="$mark->goal->name" class="size-35 shrink-0 rounded-xl">
            <x-photo :links="$entry->photo" :alt="$mark->goal->name" fill />
        </x-feed.viewer>
    @else
        <x-feed.no-photo :entry="$entry" size="md" class="size-35 shrink-0 rounded-xl" />
    @endif

    <div class="flex min-w-0 flex-1 flex-col justify-center gap-1">
        <div class="flex items-center gap-2">
            <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ $mark->user->name }}</p>
            <x-flame :days="$entry->streak" size="sm" class="shrink-0" />
        </div>

        <x-feed.goal-line :mark="$mark" class="text-zinc-500 dark:text-zinc-400" />

        @if ($mark->note !== null)
            <p class="line-clamp-2 text-[13px] leading-4.5 text-zinc-700 dark:text-zinc-200">{{ $mark->note }}</p>
        @endif

        <div class="flex items-center justify-between gap-2">
            <x-feed.reactions :mark="$mark" :reacted="$reacted" />
            <x-feed.share :entry="$entry" />
        </div>
    </div>
</div>
