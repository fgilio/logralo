<?php

declare(strict_types=1);

namespace App\Notifications;

use NotificationChannels\WebPush\WebPushMessage;

/**
 * The only nudge a member gets about their own goals, and it is sent when
 * something is actually about to be lost.
 *
 * Not "you have goals left today": that fires every evening for anyone who
 * does not mark all five, which is how an app gets silenced. A run that ends
 * at the grace cutoff is rare and cannot be recovered afterwards.
 */
final class StreakAboutToBreak extends PushNotification
{
    /** @param  string  $closesAt  H:i, the member's own clock */
    public function __construct(
        private readonly int $goalsAtRisk,
        private readonly int $longestStreak,
        private readonly string $closesAt,
    ) {}

    public function toWebPush(object $notifiable): WebPushMessage
    {
        // Spelled out rather than inflected, the way the flame and the recap
        // card already do it: `Str::plural` is an English inflector. A run of
        // exactly one day is not the edge case it looks like — a goal marked
        // once and then missed is where most first nudges land, and this read
        // "una racha de 1 días" on every one of them.
        $unit = $this->longestStreak === 1 ? 'día' : 'días';

        return $this->message()
            ->title($this->goalsAtRisk === 1
                ? "Se te va una racha de {$this->longestStreak} {$unit}"
                : "Se te van {$this->goalsAtRisk} rachas")
            ->body("Marcá ayer antes de las {$this->closesAt}.")
            // Replaced rather than repeated if a later run ever reaches the
            // same member twice in one window.
            ->tag('grace');
    }
}
