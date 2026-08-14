<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The month-end card: winner, runner-up and the best streak of the month,
 * posted above the last day of the month it closes.
 */
final readonly class RecapEntry implements FeedEntry
{
    public function __construct(public MonthlyRecap $recap) {}

    public function day(): CarbonImmutable
    {
        return $this->recap->posted_on;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->recap->created_at ?? $this->recap->posted_on;
    }

    public function key(): string
    {
        return "recap-{$this->recap->id}";
    }

    public function monthName(): string
    {
        return Str::ucfirst($this->recap->month->translatedFormat('F Y'));
    }

    /** @return \Illuminate\Support\Collection<int, Standing> */
    public function winners(): \Illuminate\Support\Collection
    {
        return $this->recap->standingEntries()->where('rank', 1)->values();
    }

    public function shareCard(): ShareCard
    {
        $winners = $this->winners();
        $champion = $winners->pluck('name')->join(', ', ' y ');

        return new ShareCard(
            title: $this->monthName(),
            badge: 'Cerró el mes',
            byline: $winners->isEmpty() ? 'Sin ganador' : "Ganó {$champion}",
            stats: array_filter([
                'Campeón' => $winners->isEmpty() ? null : $champion,
                'Del mes' => $winners->isEmpty()
                    ? null
                    : rtrim(rtrim(number_format($winners->first()->percentage(), 1, ',', '.'), '0'), ',').'%',
                'Mejor racha' => $this->recap->best_streak_days > 0
                    ? $this->recap->best_streak_days.' días · '.($this->recap->bestStreakUser?->name ?? '—')
                    : null,
            ]),
        );
    }

    public function shareText(): string
    {
        $winners = $this->winners();

        return $winners->isEmpty()
            ? "🏆 Cerró {$this->monthName()} en Logralo"
            : '🏆 Ganó '.$winners->pluck('name')->join(', ', ' y ')." en {$this->monthName()}";
    }

    public function shareUrl(): ?string
    {
        return $this->recap->shareUrl();
    }

    public function shareable(): MonthlyRecap
    {
        return $this->recap;
    }

    public function shareCardDirectory(): string
    {
        return $this->recap->shareCardDirectory();
    }

    /** A recap is a scoreboard, not a photograph. */
    public function sharePhotoKey(): ?string
    {
        return null;
    }
}
