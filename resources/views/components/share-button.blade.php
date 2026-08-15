@props(['entry', 'tone' => 'default', 'label' => true])

@php
    use App\Enums\ShareCardFormat;

    $shareUrl = $entry->shareUrl();
    $shareable = $entry->shareable();
    $shareText = $entry->shareText();

    // Slugged with the suffix attached rather than glued on after: the message
    // is whatever the member typed, so it can slug to nothing at all — "🔥🔥🔥"
    // is a perfectly good note — and "-logralo.jpg" is not a filename to hand
    // somebody's photo roll.
    $filename = Str::slug(Str::limit($shareText, 40, '') . ' logralo') . '.jpg';
@endphp

@if ($shareUrl === null)
    {{-- Revoked. Only the person who pulled it sees the way back, and taking
         it back gets a new token so the link they killed stays dead. --}}
    @if ($shareable->isManagedBy(auth()->user()))
        <button
            type="button"
            wire:click="resumeSharing('{{ $entry->shareKind() }}', '{{ $shareable->getKey() }}')"
            @class([
                'tap-target flex items-center gap-1.5 rounded-full border border-dashed py-1 text-xs font-medium transition active:scale-95',
                'px-3' => $label,
                'px-1.5' => ! $label,
                'border-zinc-300 text-zinc-500 dark:border-white/15 dark:text-zinc-400' => $tone === 'default',
                'border-white/20 text-white/60' => $tone === 'inverse',
            ])
            aria-label="Compartir de nuevo"
            data-test="share-resume"
        >
            <flux:icon name="arrow-path" variant="micro" />
            @if ($label)
                Compartir de nuevo
            @endif
        </button>
    @endif
@else
    <div
        class="relative"
        x-data="shareCard({
            url: @js($shareUrl),
            text: @js($shareText),
            cardUrl: @js($shareable->shareCardUrl(ShareCardFormat::Unfurl)),
            imageUrl: @js($shareable->shareCardUrl(ShareCardFormat::Portrait)),
            filename: @js($filename),
        })"
    >
        <div
            x-data="{
                open: false,
                anchor: null,
                show() {
                    this.anchor = this.$refs.trigger.getBoundingClientRect();
                    this.open = true;
                    this.prefetch();
                },
            }"
            @keydown.escape.window="open = false"
        >
            {{-- Tap shares the link. Holding opens the rest, and the rest is
                 opened rather than performed on purpose: a press timer is not
                 a user gesture, so a `navigator.share()` fired from one is
                 refused on iOS. The same reason the goal card's hold opens the
                 sheet instead of the camera. --}}
            <button
                type="button"
                x-ref="trigger"
                x-data="longPress({ delay: 420 })"
                {{-- Only the unfurl is warmed on touch: it is what the crawler
                     will ask for the moment the link is sent. The tall card is
                     fetched when the menu that offers it opens, so a plain tap
                     no longer downloads a 1080×1350 JPEG nobody asked for. --}}
                @pointerdown="start($event); warm()"
                @pointermove="move($event)"
                @pointerup="end()"
                @pointercancel="cancel()"
                @lostpointercapture="cancel()"
                @contextmenu.prevent
                @click.capture="onClick($event)"
                @short-press="share()"
                @long-press="show()"
                {{-- `short-press` rides on pointerup, which a keyboard never
                     sends, so without this Enter and Space did nothing at all.
                     detail is 0 only for keyboard-activated clicks, so a tap
                     cannot double-fire through here. --}}
                @click="$event.detail === 0 && share()"
                {{-- And the arrow key reaches the menu the hold opens. --}}
                @keydown.down.prevent="show()"
                aria-haspopup="menu"
                :aria-expanded="open ? 'true' : 'false'"
                @class([
                    'tap-target flex min-h-11 items-center gap-1.5 rounded-full border py-1 text-sm transition',
                    'px-3' => $label,
                    'px-2' => ! $label,
                    'border-zinc-200 text-zinc-500 dark:border-white/10 dark:text-zinc-400' => $tone === 'default',
                    'border-white/25 bg-black/40 text-white' => $tone === 'inverse',
                ])
                :class="pressing && 'scale-95'"
                aria-label="Compartir"
                data-test="share"
            >
                <flux:icon name="share" variant="micro" />
                @if ($label)
                    <span class="text-xs font-medium">Compartir</span>
                @endif
            </button>

            {{-- A feed card contains its own paint, so a menu drawn inside one
                 is cut off at the card's edge. It is positioned against the
                 trigger instead, and the first item takes focus because the
                 teleport moves it out of Tab order. --}}
            <template x-teleport="body">
                <div
                    x-show="open"
                    x-cloak
                    x-transition.opacity.duration.150ms
                    x-on:click.outside="open = false"
                    x-on:scroll.window="open = false"
                    x-effect="open && $nextTick(() => $el.querySelector('button').focus())"
                    :style="anchor && `right: ${innerWidth - anchor.right}px; bottom: ${innerHeight - anchor.top + 8}px`"
                    class="fixed z-50 w-56 overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-white/10"
                    role="menu"
                >
                    <button
                        type="button"
                        role="menuitem"
                        @click="open = false; shareImage()"
                        class="block w-full px-4 py-2.5 text-left text-sm hover:bg-zinc-100 dark:hover:bg-white/5"
                        data-test="share-image"
                    >
                        Enviar la imagen
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        @click="open = false; copy()"
                        class="block w-full px-4 py-2.5 text-left text-sm hover:bg-zinc-100 dark:hover:bg-white/5"
                        data-test="share-copy"
                    >
                        Copiar el link
                    </button>

                    <a
                        href="{{ $shareUrl }}"
                        role="menuitem"
                        class="block w-full px-4 py-2.5 text-left text-sm hover:bg-zinc-100 dark:hover:bg-white/5"
                        data-test="share-open"
                    >
                        Ver cómo se ve
                    </a>
                </div>
            </template>
        </div>
    </div>
@endif
