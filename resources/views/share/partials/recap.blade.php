@php
    $recap = $entry->recap;
    $standings = $recap->standingEntries();
    $winners = $entry->winners();
    $bestStreak = $entry->bestStreakLabel();
@endphp

<article class="relative overflow-hidden rounded-3xl bg-zinc-900 p-6 ring-1 ring-white/10">
    <div
        aria-hidden="true"
        class="pointer-events-none absolute -top-24 -right-16 size-56 rounded-full bg-accent/25 blur-3xl"
    ></div>

    <div class="relative">
        <p class="font-display text-xs tracking-[0.25em] text-accent-content uppercase">Cerró el mes</p>
        <h1 class="font-display text-4xl tracking-wide">{{ $entry->monthName() }}</h1>

        @if ($winners->isNotEmpty())
            <div class="mt-6 flex items-center gap-3">
                <span class="text-4xl">🏆</span>
                <div class="flex -space-x-2">
                    @foreach ($winners as $winner)
                        <flux:avatar
                            :name="$winner->name"
                            color="auto"
                            :color:seed="$winner->userId"
                            circle
                            size="sm"
                            class="ring-2 ring-zinc-900"
                        />
                    @endforeach
                </div>
                <div class="min-w-0 leading-tight">
                    <p class="truncate text-lg font-semibold">{{ $entry->winnerNames() }}</p>
                    <p class="text-xs text-white/60">
                        {{ $winners->first()->percentageLabel() }} del mes
                    </p>
                </div>
            </div>
        @endif

        <div class="mt-6 flex flex-col gap-1">
            @foreach ($standings as $standing)
                <x-standing-row :standing="$standing" class="text-white" />
            @endforeach
        </div>

        {{-- The card's own line, read from the entry rather than rebuilt here:
             the two are a tap apart, and they used to disagree about whether a
             one-day best streak said "días". --}}
        @if ($bestStreak !== null)
            <p class="mt-6 text-xs tracking-[0.2em] text-white/50 uppercase">Mejor racha · {{ $bestStreak }}</p>
        @endif
    </div>
</article>
