<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\EmptyFeedbackException;
use App\Exceptions\UserFacingException;
use App\Mail\FeedbackReceived;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * "¿Qué pasó?" — a member telling us the app broke, or could be better.
 *
 * The row is the deliverable and the mail is the nudge, in that order. Sending
 * it inline matches the rest of the app, where the queue exists but nothing
 * dispatches to it, and it is wrapped in a `rescue` so that a mail provider
 * having a bad afternoon cannot swallow what somebody just typed. A failed
 * notification is reported, and the feedback is still on disk.
 */
final readonly class SendFeedback
{
    public function handle(User $user, string $body, ?string $page = null): Feedback
    {
        Context::add('logralo.user_id', $user->id);

        try {
            $clean = $this->clean($body);

            if ($clean === '') {
                throw EmptyFeedbackException::make();
            }

            $feedback = $user->feedback()->create([
                'body' => $clean,
                'page' => $page,
            ]);

            // The mailable needs the member, and the member is right here.
            // `create()` on a relation does not set the inverse, so without
            // this the nudge re-reads a row we already hold.
            $feedback->setRelation('user', $user);

            Context::add('logralo.feedback_id', $feedback->id);
            Context::add('logralo.notified', $this->notify($feedback));
            Context::add('logralo.outcome', 'completed');

            return $feedback;
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
            Log::info('feedback.send.handled');
        }
    }

    /**
     * The cap belongs to the unit of work, not only to the form in front of
     * it: the sheet validates, but the Action is what always runs. Trimmed
     * rather than squished, unlike a mark's note — a report of any length is
     * somebody's paragraphs, and their line breaks are theirs.
     */
    private function clean(string $body): string
    {
        return Str::of($body)
            ->trim()
            ->limit(config()->integer('logralo.feedback.max_length'), end: '')
            ->toString();
    }

    /** Whether the inbox heard about it. An unset inbox is a choice, not a failure. */
    private function notify(Feedback $feedback): bool
    {
        $inbox = config()->string('logralo.feedback.email');

        if ($inbox === '') {
            return false;
        }

        return rescue(function () use ($inbox, $feedback): bool {
            Mail::to($inbox)->send(new FeedbackReceived($feedback));

            return true;
        }, false);
    }
}
