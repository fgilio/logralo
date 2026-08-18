<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GoalVisibility;
use App\Models\Goal;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Editing a goal in place: name, emoji, visibility. Allowed at any time,
 * archived or not — history keeps the name it has now, which is what
 * the member recognises.
 *
 * Visibility applies backwards as well as forwards. It is a read
 * filter, not a property of each mark, so flipping it never
 * rewrites history and flipping it back restores the view.
 */
final readonly class RenameGoal
{
    public function handle(Goal $goal, string $emoji, string $name, GoalVisibility $visibility): Goal
    {
        Context::add('logralo.user_id', $goal->user_id);
        Context::add('logralo.goal_id', $goal->id);

        try {
            $goal->update([
                'emoji' => $emoji,
                'name' => $name,
                'visibility' => $visibility,
            ]);

            Context::add('logralo.outcome', 'completed');

            return $goal;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('goal.rename.handled');
        }
    }
}
