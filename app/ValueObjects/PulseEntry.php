<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\User;

/**
 * One avatar in the pulse strip: how much of today a member has already
 * closed, in their own timezone.
 */
final readonly class PulseEntry
{
    public function __construct(
        public User $user,
        public int $activeGoals,
        public int $markedToday,
    ) {}

    public function isComplete(): bool
    {
        return $this->activeGoals > 0 && $this->markedToday >= $this->activeGoals;
    }

    /** 0.0 to 1.0, for the ring around the avatar. */
    public function completion(): float
    {
        if ($this->activeGoals === 0) {
            return 0.0;
        }

        return min($this->markedToday / $this->activeGoals, 1.0);
    }
}
