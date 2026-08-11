<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Goal;

/**
 * Thrown when a member acts on a goal that has left the grid. Archived goals
 * keep their history and freeze their streak; they stop taking marks.
 */
final class GoalArchivedException extends UserFacingException
{
    public static function forGoal(Goal $goal): self
    {
        return new self("Goal [{$goal->id}] is archived.");
    }

    public function userMessage(): string
    {
        return 'Ese objetivo está archivado.';
    }
}
