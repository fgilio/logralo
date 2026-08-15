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
    {{-- Spelled out rather than inflected, for the reason share/show.blade.php
         gives — and the adjective goes with the noun, which the first pass at
         this missed: it read "1 día seguidos" on the first day of every goal. --}}
    @if ($days > 0) title="{{ $days }} {{ $days === 1 ? 'día seguido' : 'días seguidos' }}" @endif
>
    {{-- Number first, then the flame, the way it is written on the share card
         and the way anyone types it in a chat: "12 🔥", not "🔥 12". --}}
    <span class="font-display {{ $scale['text'] }} leading-none">{{ $days }}</span>
    <x-brand-mark :muted="$dim || $days === 0" class="{{ $scale['icon'] }} translate-y-px self-center" />
</span>
