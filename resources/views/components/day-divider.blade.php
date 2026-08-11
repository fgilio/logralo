@props(['day', 'today', 'yesterday'])

@php
    $date = $day->toDateString();

    $label = match ($date) {
        $today => 'Hoy',
        $yesterday => 'Ayer',
        default => \Illuminate\Support\Str::ucfirst($day->translatedFormat('l j \d\e F')),
    };
@endphp

{{-- Not sticky: the header already owns the top of the screen, and two sticky
     layers would stack on top of each other. --}}
<div class="flex items-center gap-3 pt-3 pb-1">
    <span class="font-display text-xs tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
        {{ $label }}
    </span>
    <span class="h-px flex-1 bg-zinc-200 dark:bg-white/10"></span>
</div>
