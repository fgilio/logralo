<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReactionEmoji;
use App\Models\Mark;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One reaction per member per card. Tapping the same emoji removes it, tapping
 * a different one swaps it. No notifications — reactions are found by looking.
 */
final readonly class ToggleReaction
{
    public function handle(Mark $mark, User $user, ReactionEmoji $emoji): ?Reaction
    {
        Context::add('logralo.user_id', $user->id);
        Context::add('logralo.mark_id', $mark->id);
        Context::add('logralo.reaction', $emoji->value);

        try {
            $existing = $mark->reactions()->where('user_id', $user->id)->first();

            if ($existing === null) {
                $reaction = $mark->reactions()->create([
                    'user_id' => $user->id,
                    'emoji' => $emoji,
                ]);

                Context::add('logralo.outcome', 'added');

                return $reaction;
            }

            if ($existing->emoji === $emoji) {
                $existing->delete();

                Context::add('logralo.outcome', 'removed');

                return null;
            }

            $existing->update(['emoji' => $emoji]);

            Context::add('logralo.outcome', 'swapped');

            return $existing;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('reaction.toggle.handled');
        }
    }
}
