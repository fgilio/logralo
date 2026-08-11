<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a member tries to keep more goals in the grid than the limit.
 */
final class GoalLimitReachedException extends UserFacingException
{
    public static function make(): self
    {
        return new self('The active goal limit has been reached.');
    }

    public function userMessage(): string
    {
        $limit = (int) config('logralo.goals.max_active');

        return "Ya tenés {$limit} objetivos activos. Archivá uno para crear otro.";
    }
}
