<?php

declare(strict_types=1);

namespace App\Services;

use App\ValueObjects\Standing;
use Illuminate\Support\Collection;

/**
 * Turns raw mark counts into the monthly table.
 *
 * The score itself lives on Standing; what this adds is the ordering and the
 * shared podium — tied members get the same rank, and the rank after a tie
 * skips (1, 1, 3), so "runner-up" never means "beat nobody".
 */
final class ScoreCalculator
{
    /**
     * @param  list<Standing>  $standings  ranks on the way in are ignored
     * @return Collection<int, Standing>
     */
    public function rank(array $standings): Collection
    {
        // An explicit comparator, not sortBy([...]): Laravel reads an array of
        // closures as ($a, $b) comparators, so one-argument key functions
        // there silently leave the table unsorted.
        $sorted = collect($standings)
            ->sort(fn (Standing $a, Standing $b): int => $b->score() <=> $a->score()
                ?: strcmp($a->name, $b->name))
            ->values();

        $rank = 0;
        $seen = 0;
        $previous = null;

        return $sorted->map(function (Standing $standing) use (&$rank, &$seen, &$previous): Standing {
            $seen++;
            $score = round($standing->score(), 6);

            if ($score !== $previous) {
                $rank = $seen;
                $previous = $score;
            }

            return $standing->withRank($rank);
        });
    }
}
