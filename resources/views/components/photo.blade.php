@props(['links', 'alt' => '', 'eager' => false, 'fill' => false, 'wide' => false])

@php
    $img = [
        'src' => $links->fallbackUrl,
        'width' => $links->width,
        'height' => $links->height,
        'alt' => $alt,
        'loading' => $eager ? 'eager' : 'lazy',
        'decoding' => $eager ? 'sync' : 'async',
        'fetchpriority' => $eager ? 'high' : 'auto',
    ];
@endphp

@if ($fill)
    {{-- Fills whatever box it is dropped into: the goal card's own face, the
         split card's square. The square thumbnail joins the srcset there,
         because a card is half a phone wide and a low-DPR screen has no
         business downloading 720px for it.

         A box that spans the page says `wide` and gets neither: the thumbnail
         is centre-cropped, so a cover offered it would show a crop of the photo
         where the photo goes, and 50vw would have the browser shopping for half
         the pixels it needs. --}}
    <picture {{ $attributes->class(['absolute inset-0 block size-full overflow-hidden']) }}>
        <source
            type="image/webp"
            srcset="{{ $wide ? $links->srcset : "{$links->thumbnailUrl} 320w, {$links->srcset}" }}"
            sizes="{{ $wide ? '100vw' : '50vw' }}"
        >
        <img @foreach ($img as $key => $value) {{ $key }}="{{ $value }}" @endforeach class="size-full object-cover">
    </picture>
@else
    {{-- Feed image: the intrinsic ratio is baked into the box so appending a
         card never shifts what is already on screen. --}}
    <div
        {{ $attributes->class(['relative w-full overflow-hidden bg-zinc-200 dark:bg-zinc-900']) }}
        style="aspect-ratio: {{ $links->aspectRatio() }}"
    >
        <picture>
            <source type="image/webp" srcset="{{ $links->srcset }}" sizes="100vw">
            <img @foreach ($img as $key => $value) {{ $key }}="{{ $value }}" @endforeach class="size-full object-cover">
        </picture>
    </div>
@endif
