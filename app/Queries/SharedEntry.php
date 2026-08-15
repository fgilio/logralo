<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Mark;
use App\Models\MonthlyRecap;
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
        private MarkEntries $entries,
    ) {}

    /** Null for a token that never existed, or one that has been revoked. */
    public function find(string $token): ?FeedEntry
    {
        return $this->mark($token) ?? $this->recap($token);
    }

    private function mark(string $token): ?MarkEntry
    {
        // Reactions are not eager-loaded: the page renders them through
        // <livewire:share-reactions>, which reads them itself, and the card
        // endpoint never looks at them at all.
        $mark = Mark::query()
            ->with(['user', 'goal'])
            ->where('share_token', $token)
            ->first();

        if (! $mark instanceof Mark) {
            return null;
        }

        return $this->entries->from(
            $mark,
            $this->history->forGoals([$mark->goal_id])->get($mark->goal_id, MarkHistory::empty()),
        );
    }

    private function recap(string $token): ?RecapEntry
    {
        // The podium comes out of the standings JSON column; only the best
        // streak's owner is a relation anything on this page reads.
        $recap = MonthlyRecap::query()
            ->with('bestStreakUser')
            ->where('share_token', $token)
            ->first();

        return $recap instanceof MonthlyRecap ? new RecapEntry($recap) : null;
    }
}
