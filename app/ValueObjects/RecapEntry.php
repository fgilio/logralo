<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

    /** @return Collection<int, Standing> */
    public function winners(): Collection
    {
        return $this->recap->standingEntries()->where('rank', 1)->values();
    }

    /** "Guido", or "Franco y Guido" on a tie. Empty when nobody marked. */
    public function winnerNames(): string
    {
        return $this->winners()->pluck('name')->join(', ', ' y ');
    }

    public function shareCard(): ShareCard
    {
        $champion = $this->winnerNames();

        return new ShareCard(
            title: $this->monthName(),
            badge: 'Cerró el mes',
            byline: $champion === '' ? 'Sin ganador' : "Ganó {$champion}",
            // array_filter drops the empty champion along with the nulls, so a
            // month nobody marked shows no podium rather than a blank one.
            stats: array_filter([
                'Campeón' => $champion,
                'Del mes' => $this->winners()->first()?->percentageLabel(),
                // `??` already suppresses the null, so the nullsafe arrow would
                // only be noise.
                'Mejor racha' => $this->recap->best_streak_days > 0
                    ? $this->recap->best_streak_days.' días · '.($this->recap->bestStreakUser->name ?? '—')
                    : null,
            ]),
        );
    }

    /** The browser tab, for whoever opens the link. */
    public function shareTitle(): string
    {
        $champion = $this->winnerNames();

        return $champion === ''
            ? "Cerró {$this->monthName()}"
            : "Ganó {$champion} en {$this->monthName()}";
    }

    /** A month has no row in the feed to scroll to. */
    public function shareAnchor(): ?string
    {
        return null;
    }

    public function shareText(): string
    {
        $champion = $this->winnerNames();

        return $champion === ''
            ? "🏆 Cerró {$this->monthName()} en Logralo"
            : "🏆 Ganó {$champion} en {$this->monthName()}";
    }

    public function shareUrl(): ?string
    {
        return $this->recap->shareUrl();
    }

    public function shareable(): MonthlyRecap
    {
        return $this->recap;
    }

    public function shareKind(): string
    {
        return 'recap';
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
