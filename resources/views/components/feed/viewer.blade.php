@props(['entry'])

@php
    $mark = $entry->mark;
    $links = $entry->photo;
    $alt = $mark->user->name . ': ' . $mark->goal->name;

    // One dialog per mark, and a card renders exactly one photo, so the mark's
    // own id is the name Flux opens it by.
    $name = 'foto-' . $mark->id;
@endphp

<button
    type="button"
    x-data
    x-on:click="$flux.modal('{{ $name }}').show()"
    {{ $attributes->class(['relative block overflow-hidden focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent']) }}
    aria-label="Ver la foto completa de {{ $alt }}"
    data-test="viewer-open-{{ $mark->id }}"
>
    {{ $slot }}
</button>

{{-- The dialog goes to the top layer the moment it opens, so what stands here
     is only its placeholder. It is taken out of flow because a 2u card lays
     its photo out in a flex row, where an element of no size still takes a
     gap of its own. --}}
<div class="absolute">
    {{-- `bare` leaves the dialog to us: no panel, no padding, and no close
         button of Flux's own in the corner where the photo goes. --}}
    <flux:modal
        :name="$name"
        variant="bare"
        class="fixed inset-0 m-0 h-dvh w-full max-h-none max-w-none overflow-hidden"
    >
        <div
            x-data="photoViewer({ name: @js($name) })"
            {{-- The drag is the picture's, so nothing springs back at a
                 fraction of the speed the finger moved. --}}
            :class="dragging || 'transition duration-200 ease-out'"
            :style="travelled && `transform: translate(${x}px, ${y}px); opacity: ${faded}`"
            class="flex h-full flex-col bg-zinc-950 text-white"
        >
            <header class="flex items-center gap-2.5 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3">
                <x-avatar :user="$mark->user" size="sm" />

                <div class="min-w-0 flex-1 leading-tight">
                    <p class="truncate text-sm font-semibold">{{ $mark->user->name }}</p>
                    <x-feed.goal-line :mark="$mark" class="text-white/70" />
                </div>

                <x-flame :days="$entry->streak" size="sm" class="shrink-0" />

                <button
                    type="button"
                    x-on:click="dismiss()"
                    class="tap-target grid size-11 shrink-0 place-items-center rounded-full bg-white/10 transition active:scale-90"
                    aria-label="Cerrar"
                    data-test="viewer-close-{{ $mark->id }}"
                >
                    <flux:icon name="x-mark" variant="micro" />
                </button>
            </header>

            <figure class="flex min-h-0 flex-1 flex-col justify-center">
                {{-- The gesture belongs to the picture and stops there: the
                     note below it scrolls and can be selected, and neither is
                     possible inside `touch-action: none` under a captured
                     pointer. The picture fills the space around the photo, so
                     the letterbox is part of the same surface.

                     Giving the drag away costs nothing the page needs — it is
                     locked behind the dialog while this is open.

                     The dialog is display:none until it opens, and a lazy image
                     inside one is never fetched, so a feed of cards does not
                     download every photo at full size to sit unseen. --}}
                <picture
                    x-on:pointerdown="start($event)"
                    x-on:pointermove="move($event)"
                    x-on:pointerup="end($event)"
                    x-on:pointercancel="cancel()"
                    class="tap-target flex min-h-0 flex-1 items-center justify-center"
                    style="touch-action: none"
                    data-test="viewer-photo-{{ $mark->id }}"
                >
                    <source type="image/webp" srcset="{{ $links->srcset }}" sizes="100vw">
                    {{-- A picture is draggable by default, and the browser's
                         own drag would take the pointer mid-gesture. --}}
                    <img
                        src="{{ $links->fallbackUrl }}"
                        alt="{{ $alt }}"
                        loading="lazy"
                        decoding="async"
                        draggable="false"
                        class="max-h-full max-w-full object-contain"
                    >
                </picture>

                @if ($mark->note !== null)
                    <figcaption
                        class="max-h-32 shrink-0 overflow-y-auto px-5 pt-4 text-center text-note text-white/90 select-text"
                        data-test="viewer-note-{{ $mark->id }}"
                    >
                        {{ $mark->note }}
                    </figcaption>
                @endif
            </figure>

            <p
                aria-hidden="true"
                class="shrink-0 px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-center text-caption tracking-wide text-white/40 uppercase"
            >
                Tocá o deslizá para cerrar
            </p>
        </div>
    </flux:modal>
</div>
