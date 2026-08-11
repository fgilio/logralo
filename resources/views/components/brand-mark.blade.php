@props(['muted' => false])

{{-- The flame is the whole identity: a streak you keep alive. Two-tone split
     of the Heroicons (MIT) solid "fire" glyph that Flux already ships. --}}
<svg
    {{ $attributes->class(['shrink-0']) }}
    viewBox="0 0 24 24"
    fill="none"
    aria-hidden="true"
    xmlns="http://www.w3.org/2000/svg"
>
    <path
        d="M12.963 2.286a.75.75 0 0 0-1.071-.136 9.742 9.742 0 0 0-3.539 6.176 7.547 7.547 0 0 1-1.705-1.715.75.75 0 0 0-1.152-.082A9 9 0 1 0 15.68 4.534a7.46 7.46 0 0 1-2.717-2.248Z"
        class="{{ $muted ? 'fill-zinc-400 dark:fill-zinc-600' : 'fill-accent' }}"
    />
    <path
        d="M15.75 14.25a3.75 3.75 0 1 1-7.313-1.172c.628.465 1.35.81 2.133 1a5.99 5.99 0 0 1 1.925-3.546 3.75 3.75 0 0 1 3.255 3.718Z"
        class="{{ $muted ? 'fill-zinc-200 dark:fill-zinc-800' : 'fill-amber-200 dark:fill-amber-100' }}"
    />
</svg>
