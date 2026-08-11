<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Goal;
use Carbon\CarbonImmutable;

/**
 * Thrown when a goal already has a mark for that day. Two taps racing each
 * other land here rather than on the unique index.
 */
final class DuplicateMarkException extends UserFacingException
{
    public static function make(Goal $goal, CarbonImmutable $day): self
    {
        return new self("Goal [{$goal->id}] is already marked on [{$day->toDateString()}].");
    }

    public function userMessage(): string
    {
        return 'Ese objetivo ya estaba marcado.';
    }
}
