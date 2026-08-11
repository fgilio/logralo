@props(['recap'])

@php
    $standings = $recap->standingEntries();
    $winners = $standings->where('rank', 1);
    $runnersUp = $standings->where('rank', 2);
    $month = \Illuminate\Support\Str::ucfirst($recap->month->translatedFormat('F Y'));

    $shareText = $winners->isEmpty()
        ? "Cerró {$month} en Logralo"
        : 'Ganó ' . $winners->pluck('name')->join(', ', ' y ') . " en {$month} — Logralo";
@endphp

<article class="feed-card relative overflow-hidden rounded-2xl bg-zinc-900 p-5 text-white ring-1 ring-white/10">
    <div
        aria-hidden="true"
        class="pointer-events-none absolute -top-24 -right-16 size-56 rounded-full bg-accent/25 blur-3xl"
    ></div>

    <header class="relative flex items-start justify-between gap-3">
        <div>
            <p class="font-display text-xs tracking-[0.25em] text-accent-content uppercase">
                Cerró el mes
            </p>
            <h2 class="font-display text-2xl tracking-wide">{{ $month }}</h2>
        </div>

        <div
            x-data="shareCard({
                imageUrl: null,
                text: @js($shareText),
                url: @js(route('today')),
            })"
        >
            <flux:button
                variant="subtle"
                size="sm"
                square
                icon="share"
                aria-label="Compartir"
                x-on:click="share()"
                class="text-white!"
            />
        </div>
    </header>

    @if ($winners->isNotEmpty())
        <div class="relative mt-5 flex items-center gap-3">
            <span class="text-3xl">🏆</span>
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
                <p class="truncate font-semibold">{{ $winners->pluck('name')->join(', ', ' y ') }}</p>
                <p class="text-xs text-white/60">
                    {{ rtrim(rtrim(number_format($winners->first()->percentage(), 1, ',', '.'), '0'), ',') }}% del mes
                </p>
            </div>
        </div>
    @endif

    <dl class="relative mt-5 grid grid-cols-2 gap-3 text-sm">
        <div class="rounded-xl bg-white/5 p-3">
            <dt class="text-xs text-white/50">Escolta</dt>
            <dd class="mt-0.5 truncate font-medium">
                {{ $runnersUp->isEmpty() ? '—' : $runnersUp->pluck('name')->join(', ', ' y ') }}
            </dd>
        </div>

        <div class="rounded-xl bg-white/5 p-3">
            <dt class="text-xs text-white/50">Mejor racha</dt>
            <dd class="mt-0.5 flex items-baseline gap-1.5 truncate font-medium">
                @if ($recap->best_streak_days > 0)
                    <x-flame :days="$recap->best_streak_days" size="sm" />
                    <span class="truncate text-xs text-white/70">
                        {{ $recap->bestStreakUser?->name }}
                    </span>
                @else
                    —
                @endif
            </dd>
        </div>
    </dl>

    <details class="relative mt-4">
        <summary class="cursor-pointer text-xs tracking-wider text-white/50 uppercase">
            Tabla completa
        </summary>

        <div class="mt-3 flex flex-col gap-1">
            @foreach ($standings as $standing)
                <x-standing-row :standing="$standing" class="text-white" />
            @endforeach
        </div>
    </details>
</article>
