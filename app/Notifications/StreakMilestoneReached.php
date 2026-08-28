<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Services\StreakMilestone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Somebody in the group hit a round number, and the rest get told.
 *
 * The one event here that is about another person. It rides the same
 * thresholds as the celebration the marker sees, so the group hears exactly
 * as often as the app already decided is worth interrupting somebody for.
 *
 * Everything it needs is a string or an int rather than a model: the wording
 * is settled the moment the mark lands, and a goal renamed or archived while
 * the job waits in the queue should not change or fail the buzz.
 */
final class StreakMilestoneReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $memberName,
        private readonly string $goalEmoji,
        private readonly string $goalName,
        private readonly int $streak,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $headline = resolve(StreakMilestone::class)->headline($this->streak);

        return (new WebPushMessage)
            ->title("{$this->memberName}: {$headline}")
            ->body("{$this->goalEmoji} {$this->goalName}")
            ->icon('/icons/icon-192.png')
            // Names the event rather than the person, so a redelivery
            // replaces its own line and two goals reaching a milestone on
            // the same day still arrive as two.
            ->tag("milestone:{$this->memberName}:{$this->goalName}:{$this->streak}")
            ->data(['url' => '/']);
    }
}
