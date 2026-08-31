<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Mark;
use App\Services\StreakCalculator;
use App\ValueObjects\MarkEntry;
use App\ValueObjects\MarkHistory;
use Carbon\CarbonImmutable;

/**
 * One mark, plus its goal's history, turned into the entry every screen shows.
 *
 * Three places need this — the feed, a shared link, and the milestone
 * celebration — and the rules are subtle enough that three hand-written copies
 * disagreed: the streak is the one ending on the day that was marked rather
 * than today's, and a ghost mark carries the run behind it.
 */
final readonly class MarkEntries
{
    public function __construct(private StreakCalculator $streaks) {}

    public function from(Mark $mark, MarkHistory $history): MarkEntry
    {
        return new MarkEntry(
            mark: $mark,
            streak: $this->streakOn($mark->marked_on, $history),
            ghostRun: $mark->isGhost()
                ? $history->ghostRunEndingOn($mark->marked_on->toDateString())
                : 0,
            photo: $mark->photoLinks(),
        );
    }

    /**
     * The run a marked day ends on.
     *
     * Counted back from that day rather than from today, because inside the
     * grace window those are different numbers: marking yesterday while today
     * is still unmarked would otherwise report today's run. Separate from
     * from() because marking a goal and celebrating it both want this number
     * and neither wants the photo links that come with a whole entry.
     */
    public function streakOn(CarbonImmutable $day, MarkHistory $history): int
    {
        return $this->streaks->endingOn($history->dates(), $day, $history->pauses);
    }
}
