{{-- The photo, full screen.

     One for the feed rather than one per card, for the reason the milestone
     sheet gives in `pages/today.blade.php`: a page of twenty cards carrying
     twenty idle dialogs is twenty custom elements, forty document listeners
     and some sixty kilobytes of markup — re-serialised and morphed on every
     reaction tap, since the feed re-renders whole. So the card sends what it
     has instead, which is why nothing in here is drawn by Blade.

     The avatar and the flame stay on the card. They are what a shared dialog
     cannot carry: an avatar is a server-side chain of upload, then Gravatar,
     then initials, and neither is the reason anybody opened the photo. --}}
<flux:modal
    name="foto"
    variant="bare"
    class="fixed inset-0 m-0 h-dvh w-full max-h-none max-w-none overflow-hidden"
>
    <div
        x-data="photoViewer()"
        x-on:photo-viewer-open.window="show($event.detail)"
        {{-- The drag is the picture's, so nothing springs back at a fraction
             of the speed the finger moved. --}}
        :class="dragging || 'transition duration-200 ease-out'"
        :style="carried"
        class="flex h-full flex-col bg-zinc-950 text-white"
    >
        <header class="flex items-center gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3">
            <div class="min-w-0 flex-1 leading-tight">
                <p class="truncate text-sm font-semibold" x-text="photo.name"></p>
                <p class="truncate text-xs text-white/70">
                    <span x-text="photo.goal"></span>
                    <span aria-hidden="true">·</span>
                    <span x-text="photo.when"></span>
                </p>
            </div>

            <button
                type="button"
                x-on:click="dismiss()"
                class="tap-target grid size-11 shrink-0 place-items-center rounded-full bg-white/10 transition active:scale-90"
                aria-label="Cerrar"
                data-test="viewer-close"
            >
                <flux:icon name="x-mark" variant="micro" />
            </button>
        </header>

        <figure class="flex min-h-0 flex-1 flex-col">
            {{-- The gesture belongs to the picture and stops there: the note
                 below it scrolls and can be selected, and neither is possible
                 inside `touch-action: none` under a captured pointer. The
                 picture fills the space around the photo, so the letterbox is
                 part of the same surface.

                 Giving the drag away costs nothing the page needs — it is
                 locked behind the dialog while this is open. `tap-target`
                 comes for the callout and the selection it turns off; the
                 inline `touch-action` is what overrides its `manipulation`.

                 Nothing is fetched until a card hands the srcset over, so the
                 feed downloads its thumbnails and no full-size photo behind
                 a viewer nobody opened. --}}
            <picture
                x-on:pointerdown="start($event)"
                x-on:pointermove="move($event)"
                x-on:pointerup="end($event)"
                x-on:pointercancel="cancel()"
                class="tap-target flex min-h-0 flex-1 items-center justify-center"
                style="touch-action: none"
                data-test="viewer-photo"
            >
                <source type="image/webp" :srcset="photo.srcset" sizes="100vw">
                {{-- A picture is draggable by default, and the browser's own
                     drag would take the pointer mid-gesture. --}}
                <img
                    :src="photo.src"
                    :alt="photo.alt"
                    decoding="async"
                    draggable="false"
                    class="max-h-full max-w-full object-contain"
                >
            </picture>

            <figcaption
                x-show="photo.note"
                x-cloak
                x-text="photo.note"
                class="max-h-32 shrink-0 overflow-y-auto px-5 pt-4 text-center text-note text-white/90 select-text"
                data-test="viewer-note"
            ></figcaption>
        </figure>

        <p
            aria-hidden="true"
            class="shrink-0 px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] text-center text-caption tracking-wide text-white/40 uppercase"
        >
            Tocá o deslizá para cerrar
        </p>
    </div>
</flux:modal>
