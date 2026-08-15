<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\DayClosedException;
use App\Exceptions\UserFacingException;
use App\Models\Mark;
use App\Services\PhotoProcessor;
use App\Services\ShareCardRenderer;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Undoing a mark. Allowed while the day is still open — a mistap should not
 * cost a day — and refused once the day has closed, which is what makes the
 * feed trustworthy.
 */
final readonly class UnmarkGoal
{
    public function __construct(
        private PhotoProcessor $photos,
        private ShareCardRenderer $cards,
    ) {}

    public function handle(Mark $mark): void
    {
        Context::add('logralo.user_id', $mark->user_id);
        Context::add('logralo.goal_id', $mark->goal_id);
        Context::add('logralo.mark_id', $mark->id);
        Context::add('logralo.marked_on', $mark->marked_on->toDateString());

        try {
            if (! $mark->user->clock()->isOpen($mark->marked_on)) {
                throw DayClosedException::on($mark->marked_on);
            }

            $photoKey = $mark->photo_key;
            $cards = $mark->shareCardDirectory();

            $mark->delete();

            $this->photos->delete($photoKey);

            // The rendered cards go too. They have the photo composited into
            // them, so leaving them is the thing `RevokeSharing` exists to
            // prevent — a picture of somebody still on the bucket after the
            // mark it belonged to is gone.
            $this->cards->forget($cards);

            Context::add('logralo.outcome', 'completed');
        } catch (UserFacingException $exception) {
            Context::add('logralo.outcome', 'rejected');
            Context::add('logralo.reject_reason', class_basename($exception));

            throw $exception;
        } catch (Throwable $exception) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $exception->getMessage());
            Context::add('logralo.error_class', $exception::class);

            throw $exception;
        } finally {
            Log::info('mark.remove.handled');
        }
    }
}
