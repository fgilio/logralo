@php
    $mark = $entry->mark;
@endphp

<article class="overflow-hidden rounded-3xl bg-white/5 ring-1 ring-white/10">
    @if ($entry->photo !== null)
        <x-photo :links="$entry->photo" :alt="$mark->goal->name" eager />
    @else
        <div class="flex aspect-4/3 items-center justify-center bg-ghost/10">
            <span class="text-6xl">🌫️</span>
        </div>
    @endif

    <div class="p-5">
        @if ($entry->streak > 1)
            <p class="font-display text-sm tracking-[0.2em] text-accent uppercase">
                {{ $entry->streak }} días seguidos
            </p>
        @endif

        <h1 class="mt-1 font-display text-3xl tracking-wide">{{ $mark->goal->name }}</h1>

        <div class="mt-4 flex items-center gap-3">
            <flux:avatar
                :name="$mark->user->name"
                :initials="$mark->user->initials()"
                color="auto"
                :color:seed="$mark->user->id"
                circle
                size="sm"
            />
            <div class="min-w-0 leading-tight">
                <p class="truncate text-sm font-semibold">{{ $mark->user->name }}</p>
                <p class="truncate text-xs text-zinc-400">
                    {{ $mark->goal->emoji }} {{ $mark->goal->name }}
                    <span aria-hidden="true">·</span>
                    {{ $entry->day()->translatedFormat('j \d\e F') }}
                </p>
            </div>
        </div>

        @if ($mark->note !== null)
            <p class="mt-4 text-sm text-zinc-300">{{ $mark->note }}</p>
        @endif

        {{-- A tap from WhatsApp becomes a reaction without ever loading the
             feed. This is the only half of the loop the app can close: the
             sharer finds out the message landed. --}}
        <div class="mt-5 border-t border-white/10 pt-4">
            <livewire:share-reactions :mark="$mark" />
        </div>
    </div>
</article>
