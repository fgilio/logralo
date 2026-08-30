<?php

declare(strict_types=1);

use App\Notifications\PushNotification;

it('sends every notification through the shared push envelope', function (): void {
    // Web Push is the only channel the app has, and the base class is what
    // makes that true: it owns via(), the icon and the url a tap opens. A
    // notification that extends Laravel's directly would compile and send
    // nothing, because nothing here routes mail.
    expect('App\Notifications')->toExtend(PushNotification::class)
        ->ignoring(PushNotification::class);
});

it('keeps every notification final', function (): void {
    expect('App\Notifications')->classes()->toBeFinal()
        ->ignoring(PushNotification::class);
});
