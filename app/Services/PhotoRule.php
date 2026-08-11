<?php

declare(strict_types=1);

namespace App\Services;

/**
 * "Pics or it didn't happen": a goal tolerates a couple of ghost marks in a
 * row, and then the next tap has to bring a photo.
 *
 * Consecutive counts marks, not days — skipping a day does not launder the
 * ghost run.
 */
final class PhotoRule
{
    /**
     * @param  list<bool>  $recentMarksAreFull  most recent mark first
     */
    public function requiresPhoto(array $recentMarksAreFull): bool
    {
        $limit = (int) config('logralo.goals.ghosts_before_camera');
        $ghosts = 0;

        foreach ($recentMarksAreFull as $isFull) {
            if ($isFull) {
                return false;
            }

            $ghosts++;

            if ($ghosts >= $limit) {
                return true;
            }
        }

        return false;
    }
}
