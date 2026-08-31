<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The envelope every buzz shares, so that only the wording lives in the three
 * classes that send one.
 *
 * Web Push is the only channel: all three events happen while the app is
 * closed, which is the whole reason they are worth a notification. Queued
 * because a send is one HTTPS request per subscribed browser, and neither the
 * tap that marked a goal nor the hourly sweep should wait on them.
 */
abstract class PushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<class-string> */
    final public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * Logralo is one screen, so every buzz opens it, under the app icon. A
     * subclass supplies the title, the body and the tag and nothing else.
     */
    protected function message(): WebPushMessage
    {
        return new WebPushMessage()
            ->icon('/icons/icon-192.png')
            ->data(['url' => '/']);
    }
}
