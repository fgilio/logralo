<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The recap landed in the feed and the month is scored.
 *
 * Once a month, to everybody, including the winner. The scheduler closes a
 * month at an hour nobody is looking at the app, which is the whole reason
 * this one is worth a buzz.
 */
final class MonthClosed extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $month  Y-m */
    public function __construct(
        private readonly string $month,
        private readonly ?string $winnerName,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = CarbonImmutable::parse("{$this->month}-01")->translatedFormat('F');

        return (new WebPushMessage)
            ->title("Se cerró {$name}")
            ->body($this->winnerName === null
                ? 'Mirá cómo quedó la tabla.'
                : "Ganó {$this->winnerName}. Mirá cómo quedó la tabla.")
            ->icon('/icons/icon-192.png')
            ->tag("recap:{$this->month}")
            ->data(['url' => '/']);
    }
}
