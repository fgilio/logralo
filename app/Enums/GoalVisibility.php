<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who may see a goal and everything that hangs off it: its marks in the
 * feed, its share of the pulse ring, its weight in the monthly score.
 * A private goal exists only on its owner's screen.
 */
enum GoalVisibility: string
{
    case Group = 'group';
    case Private = 'private';
}
