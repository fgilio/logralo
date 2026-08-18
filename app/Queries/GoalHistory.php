<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Goal;
use App\Models\Mark;
use App\ValueObjects\MarkHistory;
use Illuminate\Support\Collection;

/**
 * The mark history a goal's streak and photo rule are computed from.
 *
 * Whole histories are loaded rather than windowed: a member has at most five
 * goals and one mark per goal per day, so even years of this group are a few
 * thousand rows, and a window would quietly truncate a long streak.
 */
final readonly class GoalHistory
{
    public function for(Goal $goal): MarkHistory
    {
        return $this->forGoals([$goal])->get($goal->id, MarkHistory::empty());
    }

    /**
     * Many goals at once, keyed by goal ID.
     *
     * @param  iterable<array-key, Goal>  $goals
     * @return Collection<string, MarkHistory>
     */
    public function forGoals(iterable $goals): Collection
    {
        /** @var Collection<string, Goal> $goals */
        $goals = collect($goals)->keyBy(fn (Goal $goal): string => $goal->id);

        if ($goals->isEmpty()) {
            /** @var Collection<string, MarkHistory> */
            return collect();
        }

        $marks = Mark::query()
            ->whereIn('goal_id', $goals->keys()->all())
            ->oldest('marked_on')
            ->get(['goal_id', 'marked_on', 'photo_key'])
            ->groupBy('goal_id');

        return $goals->mapWithKeys(function (Goal $goal) use ($marks): array {
            /** @var Collection<int, Mark> $goalMarks */
            $goalMarks = $marks->get($goal->id, collect());

            return [$goal->id => new MarkHistory(
                entries: array_values($goalMarks
                    ->map(fn (Mark $mark): array => [
                        'date' => $mark->marked_on->toDateString(),
                        'full' => $mark->photo_key !== null,
                    ])
                    ->all()),
                pauses: $goal->streakPauses(),
            )];
        });
    }
}
