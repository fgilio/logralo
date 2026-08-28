<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dropping one browser's subscription, from the toggle in the profile.
 *
 * Only this member's rows are touched. An endpoint belonging to somebody else
 * is left alone rather than refused, because the browser sends whatever it has
 * and a stale one is not worth an error on the way out.
 */
final readonly class UnsubscribeFromPush
{
    public function handle(User $user, string $endpoint): void
    {
        Context::add('logralo.user_id', $user->id);

        try {
            $user->deletePushSubscription($endpoint);

            Context::add('logralo.outcome', 'completed');
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('push.unsubscribe.handled');
        }
    }
}
