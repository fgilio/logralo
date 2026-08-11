<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Goal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Archiving keeps everything — history, photos, past scores — and only stops
 * the goal from counting from today on. Nothing is ever deleted.
 */
final class ArchiveGoal
{
    public function handle(Goal $goal): Goal
    {
        Context::add('logralo.user_id', $goal->user_id);
        Context::add('logralo.goal_id', $goal->id);

        try {
            if (! $goal->isArchived()) {
                $goal->update(['archived_at' => CarbonImmutable::now()]);
            }

            Context::add('logralo.outcome', 'completed');

            return $goal;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('goal.archive.handled');
        }
    }
}
