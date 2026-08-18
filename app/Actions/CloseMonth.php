<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Goal;
use App\Models\MonthlyRecap;
use App\Models\User;
use App\Queries\GoalHistory;
use App\Queries\MonthlyStandings;
use App\Services\StreakCalculator;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Closing a month: freeze the standings, work out the best streak, and post the
 * recap card into the feed.
 *
 * A month is only closeable once its last day has closed for *every* member.
 * Members are in different timezones, so the group's month ends at the last
 * member's grace cutoff, not at any single clock's midnight.
 */
final readonly class CloseMonth
{
    public function __construct(
        private MonthlyStandings $standings,
        private GoalHistory $history,
        private StreakCalculator $streaks,
    ) {}

    /**
     * @return MonthlyRecap|null null when the month is not closeable yet
     */
    public function handle(CarbonImmutable $month): ?MonthlyRecap
    {
        $month = $month->startOfMonth();

        Context::add('logralo.month', $month->format('Y-m'));

        try {
            $existing = MonthlyRecap::query()->covering($month)->first();

            if ($existing !== null) {
                Context::add('logralo.outcome', 'already_closed');

                return $existing;
            }

            if (! $this->isClosable($month)) {
                Context::add('logralo.outcome', 'not_yet');

                return null;
            }

            $standings = $this->standings->forMonth($month);
            $bestStreak = $this->bestStreak($month);

            $recap = MonthlyRecap::query()->create([
                'month' => $month->toDateString(),
                'posted_on' => $month->endOfMonth()->toDateString(),
                'winner_user_id' => $this->userIdAtRank($standings, 1),
                'runner_up_user_id' => $this->userIdAtRank($standings, 2),
                'best_streak_user_id' => $bestStreak['user_id'],
                'best_streak_goal_id' => $bestStreak['goal_id'],
                'best_streak_days' => $bestStreak['days'],
                'standings' => $standings->map(fn (Standing $standing): array => $standing->toArray())->all(),
            ]);

            Context::add('logralo.recap_id', $recap->id);
            Context::add('logralo.outcome', 'closed');

            return $recap;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('month.close.handled');
        }
    }

    /**
     * True once the grace window on the month's last day has expired for every
     * member.
     */
    public function isClosable(CarbonImmutable $month): bool
    {
        $lastDay = $month->startOfMonth()->endOfMonth()->startOfDay();

        return User::query()
            ->get()
            ->every(function (User $user) use ($lastDay): bool {
                $clock = $user->clock();

                return ! $clock->isOpen(CarbonImmutable::parse($lastDay->toDateString(), $clock->timezone));
            });
    }

    /**
     * The highest flame anyone reached during the month, across every group
     * goal — archived ones included, because the streak really happened.
     * Private goals stay out: the recap posts into the group feed and
     * names the goal, which would disclose it to every member.
     *
     * @return array{user_id: string|null, goal_id: string|null, days: int}
     */
    private function bestStreak(CarbonImmutable $month): array
    {
        $goals = Goal::query()->sharedWithGroup()->get(['id', 'user_id']);
        $histories = $this->history->forGoals(array_values($goals->pluck('id')->all()));

        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $best = ['user_id' => null, 'goal_id' => null, 'days' => 0];

        foreach ($goals as $goal) {
            $history = $histories->get($goal->id);

            if ($history === null) {
                continue;
            }

            $days = $this->streaks->bestWithin($history->dates(), $from, $to);

            if ($days > $best['days']) {
                $best = ['user_id' => $goal->user_id, 'goal_id' => $goal->id, 'days' => $days];
            }
        }

        return $best;
    }

    /**
     * @param  Collection<int, Standing>  $standings
     */
    private function userIdAtRank(Collection $standings, int $rank): ?string
    {
        return $standings
            ->first(fn (Standing $standing): bool => $standing->rank === $rank)
            ?->userId;
    }
}
