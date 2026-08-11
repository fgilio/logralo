<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Anything the group feed can show. Marks are the bulk of it; a month-end
 * recap is the other kind.
 */
interface FeedEntry
{
    /** The day divider this entry sits under. */
    public function day(): CarbonImmutable;

    /** Ordering within a day, newest first. */
    public function occurredAt(): CarbonImmutable;

    /** Stable wire:key for the feed loop. */
    public function key(): string;
}
