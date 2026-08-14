@props(['entry', 'reacted' => null])

@php
    $mark = $entry->mark;
@endphp

{{-- 2u: the second most recent mark of the day. A 140px square of photo and a
     column beside it, which stays exactly 2u whether or not there is a note. --}}
<article
    x-data="reactionPicker({ markId: @js($mark->id) })"
    x-on:pointerdown="press($event)"
    x-on:pointermove="track($event)"
    x-on:pointerup.window="release()"
    x-on:pointercancel="stop()"
    x-on:contextmenu.prevent
    x-on:click.capture="onClick($event)"
    class="tap-target feed-card mark-2u relative flex gap-2.5 overflow-hidden rounded-2xl bg-white p-2.5 ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10"
    style="--card-height: 160px"
    data-test="mark-{{ $mark->id }}" data-height="2"
>
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

        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
            {{ $mark->goal->emoji }} {{ $mark->goal->name }}
            <span aria-hidden="true">·</span>
            <time datetime="{{ $mark->created_at?->toIso8601String() }}">
                {{ $mark->created_at?->diffForHumans(short: true) }}
            </time>
        </p>

        @if ($mark->note !== null)
            <p class="line-clamp-2 text-[13px] leading-4.5 text-zinc-700 dark:text-zinc-200">{{ $mark->note }}</p>
        @endif

        <div class="flex items-center justify-between gap-2">
            <x-feed.reactions :mark="$mark" :reacted="$reacted" />
            <x-feed.share :entry="$entry" />
        </div>
    </div>

    <x-feed.reaction-bar :mark="$mark" :reacted="$reacted" />
</article>
