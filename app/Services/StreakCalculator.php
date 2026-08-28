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
final readonly class StreakCalculator
{
    /**
     * The streak a member sees on the goal card right now.
     *
     * @param  list<string>  $markedDates  Y-m-d, any order
     * @param  list<array{from: string, through: string|null}>  $pauses
     */
    public function current(array $markedDates, UserClock $clock, array $pauses = []): int
    {
        $marked = array_flip($markedDates);
        $day = $clock->today();
        $streak = 0;

        while (true) {
            if (isset($marked[$day->toDateString()])) {
                $streak++;
            } elseif ($this->wasPaused($day, $pauses)) {
                // Paused days preserve the run without adding flame days.
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
     * @param  list<array{from: string, through: string|null}>  $pauses
     */
    public function endingOn(array $markedDates, CarbonImmutable $day, array $pauses = []): int
    {
        $marked = array_flip($markedDates);
        $cursor = $day->startOfDay();
        $streak = 0;

        while (isset($marked[$cursor->toDateString()]) || $this->wasPaused($cursor, $pauses)) {
            if (isset($marked[$cursor->toDateString()])) {
                $streak++;
            }

            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * What a day takes down with it if it closes unmarked.
     *
     * Zero when the day is already marked, when it was paused, or when no run
     * reaches it, so a member is only ever told about a streak that is really
     * about to end.
     *
     * @param  list<string>  $markedDates  Y-m-d, any order
     * @param  list<array{from: string, through: string|null}>  $pauses
     */
    public function atRiskOn(array $markedDates, CarbonImmutable $day, array $pauses = []): int
    {
        $date = $day->startOfDay()->toDateString();

        if (in_array($date, $markedDates, true) || $this->wasPaused($day, $pauses)) {
            return 0;
        }

        return $this->endingOn($markedDates, $day->subDay(), $pauses);
    }

    /**
     * The highest flame count reached inside a window — the "best streak of
     * the month" on a recap card. Runs that started before the window still
     * count, because the flame the member saw included them.
     *
     * @param  list<string>  $markedDates  Y-m-d, any order
     * @param  list<array{from: string, through: string|null}>  $pauses
     */
    public function bestWithin(array $markedDates, CarbonImmutable $from, CarbonImmutable $to, array $pauses = []): int
    {
        $marked = array_flip($markedDates);
        $best = 0;
        $running = 0;
        $cursor = $from->startOfDay();

        // Walk in from the start of the run that is live on the first day, so
        // a streak crossing into the window keeps its real length.
        $lead = $cursor->subDay();
        while (isset($marked[$lead->toDateString()]) || $this->wasPaused($lead, $pauses)) {
            if (isset($marked[$lead->toDateString()])) {
                $running++;
            }

            $lead = $lead->subDay();
        }

        while ($cursor->lessThanOrEqualTo($to)) {
            if (isset($marked[$cursor->toDateString()])) {
                $running++;
                $best = max($best, $running);
            } elseif (! $this->wasPaused($cursor, $pauses)) {
                $running = 0;
            }

            $cursor = $cursor->addDay();
        }

        return $best;
    }

    /** @param  list<array{from: string, through: string|null}>  $pauses */
    private function wasPaused(CarbonImmutable $day, array $pauses): bool
    {
        $date = $day->toDateString();

        return collect($pauses)->contains(
            fn (array $pause): bool => $pause['from'] <= $date
                && ($pause['through'] === null || $pause['through'] >= $date)
        );
    }
}
