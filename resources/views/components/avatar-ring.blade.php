@props(['user', 'completion' => 0.0, 'complete' => false, 'label' => null, 'size' => 'md'])

@php
    $box = $size === 'sm' ? 'size-11' : 'size-14';
    $inner = $size === 'sm' ? 'size-9' : 'size-12';
@endphp

{{-- A member's day, drawn as a ring that closes as their goals do. --}}
<div {{ $attributes->class(['flex w-16 shrink-0 flex-col items-center gap-1.5']) }}>
    <div
        class="pulse-ring grid {{ $box }} place-items-center rounded-full p-[3px] text-zinc-400 transition-[background] duration-500 dark:text-zinc-600"
        style="--pulse: {{ number_format($completion, 3, '.', '') }}"
        @if ($complete) data-complete="true" @endif
    >
        <div
            class="grid {{ $inner }} place-items-center rounded-full bg-zinc-100 dark:bg-zinc-950"
        >
            <x-avatar :user="$user" :size="$size === 'sm' ? 'sm' : 'md'" />
        </div>
    </div>

    <span class="w-full truncate text-center text-[0.7rem] leading-tight text-zinc-500 dark:text-zinc-400">
        {{ $label ?? \Illuminate\Support\Str::of($user->name)->explode(' ')->first() }}
    </span>
</div>
