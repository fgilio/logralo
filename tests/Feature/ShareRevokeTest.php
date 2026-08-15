<?php

declare(strict_types=1);

use App\Actions\RecordShareVisit;
use App\Actions\ResumeSharing;
use App\Enums\ShareCardFormat;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Services\ShareCardRenderer;
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

    // Asked for by name rather than spelled out: the design version is part of
    // it, so a redesign that bumps the version should not read as a test that
    // has to be edited to keep passing.
    $card = "shares/{$token}/".ShareCardRenderer::filename(ShareCardFormat::Unfurl);

    $this->get(route('share.card', ['token' => $token, 'format' => 'og']))->assertOk();
    expect(Storage::disk('photos')->exists($card))->toBeTrue();

    $this->actingAs($user)->post(route('share.revoke', $token))->assertRedirect(route('today'));

    expect($mark->refresh()->share_token)->toBeNull()
        ->and(Storage::disk('photos')->exists($card))->toBeFalse();

    $this->get("/l/{$token}")->assertNotFound();
    $this->get(route('share.card', ['token' => $token, 'format' => 'og']))->assertNotFound();
});

it('can share again after revoking, on a link that is not the old one', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $mark = markFor($user);
    $original = (string) $mark->share_token;

    $this->actingAs($user)->post(route('share.revoke', $original));

    resolve(ResumeSharing::class)->handle($mark->refresh());

    // Changing your mind must not resurrect the address you were running from.
    expect($mark->refresh()->share_token)->not->toBeNull()
        ->and($mark->share_token)->not->toBe($original);

    $this->get("/l/{$mark->share_token}")->assertOk();
    $this->get("/l/{$original}")->assertNotFound();
});

it('leaves an already shared link alone when resuming', function (): void {
    $mark = markFor(User::factory()->create());
    $token = $mark->share_token;

    resolve(ResumeSharing::class)->handle($mark);

    // Rotating here would quietly break links the group is still passing round.
    expect($mark->refresh()->share_token)->toBe($token);
});

it('kills the link even if the cards cannot be deleted', function (): void {
    $user = User::factory()->create();
    $mark = markFor($user);

    // Revocation clears the token first and cleans up second, so a storage
    // failure costs bytes rather than privacy.
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('bucket is down'));

    try {
        $this->actingAs($user)->post(route('share.revoke', $mark->share_token));
    } catch (Throwable) {
        // The request blowing up is fine. The link being alive is not.
    }

    expect($mark->refresh()->share_token)->toBeNull();
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

it('does not count the sender checking their own link', function (): void {
    // The number is there to say the message landed somewhere. Opening it
    // yourself is not that.
    $author = User::factory()->create();
    $mark = markFor($author);

    $this->actingAs($author)
        ->withHeader('User-Agent', 'Mozilla/5.0 Safari')
        ->get("/l/{$mark->share_token}")
        ->assertOk();

    expect($mark->refresh()->share_views)->toBe(0);
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

it('keeps every visit when two people open the link at once', function (): void {
    $mark = markFor(User::factory()->create());

    // Two requests, each holding the row as it was before the other wrote.
    // Read-add-write would drop one of these; an atomic increment keeps both.
    $first = Mark::query()->findOrFail($mark->id);
    $second = Mark::query()->findOrFail($mark->id);

    resolve(RecordShareVisit::class)->handle($first, 'Mozilla/5.0 Safari');
    resolve(RecordShareVisit::class)->handle($second, 'Mozilla/5.0 Safari');

    expect($mark->refresh()->share_views)->toBe(2);
});

it('leaves the mark itself untouched when it counts a visit', function (): void {
    $mark = markFor(User::factory()->create());
    $before = $mark->updated_at;

    $this->travel(1)->hour();

    resolve(RecordShareVisit::class)->handle($mark, 'Mozilla/5.0 Safari');

    // A visit is not a change to the mark. Touching updated_at would reorder
    // the feed every time somebody opened an old link.
    expect($mark->refresh()->updated_at?->timestamp)->toBe($before?->timestamp);
});
