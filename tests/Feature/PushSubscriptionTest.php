<?php

declare(strict_types=1);

use App\Actions\SubscribeToPush;
use App\Actions\UnsubscribeFromPush;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use NotificationChannels\WebPush\PushSubscription;

const PUSH_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc';

/** What `PushSubscription.toJSON()` hands back in a browser. */
function browserSubscription(string $endpoint = PUSH_ENDPOINT): array
{
    return [
        'endpoint' => $endpoint,
        'expirationTime' => null,
        'keys' => ['p256dh' => 'BNc'.str_repeat('x', 40), 'auth' => str_repeat('y', 22)],
    ];
}

function subscribe(User $user, string $endpoint = PUSH_ENDPOINT): PushSubscription
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

    expect($subscription->endpoint)->toBe(PUSH_ENDPOINT)
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
        ->and(Context::all())->not->toContain(PUSH_ENDPOINT);
});

it('drops only the endpoint it was handed', function (): void {
    $user = User::factory()->create();

    subscribe($user, 'https://fcm.googleapis.com/fcm/send/phone');
    subscribe($user, 'https://fcm.googleapis.com/fcm/send/laptop');

    resolve(UnsubscribeFromPush::class)->handle($user, 'https://fcm.googleapis.com/fcm/send/phone');

    expect($user->pushSubscriptions()->pluck('endpoint')->all())
        ->toBe(['https://fcm.googleapis.com/fcm/send/laptop']);
});

it("leaves somebody else's rows alone when an endpoint is not theirs", function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    subscribe($owner);

    resolve(UnsubscribeFromPush::class)->handle($other, PUSH_ENDPOINT);

    expect($owner->pushSubscriptions()->count())->toBe(1);
});

it('subscribes and unsubscribes from the profile toggle', function (): void {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('subscribeToPush', browserSubscription());

    expect($user->pushSubscriptions()->count())->toBe(1);

    $component->call('unsubscribeFromPush', PUSH_ENDPOINT);

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
        ->call('subscribeToPush', ['endpoint' => PUSH_ENDPOINT])
        ->assertHasErrors('keys.p256dh');

    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('refuses an endpoint aimed at an address rather than a push service', function (string $endpoint): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('subscribeToPush', [...browserSubscription(), 'endpoint' => $endpoint])
        ->assertHasErrors('endpoint');

    expect($user->pushSubscriptions()->count())->toBe(0);
})->with([
    'a private address' => 'https://10.0.0.5/wpush/v2/abc',
    'the loopback' => 'https://127.0.0.1/wpush/v2/abc',
    'link-local' => 'https://169.254.169.254/wpush/v2/abc',
    'a port of its own' => 'https://fcm.googleapis.com:8080/fcm/send/abc',
]);

it('keeps the endpoint out of the log when the write itself fails', function (): void {
    // A real query exception rather than a stand-in, because what is being
    // checked is what the driver puts in the message: its own bindings.
    Schema::drop('push_subscriptions');

    $user = User::factory()->create();

    expect(fn (): PushSubscription => subscribe($user))->toThrow(QueryException::class);

    expect(Context::get('logralo.error'))->toBe('subscription_update_failed')
        ->and(collect(Context::all())->filter(
            fn (mixed $value): bool => is_string($value) && str_contains($value, PUSH_ENDPOINT)
        ))->toBeEmpty();
});
