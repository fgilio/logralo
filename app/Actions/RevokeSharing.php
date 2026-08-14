<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Services\ShareCardRenderer;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taking a shared link back.
 *
 * Clearing the token is what kills it: the URL is the only credential, so a
 * link already sent stops resolving the moment the row stops matching. The
 * rendered cards go with it, because a card left on the bucket is a photo
 * still reachable by anyone who noted its address.
 *
 * Sharing the same mark again mints a fresh token, which is also the way to
 * replace a link that went somewhere it should not have.
 */
final readonly class RevokeSharing
{
    public function __construct(private ShareCardRenderer $cards) {}

    public function handle(Mark|MonthlyRecap $shared): void
    {
        Context::add('logralo.share_token', $shared->share_token);
        Context::add('logralo.shared_type', class_basename($shared));

        try {
            if (! $shared->isShareable()) {
                Context::add('logralo.outcome', 'already-revoked');

                return;
            }

            $this->cards->forget($shared->shareCardDirectory());

            $shared->forceFill(['share_token' => null])->saveQuietly();

            Context::add('logralo.outcome', 'revoked');
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('share.revoke.handled');
        }
    }
}
