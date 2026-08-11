<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\GoalLimitReachedException;
use App\Models\Goal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bringing an archived goal back into the grid. Its old marks come with it, so
 * a streak that was frozen resumes from wherever it actually stood.
 */
final class RestoreGoal
{
    public function handle(Goal $goal): Goal
    {
        Context::add('logralo.user_id', $goal->user_id);
        Context::add('logralo.goal_id', $goal->id);

        try {
            if ($goal->user->activeGoals()->count() >= (int) config('logralo.goals.max_active')) {
                Context::add('logralo.outcome', 'rejected');
                Context::add('logralo.reject_reason', 'goal_limit_reached');

                throw GoalLimitReachedException::make();
            }

            $goal->update([
                'archived_at' => null,
                'position' => (int) $goal->user->goals()->max('position') + 1,
            ]);

            Context::add('logralo.outcome', 'completed');

            return $goal;
        } catch (GoalLimitReachedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $exception->getMessage());
            Context::add('logralo.error_class', $exception::class);

            throw $exception;
        } finally {
            Log::info('goal.restore.handled');
        }
    }
}
