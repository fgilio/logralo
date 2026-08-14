@props(['links', 'alt' => ''])

{{-- A photo, and the full-screen look at it. Opened in place so the feed keeps
     its scroll. --}}
<button
    type="button"
    x-data="{ open: false }"
    x-on:click="open = true"
    {{ $attributes->class(['relative block overflow-hidden']) }}
    aria-label="Ver foto completa"
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
        >
            <img src="{{ $links->fallbackUrl }}" alt="{{ $alt }}" class="max-h-full max-w-full object-contain">
        </div>
    </template>
</button>
