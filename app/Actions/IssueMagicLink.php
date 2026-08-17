<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mints the link a member joins with.
 *
 * The link goes out over WhatsApp, which means it lives forever in a chat
 * backup, so the signature expiry is only the outer bound. The inner bound is
 * the token: it is stored hashed, it is burned on first use, and issuing a new
 * one revokes whatever was sent before.
 */
final readonly class IssueMagicLink
{
    public function handle(User $user): string
    {
        Context::add('logralo.user_id', $user->id);

        try {
            $plain = Str::random(48);

            $user->forceFill([
                'magic_link_token' => hash('sha256', $plain),
                'magic_link_sent_at' => CarbonImmutable::now(),
                'magic_link_used_at' => null,
            ])->save();

            Context::add('logralo.outcome', 'issued');

            return URL::temporarySignedRoute(
                'magic-link.show',
                CarbonImmutable::now()->addDays(config()->integer('logralo.magic_link.ttl_days')),
                ['user' => $user->id, 'token' => $plain],
            );
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('magic_link.issue.handled');
        }
    }
}
