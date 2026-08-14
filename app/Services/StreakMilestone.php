<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Which streaks are worth interrupting somebody for.
 *
 * Sharing does not fail because the button is hard to find; it fails because
 * nothing ever asks. The moment right after a tap that lands on a round number
 * is the only moment a person actually wants to tell the group, so that is
 * where the app offers.
 *
 * Rare on purpose. A celebration on every mark is a dialog to dismiss, and a
 * dialog to dismiss is worse than no dialog at all.
 */
final readonly class StreakMilestone
{
    /** After the last of these, every further round fifty counts. */
    private const array THRESHOLDS = [3, 7, 14, 21, 30, 50, 75, 100];

    private const int REPEAT_EVERY = 50;

    public function isMilestone(int $streak): bool
    {
        if ($streak <= 0) {
            return false;
        }

        return in_array($streak, self::THRESHOLDS, true)
            || ($streak > max(self::THRESHOLDS) && $streak % self::REPEAT_EVERY === 0);
    }

    /** The line the celebration shouts. */
    public function headline(int $streak): string
    {
        return match ($streak) {
            7 => 'Una semana entera',
            14 => 'Dos semanas',
            21 => 'Tres semanas',
            30 => 'Un mes entero',
            100 => 'Cien días',
            default => "{$streak} días seguidos",
        };
    }
}
