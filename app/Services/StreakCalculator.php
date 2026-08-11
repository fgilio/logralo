<?php

declare(strict_types=1);

namespace App\Services;

use App\ValueObjects\UserClock;
use Carbon\CarbonImmutable;

/**
 * Streaks are per goal: consecutive days with a mark, ghost marks included.
 * Streaks reward showing up; photos are what the monthly score rewards.
 *
 * A streak only breaks when a day *closes* unmarked, so an unmarked today (and
 * an unmarked yesterday during grace) is skipped rather than counted as a miss.
 */
final class StreakCalculator
{
    /**
     * The streak a member sees on the goal card right now.
     *
     * @param  list<string>  $markedDates  Y-m-d, any order
     */
    public function current(array $markedDates, UserClock $clock): int
    {
        $marked = array_flip($markedDates);
        $day = $clock->today();
        $streak = 0;

        while (true) {
            if (isset($marked[$day->toDateString()])) {
                $streak++;
            } elseif (! $clock->isOpen($day)) {
                return $streak;
            }

            $day = $day->subDay();
        }
    }

    /**
     * The flame a feed card carries: consecutive marked days ending on that
     * card's own day.
     *
     * @param  list<string>  $markedDates  Y-m-d, any order
     */
    public function endingOn(array $markedDates, CarbonImmutable $day): int
    {
        $marked = array_flip($markedDates);
        $cursor = $day->startOfDay();
        $streak = 0;

        while (isset($marked[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * The highest flame count reached inside a window — the "best streak of
     * the month" on a recap card. Runs that started before the window still
     * count, because the flame the member saw included them.
     *
     * @param  list<string>  $markedDates  Y-m-d, any order
     */
    public function bestWithin(array $markedDates, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $marked = array_flip($markedDates);
        $best = 0;
        $running = 0;
        $cursor = $from->startOfDay();

        // Walk in from the start of the run that is live on the first day, so
        // a streak crossing into the window keeps its real length.
        $lead = $cursor->subDay();
        while (isset($marked[$lead->toDateString()])) {
            $running++;
            $lead = $lead->subDay();
        }

        while ($cursor->lessThanOrEqualTo($to)) {
            $running = isset($marked[$cursor->toDateString()]) ? $running + 1 : 0;
            $best = max($best, $running);
            $cursor = $cursor->addDay();
        }

        return $best;
    }
}
