@props(['links', 'alt' => ''])

{{-- Opened in place so the feed keeps its scroll. --}}
<button
    type="button"
    x-data="{ open: false }"
    x-on:click="open = true"
    x-effect="document.body.style.overflow = open ? 'hidden' : ''"
    {{ $attributes->class(['relative block overflow-hidden focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent']) }}
    aria-label="Ver la foto completa de {{ $alt }}"
>
    {{ $slot }}

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-transition.opacity
            x-on:click="open = false"
            x-on:keydown.escape.window="open = false"
            class="fixed inset-0 z-50 grid place-items-center bg-black/95 p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Foto completa"
        >
            {{-- The teleport clones this into the body while the card renders,
                 so an eager src would download every photo on the page. --}}
            <picture>
                <source type="image/webp" srcset="{{ $links->srcset }}" sizes="100vw">
                <img
                    src="{{ $links->fallbackUrl }}"
                    alt="{{ $alt }}"
                    loading="lazy"
                    decoding="async"
                    class="max-h-full max-w-full object-contain"
                >
            </picture>

            <button
                type="button"
                class="tap-target absolute top-4 right-4 grid size-11 place-items-center rounded-full bg-white/10 text-white"
                aria-label="Cerrar"
                data-test="viewer-close"
            >
                <flux:icon name="x-mark" variant="micro" />
            </button>
        </div>
    </template>
</button>
