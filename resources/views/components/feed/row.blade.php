@props(['entry', 'reacted' => null])

@php
    $mark = $entry->mark;
@endphp

{{-- 1u: everything else that day. Two lines beside a 52px thumbnail, and the
     photo one tap away without leaving the feed. --}}
<article
    x-data="reactionPicker({ markId: @js($mark->id) })"
    x-on:pointerdown="press($event)"
    x-on:pointermove="track($event)"
    x-on:pointerup.window="release()"
    x-on:pointercancel="stop()"
    x-on:contextmenu.prevent
    x-on:click.capture="onClick($event)"
    class="tap-target feed-card relative overflow-hidden rounded-2xl bg-white ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10"
    style="--card-height: 76px"
    data-test="mark-{{ $mark->id }}" data-height="1"
>
    <div x-data="{ expanded: false }">
        <div
            class="mark-1u flex items-center gap-3 px-3"
            role="button"
            tabindex="0"
            x-on:click="expanded = ! expanded"
            x-on:keydown.enter.prevent="expanded = ! expanded"
            x-on:keydown.space.prevent="expanded = ! expanded"
            :aria-expanded="expanded"
            aria-label="{{ $mark->user->name }}: {{ $mark->goal->name }}"
        >
            @if ($entry->photo !== null)
                <img
                    src="{{ $entry->photo->thumbnailUrl }}"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="size-13 shrink-0 rounded-lg object-cover"
                >
            @else
                <x-feed.no-photo :entry="$entry" size="sm" class="size-13 shrink-0 rounded-lg" />
            @endif

            <div class="min-w-0 flex-1 leading-tight">
                <p class="truncate text-sm">
                    <span class="font-semibold">{{ $mark->user->name }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400">
                        <span aria-hidden="true">·</span>
                        {{ $mark->goal->emoji }} {{ $mark->goal->name }}
                    </span>
                </p>

                {{-- The note takes the second line, and the time takes it when
                     there is no note. --}}
                <p @class(['truncate text-xs text-zinc-500 dark:text-zinc-400', 'italic' => $mark->note !== null])>
                    @if ($mark->note !== null)
                        {{ $mark->note }}
                    @else
                        <time datetime="{{ $mark->created_at?->toIso8601String() }}">
                            {{ $mark->created_at?->diffForHumans(short: true) }}
                        </time>
                    @endif
                </p>
            </div>

            <x-feed.reactions :mark="$mark" :reacted="$reacted" :interactive="false" class="shrink-0" />

            <x-flame :days="$entry->streak" size="sm" class="shrink-0" />
        </div>

        {{-- Opened in place: the photo, the whole note, and the ＋ that a row has
             no room for. --}}
        <div x-show="expanded" x-collapse x-cloak class="px-3 pb-3">
            @if ($entry->photo !== null)
                <x-feed.viewer :links="$entry->photo" :alt="$mark->goal->name" class="w-full rounded-xl">
                    <x-photo :links="$entry->photo" :alt="$mark->goal->name" />
                </x-feed.viewer>
            @else
                <x-feed.no-photo :entry="$entry" size="md" class="h-40 w-full rounded-xl" />
            @endif

            @if ($mark->note !== null)
                <p class="mt-2 text-[13px] leading-4.5 text-zinc-700 dark:text-zinc-200">{{ $mark->note }}</p>
            @endif

            <div class="mt-2 flex items-center justify-between gap-2">
                <x-feed.reactions :mark="$mark" :reacted="$reacted" />
                <x-feed.share :entry="$entry" />
            </div>
        </div>
    </div>

    <x-feed.reaction-bar :mark="$mark" :reacted="$reacted" />
</article>
