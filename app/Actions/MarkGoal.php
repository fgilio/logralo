<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\DayClosedException;
use App\Exceptions\DuplicateMarkException;
use App\Exceptions\GoalArchivedException;
use App\Exceptions\MonthClosedException;
use App\Exceptions\PhotoRequiredException;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\User;
use App\Notifications\StreakMilestoneReached;
use App\Queries\GoalHistory;
use App\Queries\Members;
use App\Services\PhotoProcessor;
use App\Services\PhotoRule;
use App\Services\StreakCalculator;
use App\Services\StreakMilestone;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
        private StreakCalculator $streaks,
        private StreakMilestone $milestones,
        private Members $members,
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

            $streak = $this->streakEndingOn($goal, $day);

            Context::add('logralo.mark_id', $mark->id);
            Context::add('logralo.mark_kind', $mark->kind()->value);
            Context::add('logralo.streak', $streak);
            Context::add('logralo.announced_to', $this->announce($goal, $streak));
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
            $this->history->for($goal)->recentFullnessBefore($day->toDateString())
        );
    }

    /**
     * The streak the marked day ends on.
     *
     * Counted back from that day rather than from today, because inside the
     * grace window those are different numbers: marking yesterday while today
     * is still unmarked would otherwise report today's run.
     */
    public function streakEndingOn(Goal $goal, CarbonImmutable $day): int
    {
        $history = $this->history->for($goal);

        return $this->streaks->endingOn($history->dates(), $day, $history->pauses);
    }

    /**
     * How many of the others were told, which is nobody unless the streak
     * landed on one of the round numbers the app celebrates.
     *
     * A private goal never announces: the flame is real, but the group cannot
     * see the goal it belongs to, and the notification names it.
     *
     * Wrapped so a queue that will not take the job cannot fail the tap that
     * earned it. The mark is the deliverable, the buzz is a courtesy.
     */
    private function announce(Goal $goal, int $streak): int
    {
        if ($goal->isPrivate() || ! $this->milestones->isMilestone($streak)) {
            return 0;
        }

        $others = $this->members->roster()
            ->reject(fn (User $member): bool => $member->is($goal->user));

        return rescue(function () use ($goal, $others, $streak): int {
            Notification::send($others, new StreakMilestoneReached(
                memberName: $goal->user->name,
                goalEmoji: $goal->emoji,
                goalName: $goal->name,
                streak: $streak,
            ));

            return $others->count();
        }, 0);
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

        // The clocks that closed the month are not the clocks that open this
        // day: a member who moves west, or a raised grace hour, reopens a
        // day whose score the recap already froze.
        if (MonthlyRecap::query()->covering($day)->exists()) {
            throw MonthClosedException::on($day);
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
            config()->integer('logralo.goals.note_max_length'),
            end: '',
        );

        return $clean->isEmpty() ? null : $clean->toString();
    }
}
