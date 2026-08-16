@props(['standing', 'highlight' => false])

@php
    $medal = match ($standing->rank) {
        1 => '🥇',
        2 => '🥈',
        3 => '🥉',
        default => null,
    };
@endphp

<div
    {{ $attributes->class([
        'flex items-center gap-3 rounded-xl px-2 py-2',
        'bg-accent/10 ring-1 ring-accent/25' => $highlight,
    ]) }}
>
    <span class="w-6 shrink-0 text-center font-display text-lg leading-none text-zinc-400 dark:text-zinc-500">
        {{ $medal ?? $standing->rank }}
    </span>

    <x-avatar :user-id="$standing->userId" :name="$standing->name" size="sm" />

    <div class="min-w-0 flex-1">
        <div class="flex items-baseline justify-between gap-2">
            <span class="truncate text-sm font-medium">{{ $standing->name }}</span>
            <span class="shrink-0 font-display text-base tabular-nums">
                {{ $standing->percentageLabel() }}
            </span>
        </div>

        {{-- Solid is proof, hatched is the half credit a ghost mark earns.
             The bar is the scoring rule, explained without words. --}}
        <div
            class="mt-1.5 flex h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-white/10"
            role="img"
            aria-label="{{ $standing->fullMarks }} con foto, {{ $standing->ghostMarks }} sin foto"
        >
            <div
                class="h-full bg-accent transition-[width] duration-500"
                style="width: {{ $standing->fullPercentage() }}%"
            ></div>
            <div
                class="hatched h-full transition-[width] duration-500"
                style="width: {{ $standing->ghostPercentage() }}%"
            ></div>
        </div>
    </div>
</div>
