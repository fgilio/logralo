@props(['days' => 0, 'size' => 'base', 'dim' => false])

@php
    $scale = match ($size) {
        'sm' => ['icon' => 'size-3.5', 'text' => 'text-xs'],
        'lg' => ['icon' => 'size-6', 'text' => 'text-xl'],
        default => ['icon' => 'size-4', 'text' => 'text-sm'],
    };
@endphp

<span
    {{ $attributes->class(['inline-flex items-baseline gap-1 tabular-nums', 'opacity-45' => $dim || $days === 0]) }}
    @if ($days > 0) title="{{ $days }} {{ \Illuminate\Support\Str::plural('día', $days) }} seguidos" @endif
>
    <x-brand-mark :muted="$dim || $days === 0" class="{{ $scale['icon'] }} translate-y-px self-center" />
    <span class="font-display {{ $scale['text'] }} leading-none">{{ $days }}</span>
</span>
