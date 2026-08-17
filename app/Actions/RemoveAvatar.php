<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\PhotoProcessor;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taking an uploaded avatar back off.
 *
 * Not a reset to nothing: with the upload gone the browser goes back to trying
 * the member's Gravatar, and the coloured initials are what shows if that has
 * nothing either. So this is "stop using the one I uploaded", and a member who
 * wants no picture at all wants no Gravatar, which is a Gravatar setting.
 */
final readonly class RemoveAvatar
{
    public function __construct(private PhotoProcessor $photos) {}

    public function handle(User $user): User
    {
        Context::add('logralo.user_id', $user->id);
        Context::add('logralo.avatar_key', $user->avatar_key ?? '');

        $removed = $user->avatar_key;

        try {
            $user->update(['avatar_key' => null]);

            $this->photos->delete($removed);

            Context::add('logralo.outcome', $removed === null ? 'already_removed' : 'completed');

            return $user;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('avatar.remove.handled');
        }
    }
}
