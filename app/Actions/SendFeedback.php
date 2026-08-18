<?php

declare(strict_types=1);

namespace App\Actions;

use App\Mail\FeedbackReceived;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            $feedback = $user->feedback()->create([
                'body' => $body,
                'page' => $page,
            ]);

            Context::add('logralo.feedback_id', $feedback->id);
            Context::add('logralo.notified', $this->notify($feedback));
            Context::add('logralo.outcome', 'completed');

            return $feedback;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('feedback.send.handled');
        }
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
