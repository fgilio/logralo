<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\Mark;
use Carbon\CarbonImmutable;

/**
 * A mark as it appears in the feed, carrying the flame count it had on its own
 * day rather than the goal's flame today, and the ghost run behind the
 * "2ᵃ seguida" line.
 */
final readonly class MarkEntry implements FeedEntry
{
    public function __construct(
        public Mark $mark,
        public int $streak,
        public int $ghostRun,
        public ?PhotoLinks $photo,
    ) {}

    public function day(): CarbonImmutable
    {
        return $this->mark->marked_on;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->mark->created_at ?? $this->mark->marked_on;
    }

    public function key(): string
    {
        return "mark-{$this->mark->id}";
    }
}
