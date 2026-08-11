<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonImmutable;

/**
 * Thrown when a member tries to log a day that is no longer theirs to log:
 * anything before yesterday, or yesterday after the grace hour.
 */
final class DayClosedException extends UserFacingException
{
    public static function on(CarbonImmutable $day): self
    {
        return new self("Day [{$day->toDateString()}] is closed for marking.");
    }

    public function userMessage(): string
    {
        return 'Ese día ya cerró.';
    }
}
