<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Services\ScoreCalculator;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The monthly table behind the pulse strip.
 *
 * Only marks on goals that are still active count. That is what "archiving
 * stops the goal counting from that day" means in the score: archiving removes
 * a goal from both sides of the ratio, so it can neither inflate a member's
 * percentage nor be used to game the last day of the month.
 */
final readonly class MonthlyStandings
{
    public function __construct(private ScoreCalculator $scores) {}

    /**
     * This month so far, each member measured on their own calendar.
     *
     * @return Collection<int, Standing>
     */
    public function current(): Collection
    {
        return $this->build(
            fn (User $user): string => $user->clock()->monthStart()->toDateString(),
            fn (User $user): string => $user->clock()->today()->toDateString(),
            fn (User $user): int => $user->clock()->daysElapsedThisMonth(),
        );
    }

    /**
     * A finished month, every day of it counted. Used once, when the month is
     * closed and the result is frozen onto the recap.
     *
     * @return Collection<int, Standing>
     */
    public function forMonth(CarbonImmutable $month): Collection
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        return $this->build(
            fn (User $user): string => $start->toDateString(),
            fn (User $user): string => $end->toDateString(),
            fn (User $user): int => $start->daysInMonth,
        );
    }

    /**
     * @param  callable(User): string  $firstDay
     * @param  callable(User): string  $lastDay
     * @param  callable(User): int  $daysCounted
     * @return Collection<int, Standing>
     */
    private function build(callable $firstDay, callable $lastDay, callable $daysCounted): Collection
    {
        $users = User::query()->orderBy('name')->get();

        if ($users->isEmpty()) {
            /** @var Collection<int, Standing> */
            return collect();
        }

        $goals = Goal::query()->active()->get(['id', 'user_id']);
        $goalCounts = $goals->groupBy('user_id')->map(fn (Collection $userGoals): int => $userGoals->count());

        $earliest = $users->map($firstDay)->min();

        $marksByUser = Mark::query()
            ->whereIn('goal_id', $goals->pluck('id')->values()->all())
            ->where('marked_on', '>=', $earliest)
            ->get(['user_id', 'marked_on', 'photo_key'])
            ->groupBy('user_id');

        $standings = $users
            // A member needs at least one active goal to appear in the table.
            ->filter(fn (User $user): bool => $goalCounts->get($user->id, 0) > 0)
            ->map(function (User $user) use ($marksByUser, $goalCounts, $firstDay, $lastDay, $daysCounted): Standing {
                $from = $firstDay($user);
                $to = $lastDay($user);

                /** @var Collection<int, Mark> $userMarks */
                $userMarks = $marksByUser->get($user->id, collect());

                $inWindow = $userMarks->filter(function (Mark $mark) use ($from, $to): bool {
                    $date = $mark->marked_on->toDateString();

                    return $date >= $from && $date <= $to;
                });

                $full = $inWindow->filter(fn (Mark $mark): bool => $mark->photo_key !== null)->count();

                return new Standing(
                    userId: $user->id,
                    name: $user->name,
                    fullMarks: $full,
                    ghostMarks: $inWindow->count() - $full,
                    possibleMarks: $daysCounted($user) * $goalCounts->get($user->id, 0),
                    rank: 0,
                );
            })
            ->all();

        return $this->scores->rank(array_values($standings));
    }
}
