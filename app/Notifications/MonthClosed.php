<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The recap landed in the feed and the month is scored.
 *
 * Once a month, to everybody, including the winner. The scheduler closes a
 * month at an hour nobody is looking at the app, which is the whole reason
 * this one is worth a buzz.
 */
final class MonthClosed extends PushNotification
{
    /**
     * @param  string  $month  Y-m
     * @param  string  $winnerNames  as RecapEntry spells them: "Franco y Guido" on a tie
     * @param  string  $winnerLabel  the verb that agrees with them, from the same class
     */
    public function __construct(
        private readonly string $month,
        private readonly string $winnerNames,
        private readonly string $winnerLabel,
    ) {}

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = CarbonImmutable::parse("{$this->month}-01")->translatedFormat('F');

        return $this->message()
            ->title("Se cerró {$name}")
            ->body($this->winnerNames === ''
                ? 'Mirá cómo quedó la tabla.'
                : "{$this->winnerLabel} {$this->winnerNames}. Mirá cómo quedó la tabla.")
            ->tag("recap:{$this->month}");
    }
}
