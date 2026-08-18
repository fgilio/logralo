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
        return $this->forGoals([$goal->id])->get($goal->id, MarkHistory::empty());
    }

    /**
     * Many goals at once, keyed by goal id — one query for a whole screen.
     *
     * @param  list<string>  $goalIds
     * @return Collection<string, MarkHistory>
     */
    public function forGoals(array $goalIds): Collection
    {
        if ($goalIds === []) {
            /** @var Collection<string, MarkHistory> */
            return collect();
        }

        $goals = Goal::query()
            ->whereKey($goalIds)
            ->get(['id', 'streak_pauses'])
            ->keyBy('id');

        $marks = Mark::query()
            ->whereIn('goal_id', $goalIds)
            ->oldest('marked_on')
            ->get(['goal_id', 'marked_on', 'photo_key'])
            ->groupBy('goal_id');

        return collect($goalIds)->mapWithKeys(function (string $goalId) use ($goals, $marks): array {
            /** @var Collection<int, Mark> $goalMarks */
            $goalMarks = $marks->get($goalId, collect());

            return [$goalId => new MarkHistory(
                entries: array_values($goalMarks
                    ->map(fn (Mark $mark): array => [
                        'date' => $mark->marked_on->toDateString(),
                        'full' => $mark->photo_key !== null,
                    ])
                    ->all()),
                pauses: $goals->get($goalId)?->streakPauses() ?? [],
            )];
        });
    }
}
