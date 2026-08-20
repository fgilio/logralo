{{-- The photo, full screen.

     One for the feed rather than one per card, for the reason the milestone
     sheet gives in `pages/today.blade.php`: a page of twenty cards carrying
     twenty idle dialogs is twenty custom elements, forty document listeners
     and some sixty kilobytes of markup — re-serialised and morphed on every
     reaction tap, since the feed re-renders whole. So the card sends what it
     has instead, which is why the photo, the name, the flame and the tally
     here are drawn by Alpine rather than by Blade.

     The thread underneath is the one exception, and it is a Livewire component
     for exactly the reason the rest is not: comments change after the card was
     drawn, so they cannot travel in a payload, and one copy per card is the
     weight this dialog exists to avoid. It renders once and is filled when a
     photo opens.

     The avatar still stays on the card. It is a server-side chain of upload,
     then Gravatar, then initials, and it is not the reason anybody opened the
     photo. --}}

@use('App\Enums\ReactionEmoji')

<flux:modal
    name="foto"
    variant="bare"
    {{-- The ground is on the dialog as well as on the column inside it: while
         a keyboard is up the column is cut to what is still visible, and what
         it stops covering is this. --}}
    class="fixed inset-0 m-0 h-dvh w-full max-h-none max-w-none overflow-hidden bg-zinc-950"
>
    <div
        {{-- The five characters come from the enum rather than from a copy in
             JavaScript: `ReactionEmoji` says the emoji are changeable, and a
             second list is how that stops being true. --}}
        x-data="photoViewer({ characters: {{ Js::from(collect(ReactionEmoji::cases())->mapWithKeys(fn (ReactionEmoji $emoji): array => [$emoji->value => $emoji->character()])) }} })"
        x-on:photo-viewer-open.window="show($event.detail)"
        {{-- The drag is the picture's, so nothing springs back at a fraction
             of the speed the finger moved. --}}
        :class="dragging || 'transition duration-200 ease-out'"
        :style="carried"
        {{-- Cut to the screen as it is actually visible rather than to the
             window, and slid down with it: a keyboard shrinks one and leaves
             the other alone, and a dialog that ignores the difference puts the
             comment box behind the keys. `viewport.js` publishes both, and the
             fallbacks are what is true until it has anything to say. The
             `translate` property is the drag's `transform`'s neighbour rather
             than its rival, so the two compose. --}}
        class="flex h-[var(--viewport-height,100%)] translate-y-[var(--viewport-top,0px)] flex-col bg-zinc-950 text-white"
    >
        <header class="flex shrink-0 items-center gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-2">
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

        {{-- The one direction that closes, said without words. A grip at the
             top of a surface you pull upward is the affordance; the sentence
             under it is for whoever cannot see the grip. --}}
        <div class="mx-auto h-1 w-9 shrink-0 rounded-full bg-white/25" aria-hidden="true" data-test="viewer-grip"></div>
        <p class="sr-only">Deslizá la foto hacia arriba para cerrarla.</p>

        <figure class="flex min-h-0 flex-1 flex-col">
            {{-- The gesture belongs to the picture and stops there: the note
                 below it can be selected and the thread under that scrolls,
                 and neither is possible inside `touch-action: none` under a
                 captured pointer. The picture fills the space around the
                 photo, so the letterbox is part of the same surface.

                 Giving the drag away costs nothing the page needs — it is
                 locked behind the dialog while this is open. `tap-target`
                 comes for the tap flash it turns off; the inline
                 `touch-action` is what overrides its `manipulation`. The
                 callout, the selection and the browser's own drag — which
                 would take the pointer mid-gesture — are already off for
                 every picture in the app, in `app.css` and `protect-media.js`.

                 Nothing is fetched until a card hands the srcset over, so the
                 feed downloads its thumbnails and no full-size photo behind
                 a viewer nobody opened. --}}
            <picture
                x-on:pointerdown="start($event)"
                x-on:pointermove="move($event)"
                x-on:pointerup="end($event)"
                x-on:pointercancel="cancel()"
                {{-- The tap closes here rather than on the pointer, so the
                     click it owes is spent on the picture instead of on the
                     card the picture was covering. --}}
                x-on:click="tap()"
                class="tap-target flex min-h-0 flex-1 items-center justify-center"
                style="touch-action: none"
                data-test="viewer-photo"
            >
                <source type="image/webp" :srcset="photo.srcset" sizes="100vw">
                <img
                    :src="photo.src"
                    :alt="photo.alt"
                    decoding="async"
                    class="max-h-full max-w-full object-contain"
                >
            </picture>

            {{-- What the member typed when they marked it, ranged left under
                 the photo and close to it: it is a caption, and a caption that
                 floats centred in its own air reads as a pull quote. --}}
            <figcaption
                x-show="photo.note"
                x-cloak
                x-text="photo.note"
                class="max-h-20 shrink-0 overflow-y-auto px-4 pt-1.5 text-note text-white/90 select-text"
                data-test="viewer-note"
            ></figcaption>
        </figure>

        {{-- The streak and the tally sit a hair apart, because they are one
             reading of the same card: how long this has been going, and who
             said something about it. --}}
        <div class="relative flex shrink-0 items-center gap-1 px-4 pt-2 pb-2.5">
            <span
                class="inline-flex items-baseline gap-1 rounded-full bg-white/10 px-2.5 py-1 tabular-nums"
                role="img"
                :aria-label="`Racha de ${photo.streak} ${photo.streak === 1 ? 'día' : 'días'}`"
            >
                <span class="font-display text-sm leading-none" x-text="photo.streak"></span>
                <x-brand-mark class="size-3.5 translate-y-px self-center" />
            </span>

            <button
                type="button"
                x-show="total > 0"
                x-cloak
                x-on:click="picking = ! picking"
                class="tap-target inline-flex items-center gap-1.5 rounded-full bg-white/10 py-1 pr-2.5 pl-1.5 transition active:scale-95"
                :aria-label="`${total} ${total === 1 ? 'reacción' : 'reacciones'}`"
                data-test="viewer-tally"
            >
                <span aria-hidden="true" class="flex -space-x-1">
                    <template x-for="emoji in faces" :key="emoji">
                        <span
                            class="grid size-5 place-items-center rounded-full bg-white/20 text-caption leading-none"
                            :class="emoji === reacted && 'ring-2 ring-accent'"
                            x-text="characters[emoji]"
                        ></span>
                    </template>
                </span>

                <span aria-hidden="true" class="text-xs font-medium tabular-nums" x-text="total"></span>
            </button>

            <button
                type="button"
                x-on:click="picking = ! picking"
                class="tap-target ml-auto grid size-8 shrink-0 place-items-center rounded-full border border-white/25 bg-white/5 transition active:scale-90"
                :class="picking && 'border-accent bg-accent text-accent-foreground'"
                aria-label="Reaccionar"
                aria-haspopup="true"
                :aria-expanded="picking"
                aria-controls="viewer-reactions"
                data-test="viewer-react-open"
            >
                <flux:icon name="plus" variant="micro" />
            </button>

            {{-- Above the row it is opened from, so the thumb that reaches the
                 ＋ does not cover the five it just asked for. --}}
            <div
                x-show="picking"
                x-cloak
                x-transition
                x-on:click.outside="picking = false"
                x-on:keydown.escape.window="picking = false"
                id="viewer-reactions"
                class="absolute inset-x-4 bottom-full z-10 mb-2 flex items-center justify-between rounded-full bg-zinc-900/95 p-1 shadow-lg ring-1 ring-white/10 backdrop-blur"
                role="group"
                aria-label="Reaccionar"
            >
                @foreach (ReactionEmoji::cases() as $emoji)
                    <button
                        type="button"
                        x-on:click="choose('{{ $emoji->value }}')"
                        class="tap-target grid size-11 place-items-center rounded-full text-xl leading-none transition duration-100"
                        :class="reacted === '{{ $emoji->value }}' && 'bg-accent/25 ring-1 ring-accent'"
                        aria-label="{{ $emoji->label() }}"
                        :aria-pressed="reacted === '{{ $emoji->value }}'"
                        data-test="viewer-react-{{ $emoji->value }}"
                    >
                        <span aria-hidden="true">{{ $emoji->character() }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Keyed, because this is a child of the feed and the feed re-renders
             on every reaction — including the one tapped in here. Without a
             key Livewire mounts a new instance on each parent render, and the
             thread this one was told to load goes with the old one. --}}
        <livewire:photo-comments wire:key="photo-comments" />
    </div>
</flux:modal>
