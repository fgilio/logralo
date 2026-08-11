<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\DayClosedException;
use App\Exceptions\DuplicateMarkException;
use App\Exceptions\GoalArchivedException;
use App\Exceptions\PhotoRequiredException;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use App\Models\Mark;
use App\Queries\GoalHistory;
use App\Services\PhotoProcessor;
use App\Services\PhotoRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Logging a goal for a day. The one action the whole product is built around,
 * so every rule that can refuse a mark is enforced here rather than in the UI.
 */
final readonly class MarkGoal
{
    public function __construct(
        private PhotoProcessor $photos,
        private PhotoRule $photoRule,
        private GoalHistory $history,
    ) {}

    public function handle(
        Goal $goal,
        CarbonImmutable $day,
        ?UploadedFile $photo = null,
        ?string $note = null,
    ): Mark {
        Context::add('logralo.user_id', $goal->user_id);
        Context::add('logralo.goal_id', $goal->id);
        Context::add('logralo.marked_on', $day->toDateString());

        try {
            $this->guard($goal, $day, $photo);

            $stored = $photo instanceof UploadedFile ? $this->photos->store($photo) : null;

            try {
                $mark = $goal->marks()->create([
                    'user_id' => $goal->user_id,
                    'marked_on' => $day->toDateString(),
                    'photo_key' => $stored?->key,
                    'photo_width' => $stored?->width,
                    'photo_height' => $stored?->height,
                    'note' => $this->cleanNote($note),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two taps raced each other; the index is the referee.
                $this->photos->delete($stored?->key);

                throw DuplicateMarkException::make($goal, $day);
            }

            Context::add('logralo.mark_id', $mark->id);
            Context::add('logralo.mark_kind', $mark->kind()->value);
            Context::add('logralo.outcome', 'completed');

            return $mark;
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
            Log::info('mark.create.handled');
        }
    }

    /**
     * Whether the next tap on this goal has to open the camera: two ghost
     * marks in a row and the third needs proof.
     */
    public function requiresPhoto(Goal $goal, CarbonImmutable $day): bool
    {
        return $this->photoRule->requiresPhoto(
            $this->history->for($goal)->recentFullnessBefore(
                $day->toDateString(),
                (int) config('logralo.goals.ghosts_before_camera'),
            )
        );
    }

    private function guard(Goal $goal, CarbonImmutable $day, ?UploadedFile $photo): void
    {
        if ($goal->isArchived()) {
            throw GoalArchivedException::forGoal($goal);
        }

        $openDays = collect($goal->user->clock()->openDays())
            ->map(fn (CarbonImmutable $open): string => $open->toDateString());

        if ($openDays->doesntContain($day->toDateString())) {
            throw DayClosedException::on($day);
        }

        if ($goal->marks()->where('marked_on', $day->toDateString())->exists()) {
            throw DuplicateMarkException::make($goal, $day);
        }

        if (! $photo instanceof UploadedFile && $this->requiresPhoto($goal, $day)) {
            throw PhotoRequiredException::forGoal($goal);
        }
    }

    private function cleanNote(?string $note): ?string
    {
        $clean = Str::of($note ?? '')->squish()->limit(
            (int) config('logralo.goals.note_max_length'),
            end: '',
        );

        return $clean->isEmpty() ? null : $clean->toString();
    }
}
