<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\ValueObjects\PulseEntry;
use Illuminate\Support\Collection;

/**
 * The strip at the top of "Hoy": every member's avatar with how much of today
 * they have already closed. "Today" is each member's own local day, so someone
 * in a different timezone is measured against their day, not yours.
 */
final readonly class GroupPulse
{
    public function __construct(private Members $members) {}

    /** @return Collection<int, PulseEntry> */
    public function today(): Collection
    {
        $users = $this->members->roster();

        if ($users->isEmpty()) {
            /** @var Collection<int, PulseEntry> */
            return collect();
        }

        $goals = Goal::query()->active()->get(['id', 'user_id']);
        $goalCounts = $goals->countBy('user_id');

        $localDates = $users->mapWithKeys(
            fn (User $user): array => [$user->id => $user->clock()->today()->toDateString()]
        );

        $marksMarkedToday = Mark::query()
            ->whereIn('goal_id', $goals->pluck('id')->values()->all())
            ->whereIn('marked_on', $localDates->values()->unique()->values()->all())
            ->get(['user_id', 'marked_on'])
            ->countBy(fn (Mark $mark): string => $mark->user_id.'|'.$mark->marked_on->toDateString());

        return $users
            ->map(fn (User $user): PulseEntry => new PulseEntry(
                user: $user,
                activeGoals: $goalCounts->get($user->id, 0),
                markedToday: $marksMarkedToday->get($user->id.'|'.$localDates->get($user->id), 0),
            ))
            ->values();
    }
}
