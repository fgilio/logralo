<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

/**
 * Storing the endpoint a browser handed over when a member said yes.
 *
 * One row per browser, not per member: the same person on a phone and a laptop
 * is two subscriptions, and both get the buzz. The push service is what expires
 * them, and it says so through a 404 or 410 on the next send.
 */
final readonly class SubscribeToPush
{
    public function handle(
        User $user,
        string $endpoint,
        string $key,
        string $token,
    ): PushSubscription {
        Context::add('logralo.user_id', $user->id);
        // Never the endpoint itself. Anyone holding it can push to that
        // browser, so the host is all that belongs in a log line.
        Context::add('logralo.push_service', Uri::of($endpoint)->host() ?? 'unknown');

        try {
            $subscription = $user->updatePushSubscription($endpoint, $key, $token);

            Context::add('logralo.outcome', 'completed');

            return $subscription;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('push.subscribe.handled');
        }
    }
}
