<?php

declare(strict_types=1);

use App\Actions\SubscribeToPush;
use App\Actions\UnsubscribeFromPush;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use NotificationChannels\WebPush\PushSubscription;

/** What `PushSubscription.toJSON()` hands back in a browser. */
function browserSubscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
{
    return [
        'endpoint' => $endpoint,
        'expirationTime' => null,
        'keys' => ['p256dh' => 'BNc'.str_repeat('x', 40), 'auth' => str_repeat('y', 22)],
    ];
}

function subscribe(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): PushSubscription
{
    $subscription = browserSubscription($endpoint);

    return resolve(SubscribeToPush::class)->handle(
        user: $user,
        endpoint: $subscription['endpoint'],
        key: $subscription['keys']['p256dh'],
        token: $subscription['keys']['auth'],
    );
}

it('stores the endpoint a browser handed over', function (): void {
    $user = User::factory()->create();

    $subscription = subscribe($user);

    expect($subscription->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/abc')
        ->and($subscription->subscribable_id)->toBe($user->id)
        ->and($user->pushSubscriptions()->count())->toBe(1);
});

it('keeps one row per browser however often the same endpoint comes back', function (): void {
    $user = User::factory()->create();

    subscribe($user);
    subscribe($user);

    expect($user->pushSubscriptions()->count())->toBe(1);
});

it('subscribes a member once per browser they say yes on', function (): void {
    $user = User::factory()->create();

    subscribe($user, 'https://fcm.googleapis.com/fcm/send/phone');
    subscribe($user, 'https://updates.push.services.mozilla.com/wpush/v2/laptop');

    expect($user->pushSubscriptions()->count())->toBe(2);
});

it('moves an endpoint to whoever subscribed with it last', function (): void {
    $lender = User::factory()->create();
    $borrower = User::factory()->create();

    subscribe($lender);
    subscribe($borrower);

    expect($lender->pushSubscriptions()->count())->toBe(0)
        ->and($borrower->pushSubscriptions()->count())->toBe(1)
        ->and(PushSubscription::query()->count())->toBe(1);
});

it('logs the push service without ever logging the endpoint', function (): void {
    Log::spy();

    $user = User::factory()->create();
    subscribe($user);

    Log::shouldHaveReceived('info')->with('push.subscribe.handled')->once();

    // The endpoint is a capability: whoever holds it can push to that browser.
    expect(Context::get('logralo.push_service'))->toBe('fcm.googleapis.com')
        ->and(Context::all())->not->toContain('https://fcm.googleapis.com/fcm/send/abc');
});

it('drops only the endpoint it was handed', function (): void {
    $user = User::factory()->create();

    subscribe($user, 'https://fcm.googleapis.com/fcm/send/phone');
    subscribe($user, 'https://fcm.googleapis.com/fcm/send/laptop');

    resolve(UnsubscribeFromPush::class)->handle($user, 'https://fcm.googleapis.com/fcm/send/phone');

    expect($user->pushSubscriptions()->pluck('endpoint')->all())
        ->toBe(['https://fcm.googleapis.com/fcm/send/laptop']);
});

it('leaves somebody else rows alone when an endpoint is not theirs', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    subscribe($owner);

    resolve(UnsubscribeFromPush::class)->handle($other, 'https://fcm.googleapis.com/fcm/send/abc');

    expect($owner->pushSubscriptions()->count())->toBe(1);
});

it('subscribes and unsubscribes from the profile toggle', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('subscribeToPush', browserSubscription());

    expect($user->pushSubscriptions()->count())->toBe(1);

    $component->call('unsubscribeFromPush', 'https://fcm.googleapis.com/fcm/send/abc');

    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('refuses a subscription whose endpoint is not an https URL', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('subscribeToPush', [...browserSubscription(), 'endpoint' => 'javascript:alert(1)'])
        ->assertHasErrors('endpoint');

    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('refuses a subscription with no encryption keys behind it', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('subscribeToPush', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])
        ->assertHasErrors('keys.p256dh');

    expect($user->pushSubscriptions()->count())->toBe(0);
});
