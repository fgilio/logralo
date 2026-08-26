<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\DayClosedException;
use App\Exceptions\MonthClosedException;
use App\Exceptions\PhotoAlreadyAddedException;
use App\Exceptions\UserFacingException;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Services\PhotoProcessor;
use App\Services\ShareCardRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Adds proof to an existing ghost mark while its day is still open. */
final readonly class AttachPhotoToMark
{
    public function __construct(
        private PhotoProcessor $photos,
        private ShareCardRenderer $cards,
    ) {}

    public function handle(Mark $mark, UploadedFile $photo): Mark
    {
        Context::add('logralo.user_id', $mark->user_id);
        Context::add('logralo.goal_id', $mark->goal_id);
        Context::add('logralo.mark_id', $mark->id);
        Context::add('logralo.marked_on', $mark->marked_on->toDateString());

        try {
            $this->guard($mark);

            $stored = $this->photos->store($photo);

            try {
                $updated = Mark::query()
                    ->whereKey($mark->id)
                    ->whereNull('photo_key')
                    ->update([
                        'photo_key' => $stored->key,
                        'photo_width' => $stored->width,
                        'photo_height' => $stored->height,
                    ]);
            } catch (Throwable $throwable) {
                $this->photos->delete($stored->key);

                throw $throwable;
            }

            if ($updated !== 1) {
                $this->photos->delete($stored->key);

                throw PhotoAlreadyAddedException::forMark($mark);
            }

            $mark->refresh();

            if ($mark->isShareable()) {
                $this->cards->forget($mark->shareCardDirectory());
            }

            Context::add('logralo.outcome', 'completed');

            return $mark;
        } catch (UserFacingException $exception) {
            Context::add('logralo.outcome', 'rejected');
            Context::add('logralo.reject_reason', $exception->reason());

            throw $exception;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('mark.photo.attach.handled');
        }
    }

    private function guard(Mark $mark): void
    {
        if (! $mark->user->clock()->isOpen($mark->marked_on)) {
            throw DayClosedException::on($mark->marked_on);
        }

        if (MonthlyRecap::query()->covering($mark->marked_on)->exists()) {
            throw MonthClosedException::on($mark->marked_on);
        }

        if ($mark->photo_key !== null) {
            throw PhotoAlreadyAddedException::forMark($mark);
        }
    }
}
