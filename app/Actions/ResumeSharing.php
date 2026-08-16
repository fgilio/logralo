<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sharing something again after it was taken back.
 *
 * The new token is a new link, and the old one stays dead — which is the point.
 * Revoking is how a link that went somewhere it should not have gets killed,
 * and this is how the member who killed it gets to change their mind without
 * resurrecting the address they were running from.
 *
 * A row that is already shared keeps the token it has. Rotating it here would
 * quietly break links the group is still passing around.
 */
final readonly class ResumeSharing
{
    public function handle(Mark|MonthlyRecap $shared): void
    {
        Context::add('logralo.shared_id', $shared->getKey());
        Context::add('logralo.shared_type', class_basename($shared));

        try {
            if ($shared->isShareable()) {
                Context::add('logralo.outcome', 'already_shared');

                return;
            }

            $shared->forceFill(['share_token' => $shared::freshShareToken()])->saveQuietly();

            Context::add('logralo.share_token', $shared->share_token);
            Context::add('logralo.outcome', 'resumed');
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('share.resume.handled');
        }
    }
}
