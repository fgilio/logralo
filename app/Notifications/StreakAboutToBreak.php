<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The only nudge a member gets about their own goals, and it is sent when
 * something is actually about to be lost.
 *
 * Not "you have goals left today": that fires every evening for anyone who
 * does not mark all five, which is how an app gets silenced. A run that ends
 * at the grace cutoff is rare and cannot be recovered afterwards.
 */
final class StreakAboutToBreak extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $closesAt  H:i, the member's own clock */
    public function __construct(
        private readonly int $goalsAtRisk,
        private readonly int $longestStreak,
        private readonly string $closesAt,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->goalsAtRisk === 1
                ? "Se te va una racha de {$this->longestStreak} días"
                : "Se te van {$this->goalsAtRisk} rachas")
            ->body("Marcá ayer antes de las {$this->closesAt}.")
            ->icon('/icons/icon-192.png')
            // Replaced rather than repeated if a later run ever reaches the
            // same member twice in one window.
            ->tag('grace')
            ->data(['url' => '/']);
    }
}
