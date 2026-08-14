<?php

declare(strict_types=1);

use App\Actions\RecordShareVisit;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Taking a link back, and counting who opened it.
 *
 * The token is the whole of the access control, so revoking has to actually
 * kill the URL and take the rendered card off the bucket with it — a card left
 * behind is a photo still reachable by anyone who noted its address.
 */
function markFor(User $user): Mark
{
    $goal = Goal::factory()->for($user)->create();

    return Mark::factory()->for($goal)->create([
        'user_id' => $user->id,
        'marked_on' => now()->toDateString(),
    ]);
}

it('kills the link and the rendered card', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $mark = markFor($user);
    $token = (string) $mark->share_token;

    $this->get(route('share.card', ['token' => $token, 'format' => 'og']))->assertOk();
    expect(Storage::disk('photos')->exists("shares/{$token}/og.jpg"))->toBeTrue();

    $this->actingAs($user)->post(route('share.revoke', $token))->assertRedirect(route('today'));

    expect($mark->refresh()->share_token)->toBeNull()
        ->and(Storage::disk('photos')->exists("shares/{$token}/og.jpg"))->toBeFalse();

    $this->get("/l/{$token}")->assertNotFound();
    $this->get(route('share.card', ['token' => $token, 'format' => 'og']))->assertNotFound();
});

it('lets nobody but the author revoke a mark', function (): void {
    $mark = markFor(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('share.revoke', $mark->share_token))
        ->assertForbidden();

    expect($mark->refresh()->share_token)->not->toBeNull();
});

it('will not let a stranger revoke anything', function (): void {
    $mark = markFor(User::factory()->create());

    $this->post(route('share.revoke', $mark->share_token))->assertRedirect(route('login'));
});

it('counts a person who opens the link', function (): void {
    $mark = markFor(User::factory()->create());

    $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/605.1')
        ->get("/l/{$mark->share_token}")
        ->assertOk();

    expect($mark->refresh()->share_views)->toBe(1)
        ->and($mark->share_last_viewed_at)->not->toBeNull();
});

it('does not count the preview crawler as a visitor', function (string $agent): void {
    // WhatsApp fetches a link once to build its preview. Counting that would
    // report a view for every message sent, opened or not.
    $mark = markFor(User::factory()->create());

    $this->withHeader('User-Agent', $agent)->get("/l/{$mark->share_token}")->assertOk();

    expect($mark->refresh()->share_views)->toBe(0);
})->with([
    'whatsapp' => ['WhatsApp/2.23.20.0'],
    'facebook' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
    'telegram' => ['TelegramBot (like TwitterBot)'],
    'no agent at all' => [''],
]);

it('leaves the mark itself untouched when it counts a visit', function (): void {
    $mark = markFor(User::factory()->create());
    $before = $mark->updated_at;

    $this->travel(1)->hour();

    resolve(RecordShareVisit::class)->handle($mark, 'Mozilla/5.0 Safari');

    // A visit is not a change to the mark. Touching updated_at would reorder
    // the feed every time somebody opened an old link.
    expect($mark->refresh()->updated_at?->timestamp)->toBe($before?->timestamp);
});
