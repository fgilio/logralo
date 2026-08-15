@props(['entry', 'reacted' => null])

@php
    $mark = $entry->mark;
    $box = 'size-13 shrink-0 overflow-hidden rounded-lg';
@endphp

<div x-data="{ expanded: false }">
    <button
        type="button"
        class="mark-1u tap-target flex w-full items-center gap-3 px-3 text-left transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent active:bg-zinc-50 dark:active:bg-white/5"
        x-on:click="expanded = ! expanded"
        :aria-expanded="expanded"
        aria-controls="mark-{{ $mark->id }}-detail"
    >
        @if ($entry->photo !== null)
            <div class="relative {{ $box }}">
                <x-photo :links="$entry->photo" alt="" fill sizes="52px" />
            </div>
        @else
            <x-feed.ghost :entry="$entry" size="sm" class="{{ $box }}" />
        @endif

        <div class="min-w-0 flex-1 leading-tight">
            {{-- Two truncations rather than one: sharing it lets the goal, which
                 is the content, disappear before the name. --}}
            <p class="flex min-w-0 items-baseline gap-1 text-sm">
                <span class="max-w-24 shrink-0 truncate font-semibold">{{ $mark->user->name }}</span>
                <span aria-hidden="true" class="shrink-0 text-zinc-400">·</span>
                <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">
                    {{ $mark->goal->emoji }} {{ $mark->goal->name }}
                </span>
            </p>

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

        <x-feed.reactions :mark="$mark" :reacted="$reacted" :interactive="false" :take="2" class="shrink-0" />

        <x-flame :days="$entry->streak" size="sm" class="shrink-0" />

        <flux:icon
            name="chevron-down"
            variant="micro"
            class="shrink-0 text-zinc-300 transition-transform dark:text-zinc-600"
            ::class="expanded && 'rotate-180'"
        />
    </button>

    <div id="mark-{{ $mark->id }}-detail" x-show="expanded" x-collapse x-cloak class="px-3 pb-3">
        @if ($entry->photo !== null)
            <x-feed.viewer :links="$entry->photo" :alt="$mark->user->name . ': ' . $mark->goal->name" class="w-full rounded-xl">
                <x-photo :links="$entry->photo" :alt="$mark->goal->name" />
            </x-feed.viewer>
        @else
            <x-feed.ghost :entry="$entry" size="md" class="h-40 w-full rounded-xl" />
        @endif

        @if ($mark->note !== null)
            <p class="mt-2 text-note text-zinc-700 select-text dark:text-zinc-200">{{ $mark->note }}</p>
        @endif

        {{-- The collapsed line above already carries the summary. --}}
        <div class="mt-2 flex items-center justify-between gap-2">
            <x-feed.reactions :mark="$mark" :reacted="$reacted" :summary="false" />
            <x-feed.share :entry="$entry" />
        </div>
    </div>
</div>
