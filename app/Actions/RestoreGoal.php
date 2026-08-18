<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\GoalLimitReachedException;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bringing an archived goal back into the grid. Its old marks come with it, so
 * a streak that was frozen resumes from wherever it actually stood.
 */
final readonly class RestoreGoal
{
    public function handle(Goal $goal): Goal
    {
        Context::add('logralo.user_id', $goal->user_id);
        Context::add('logralo.goal_id', $goal->id);

        try {
            if ($goal->user->activeGoals()->count() >= config()->integer('logralo.goals.max_active')) {
                throw GoalLimitReachedException::make();
            }

            $goal->update([
                'archived_at' => null,
                'position' => $goal->user->nextGoalPosition(),
                'streak_pauses' => $this->streakPausesAfterRestore($goal),
            ]);

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
            Log::info('goal.restore.handled');
        }
    }

    /** @return list<array{from: string, through: string}> */
    private function streakPausesAfterRestore(Goal $goal): array
    {
        if ($goal->archived_at === null) {
            return $goal->streakPauses();
        }

        $clock = $goal->user->clock();
        $archivedAt = $goal->archived_at->setTimezone($clock->timezone);
        $from = $clock->dayOf($goal->archived_at);

        if ($archivedAt->hour < $clock->graceCutoffHour) {
            $from = $from->subDay();
        }

        $through = $clock->today()->subDay();

        if ($through->lessThan($from)) {
            return $goal->streakPauses();
        }

        return [
            ...$goal->streakPauses(),
            [
                'from' => $from->toDateString(),
                'through' => $through->toDateString(),
            ],
        ];
    }
}
