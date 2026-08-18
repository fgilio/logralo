<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Services\ScoreCalculator;
use App\ValueObjects\Standing;
use App\ValueObjects\UserClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The monthly table behind the pulse strip.
 *
 * Only marks on goals that were active on the window's last day count. That is
 * what "archiving stops the goal counting from that day" means in the score:
 * archiving removes a goal from both sides of the ratio, so it can neither
 * inflate a member's percentage nor be used to game the last day of the month.
 *
 * Private goals sit outside the ratio the same way: the table ranks
 * what the group can witness, and nothing else.
 */
final readonly class MonthlyStandings
{
    public function __construct(private ScoreCalculator $scores, private Members $members) {}

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
        $users = $this->members->roster();

        if ($users->isEmpty()) {
            /** @var Collection<int, Standing> */
            return collect();
        }

        $goalsByUser = Goal::query()
            ->sharedWithGroup()
            ->get(['id', 'user_id', 'created_at', 'archived_at', 'streak_pauses'])
            ->groupBy('user_id');

        // Counted as of the window's last day rather than as of now. A month
        // closes hours after it ends, so a goal created in that
        // gap belongs to no part of it.
        /** @var Collection<string, Collection<int, Goal>> $countedGoals */
        $countedGoals = $users->mapWithKeys(function (User $user) use ($goalsByUser, $lastDay): array {
            /** @var Collection<int, Goal> $goals */
            $goals = $goalsByUser->get($user->id, collect());

            $clock = $user->clock();
            $lastDayOfWindow = $lastDay($user);

            return [$user->id => $goals->filter(fn (Goal $goal): bool => $this->existedBy($goal, $clock, $lastDayOfWindow)
                && $goal->wasActiveOn($lastDayOfWindow, $clock)
            )];
        });

        $goalCounts = $countedGoals->map(fn (Collection $goals): int => $goals->count());

        $earliest = $users->map($firstDay)->min();

        // Bounded at both ends. Only the lower bound matters for the live
        // table — nobody marks the future — but `forMonth()` closes a month
        // that may be a year behind, and without the ceiling it fetched every
        // mark from that month up to today to then throw most of them away.
        $latest = $users->map($lastDay)->max();

        // The same goals the denominator counts, so a grace mark on a goal the
        // window excludes cannot push a frozen score past 100%.
        $marksByUser = Mark::query()
            ->whereIn('goal_id', $countedGoals->collapse()->pluck('id')->all())
            ->whereBetween('marked_on', [$earliest, $latest])
            ->get(['user_id', 'marked_on', 'photo_key'])
            ->groupBy('user_id');

        $standings = $users
            // A member needs at least one counted goal to appear in the table.
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

    /**
     * Whether the goal was on the member's board by $lastDay, read on
     * their own calendar. A row with no created_at predates
     * every month that can be asked about.
     */
    private function existedBy(Goal $goal, UserClock $clock, string $lastDay): bool
    {
        return $goal->created_at === null
            || $clock->dayOf($goal->created_at)->toDateString() <= $lastDay;
    }
}
