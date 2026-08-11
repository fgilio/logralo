<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\GoalLimitReachedException;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adding a goal to the grid. The grid is the product, so the cap on how many
 * goals fit in it is enforced here, not only in the form.
 */
final class CreateGoal
{
    public function handle(User $user, string $emoji, string $name): Goal
    {
        Context::add('logralo.user_id', $user->id);

        try {
            if ($user->activeGoals()->count() >= (int) config('logralo.goals.max_active')) {
                Context::add('logralo.outcome', 'rejected');
                Context::add('logralo.reject_reason', 'goal_limit_reached');

                throw GoalLimitReachedException::make();
            }

            $goal = $user->goals()->create([
                'emoji' => $emoji,
                'name' => $name,
                'position' => (int) $user->goals()->max('position') + 1,
            ]);

            Context::add('logralo.goal_id', $goal->id);
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
            Log::info('goal.create.handled');
        }
    }
}
