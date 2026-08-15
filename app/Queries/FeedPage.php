<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\ValueObjects\FeedEntry;
use App\ValueObjects\FeedResult;
use App\ValueObjects\MarkHistory;
use App\ValueObjects\RecapEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The group feed: everyone's marks, newest first, with the month-end recap
 * cards mixed in.
 *
 * The feed grows by re-reading the first N marks rather than by paging with a
 * cursor. New marks land at the top constantly, and a cursor would either
 * duplicate rows or hide them; re-reading is exact, and N stays small because
 * this is a group of friends, not a public network.
 */
final readonly class FeedPage
{
    public function __construct(
        private GoalHistory $history,
        private MarkEntries $entries,
    ) {}

    public function load(int $limit): FeedResult
    {
        $marks = Mark::query()
            ->with(['user', 'goal', 'reactions'])
            ->latest('marked_on')->latest()
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $marks->count() > $limit;
        $marks = $marks->take($limit);

        $histories = $this->history->forGoals(
            array_values($marks->pluck('goal_id')->unique()->all())
        );

        /** @var Collection<int, FeedEntry> $entries */
        $entries = $marks->map(fn (Mark $mark): FeedEntry => $this->entries->from(
            $mark,
            $histories->get($mark->goal_id, MarkHistory::empty()),
        ));

        return new FeedResult(
            entries: $this->withRecaps($entries, $hasMore),
            hasMore: $hasMore,
        );
    }

    /**
     * Recap cards are a handful per year, so they are loaded whole and merged
     * in rather than paged. Anything older than the oldest mark on screen waits
     * for the reader to scroll that far.
     *
     * @param  Collection<int, FeedEntry>  $entries
     * @return Collection<int, FeedEntry>
     */
    private function withRecaps(Collection $entries, bool $hasMore): Collection
    {
        $oldest = $entries->last()?->day();
        $floor = $hasMore && $oldest !== null ? $oldest->toDateString() : null;

        $recaps = MonthlyRecap::query()
            ->with(['winner', 'runnerUp', 'bestStreakUser', 'bestStreakGoal'])
            ->when($floor !== null, fn (Builder $query): Builder => $query->where('posted_on', '>=', $floor))
            ->get()
            ->map(fn (MonthlyRecap $recap): FeedEntry => new RecapEntry($recap));

        return $entries
            ->concat($recaps)
            // Day first, then the whole instant — a recap written the morning
            // after the month ended still sits at the top of the day it closes.
            ->sortByDesc(fn (FeedEntry $entry): string => $entry->day()->toDateString().' '.$entry->occurredAt()->format('Y-m-d H:i:s.u'))
            ->values();
    }
}
