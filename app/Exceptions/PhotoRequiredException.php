<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Goal;

/**
 * Thrown when a goal has run out of ghost marks: two in a row, and the third
 * tap has to bring proof.
 */
final class PhotoRequiredException extends UserFacingException
{
    public static function forGoal(Goal $goal): self
    {
        return new self("Goal [{$goal->id}] reached its ghost mark limit and needs a photo.");
    }

    public function userMessage(): string
    {
        return 'Pics or it didn\'t happen 📸';
    }
}
