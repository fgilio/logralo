<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Services\PhotoProcessor;
use App\Services\StreakCalculator;
use App\ValueObjects\FeedEntry;
use App\ValueObjects\MarkEntry;
use App\ValueObjects\MarkHistory;
use App\ValueObjects\RecapEntry;

/**
 * A share token, resolved back to the thing it points at.
 *
 * The public share page and the card renderer both start here, and both are
 * reachable without a session — a WhatsApp preview is fetched by an anonymous
 * crawler, so the token is the only credential either of them gets.
 */
final readonly class SharedEntry
{
    public function __construct(
        private GoalHistory $history,
        private StreakCalculator $streaks,
        private PhotoProcessor $photos,
    ) {}

    /** Null for a token that never existed, or one that has been revoked. */
    public function find(string $token): ?FeedEntry
    {
        return $this->mark($token) ?? $this->recap($token);
    }

    private function mark(string $token): ?MarkEntry
    {
        $mark = Mark::query()
            ->with(['user', 'goal', 'reactions.user'])
            ->where('share_token', $token)
            ->first();

        if (! $mark instanceof Mark) {
            return null;
        }

        $history = $this->history->forGoals([$mark->goal_id])->get($mark->goal_id, MarkHistory::empty());

        return new MarkEntry(
            mark: $mark,
            streak: $this->streaks->endingOn($history->dates(), $mark->marked_on),
            ghostRun: $mark->isGhost() ? $history->ghostRunEndingOn($mark->marked_on->toDateString()) : 0,
            photo: $mark->photo_key === null ? null : $this->photos->links(
                $mark->photo_key,
                $mark->photo_width ?? 4,
                $mark->photo_height ?? 3,
            ),
        );
    }

    private function recap(string $token): ?RecapEntry
    {
        $recap = MonthlyRecap::query()
            ->with(['winner', 'runnerUp', 'bestStreakUser', 'bestStreakGoal'])
            ->where('share_token', $token)
            ->first();

        return $recap instanceof MonthlyRecap ? new RecapEntry($recap) : null;
    }
}
