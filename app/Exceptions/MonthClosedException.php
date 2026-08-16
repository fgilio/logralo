<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonImmutable;

/**
 * Thrown when a day is still open on the member's own clock but the month it
 * belongs to has already been recapped. The score is frozen by then, so a
 * mark written into it would count for nothing and still show in the feed.
 */
final class MonthClosedException extends UserFacingException
{
    public static function on(CarbonImmutable $day): self
    {
        return new self("Month [{$day->format('Y-m')}] is closed: its recap is already posted.");
    }

    public function userMessage(): string
    {
        return 'Ese mes ya cerró.';
    }
}
