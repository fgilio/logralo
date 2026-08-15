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
        {{-- The goal and the streak on one line, the way the card the visitor
             just saw in the chat has them. The name used to appear three times
             on this page — as the heading, again under the avatar, and again in
             the photo's alt text — over a picture of the thing itself. --}}
        <div class="flex items-start justify-between gap-4">
            <h1 class="font-display text-3xl tracking-wide">
                {{ $mark->goal->emoji }} {{ $mark->goal->name }}
            </h1>

            {{-- Shown on a first day too, because the card the visitor just
                 tapped says 1 and so does the feed. --}}
            <x-flame :days="$entry->streak" size="lg" class="mt-1 shrink-0" />
        </div>

        <div class="mt-4 flex items-center gap-3">
            <flux:avatar
                :name="$mark->user->name"
                :initials="$mark->user->initials()"
                color="auto"
                :color:seed="$mark->user->id"
                circle
                size="sm"
            />
            <p class="min-w-0 truncate text-sm font-semibold">{{ $mark->user->name }}</p>
        </div>

        {{-- The one line on the page nobody else could have written, so it gets
             the ember rule rather than another paragraph of grey. --}}
        @if (filled($mark->note))
            <p class="mt-4 border-l-2 border-accent/50 pl-3 text-base text-zinc-200 italic">
                {{ $mark->note }}
            </p>
        @endif

        {{-- A tap from WhatsApp becomes a reaction without ever loading the
             feed. This is the only half of the loop the app can close: the
             sharer finds out the message landed. --}}
        <div class="mt-5 border-t border-white/10 pt-4">
            <livewire:share-reactions :mark="$mark" />
        </div>
    </div>
</article>
