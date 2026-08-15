<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Mark;
use App\Services\PhotoProcessor;
use App\Services\StreakCalculator;
use App\ValueObjects\MarkEntry;
use App\ValueObjects\MarkHistory;

/**
 * One mark, plus its goal's history, turned into the entry every screen shows.
 *
 * Three places need this — the feed, a shared link, and the milestone
 * celebration — and the rules are subtle enough that three hand-written copies
 * disagreed: the streak is the one ending on the day that was marked rather
 * than today's, a ghost mark carries the run behind it, and a photo whose
 * dimensions were never recorded falls back to 4:3 so the feed still reserves
 * the right box.
 */
final readonly class MarkEntries
{
    public function __construct(
        private StreakCalculator $streaks,
        private PhotoProcessor $photos,
    ) {}

    public function from(Mark $mark, MarkHistory $history): MarkEntry
    {
        return new MarkEntry(
            mark: $mark,
            streak: $this->streaks->endingOn($history->dates(), $mark->marked_on),
            ghostRun: $mark->isGhost()
                ? $history->ghostRunEndingOn($mark->marked_on->toDateString())
                : 0,
            photo: $mark->photo_key === null ? null : $this->photos->links(
                $mark->photo_key,
                $mark->photo_width ?? 4,
                $mark->photo_height ?? 3,
            ),
        );
    }
}
