<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;

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
}
