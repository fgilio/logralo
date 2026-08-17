<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\GoalLimitReachedException;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adding a goal to the grid. The grid is the product, so the cap on how many
 * goals fit in it is enforced here, not only in the form.
 */
final readonly class CreateGoal
{
    public function handle(User $user, string $emoji, string $name): Goal
    {
        Context::add('logralo.user_id', $user->id);

        try {
            if ($user->activeGoals()->count() >= config()->integer('logralo.goals.max_active')) {
                throw GoalLimitReachedException::make();
            }

            $goal = $user->goals()->create([
                'emoji' => $emoji,
                'name' => $name,
                'position' => $user->nextGoalPosition(),
            ]);

            Context::add('logralo.goal_id', $goal->id);
            Context::add('logralo.outcome', 'completed');

            return $goal;
        } catch (UserFacingException $exception) {
            Context::add('logralo.outcome', 'rejected');
            Context::add('logralo.reject_reason', $exception->reason());

            throw $exception;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('goal.create.handled');
        }
    }
}
