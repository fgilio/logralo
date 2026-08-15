@props(['links', 'alt' => '', 'eager' => false, 'fill' => false, 'wide' => false, 'sizes' => null])

@php
    // A box that spans the page says `wide`: the thumbnail is centre-cropped,
    // so a cover offered it would show a crop where the photo goes.
    $sizes ??= $wide ? '100vw' : '50vw';
    $srcset = $wide ? $links->srcset : "{$links->thumbnailUrl} 320w, {$links->srcset}";

    $img = new Illuminate\View\ComponentAttributeBag([
        'src' => $links->fallbackUrl,
        'width' => $links->width,
        'height' => $links->height,
        'alt' => $alt,
        'loading' => $eager ? 'eager' : 'lazy',
        'decoding' => $eager ? 'sync' : 'async',
        'fetchpriority' => $eager ? 'high' : 'auto',
        'class' => 'size-full object-cover',
    ]);
@endphp

@if ($fill)
    <picture
        {{ $attributes->class(['absolute inset-0 block size-full overflow-hidden bg-zinc-200 dark:bg-zinc-900']) }}
    >
        <source type="image/webp" srcset="{{ $srcset }}" sizes="{{ $sizes }}">
        <img {{ $img }}>
    </picture>
@else
    {{-- The intrinsic ratio is baked into the box so appending a card never
         shifts what is already on screen. --}}
    <div
        {{ $attributes->class(['relative w-full overflow-hidden bg-zinc-200 dark:bg-zinc-900']) }}
        style="aspect-ratio: {{ $links->aspectRatio() }}"
    >
        <picture>
            <source type="image/webp" srcset="{{ $links->srcset }}" sizes="100vw">
            <img {{ $img }}>
        </picture>
    </div>
@endif
