<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\PhotoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Putting a member's own face on their avatar.
 *
 * The upload is stored before the column moves and the old key is deleted
 * after, so a failure anywhere in the middle leaves the member looking at the
 * picture they already had rather than at a broken image.
 */
final readonly class UpdateAvatar
{
    public function __construct(private PhotoProcessor $photos) {}

    public function handle(User $user, UploadedFile $photo): User
    {
        Context::add('logralo.user_id', $user->id);

        $previous = $user->avatar_key;

        try {
            $key = $this->photos->storeAvatar($photo);

            $user->update(['avatar_key' => $key]);

            Context::add('logralo.avatar_key', $key);
            Context::add('logralo.outcome', 'completed');

            // Only once the row points somewhere else. Deleting first would
            // blank every avatar on screen for as long as the write took, and
            // for good if it never landed.
            $this->photos->delete($previous);

            return $user;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('avatar.update.handled');
        }
    }
}
