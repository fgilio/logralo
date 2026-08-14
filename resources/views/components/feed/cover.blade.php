@props(['entry', 'eager' => false, 'reacted' => null])

@php
    $mark = $entry->mark;

    // White on a scrim is only legible over a photograph. A mark without one
    // keeps the same furniture in the same places, in the card's own colours.
    $onPhoto = $entry->photo !== null;
@endphp

{{-- 3u: the day's most recent mark. The photo fills the card and the furniture
     rides two scrims over it, so the picture is never cropped to make room. --}}
<article
    x-data="reactionPicker({ markId: @js($mark->id) })"
    x-on:pointerdown="press($event)"
    x-on:pointermove="track($event)"
    x-on:pointerup.window="release()"
    x-on:pointercancel="stop()"
    x-on:contextmenu.prevent
    x-on:click.capture="onClick($event)"
    class="tap-target feed-card relative overflow-hidden rounded-2xl bg-white ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10"
    data-test="mark-{{ $mark->id }}" data-height="3"
>
    <div class="mark-3u relative">
        @if ($onPhoto)
            <x-feed.viewer :links="$entry->photo" :alt="$mark->goal->name" class="size-full">
                <x-photo :links="$entry->photo" :alt="$mark->goal->name" :eager="$eager" fill />
            </x-feed.viewer>
        @else
            <x-feed.no-photo :entry="$entry" size="lg" class="size-full rounded-2xl" />
        @endif

        <div
            @class([
                'pointer-events-none absolute inset-x-0 top-0 flex items-center gap-2.5 px-3 pt-3 pb-8',
                'bg-linear-to-b from-black/65 to-transparent text-white' => $onPhoto,
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
                <p @class(['truncate text-xs', 'text-white/75' => $onPhoto, 'text-zinc-500 dark:text-zinc-400' => ! $onPhoto])>
                    {{ $mark->goal->emoji }} {{ $mark->goal->name }}
                    <span aria-hidden="true">·</span>
                    <time datetime="{{ $mark->created_at?->toIso8601String() }}">
                        {{ $mark->created_at?->diffForHumans(short: true) }}
                    </time>
                </p>
            </div>

            <x-flame :days="$entry->streak" size="sm" class="shrink-0" />

            <x-feed.share :entry="$entry" :tone="$onPhoto ? 'scrim' : 'plain'" />
        </div>

        <div
            @class([
                'pointer-events-none absolute inset-x-0 bottom-0 flex justify-end px-3 pt-8 pb-3',
                'bg-linear-to-t from-black/60 to-transparent' => $onPhoto,
            ])
        >
            <x-feed.reactions
                :mark="$mark"
                :reacted="$reacted"
                :tone="$onPhoto ? 'scrim' : 'plain'"
                class="pointer-events-auto"
            />
        </div>
    </div>

    @if ($mark->note !== null)
        {{-- The one place a card's height depends on what is in it: the note
             gets a band of its own rather than a corner of the photo. --}}
        <p class="flex h-11 items-center px-3 text-[13px] leading-4.5 text-zinc-700 dark:text-zinc-200">
            <span class="line-clamp-2">{{ $mark->note }}</span>
        </p>
    @endif

    <x-feed.reaction-bar :mark="$mark" :reacted="$reacted" />
</article>
