<?php

declare(strict_types=1);

use App\Enums\ShareCardFormat;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\User;
use App\Services\ShareCardRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * The public half of the app.
 *
 * A WhatsApp preview is fetched by an anonymous crawler, so the share page has
 * to answer without a session — behind `auth` it unfurled as the login screen,
 * identically for every share the group ever sent. The token is the only
 * credential, and these pin what it does and does not open.
 */
function sharedMark(?User $user = null): Mark
{
    $user ??= User::factory()->create(['name' => 'Guido']);
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    return Mark::factory()->for($goal)->create([
        'user_id' => $user->id,
        'marked_on' => now()->toDateString(),
    ]);
}

it('mints a token for every mark as it is made', function (): void {
    // Minted on create, not on the first tap: navigator.share() has to be
    // called inside the gesture that opened it, and a round trip to fetch a
    // token would spend the activation before the sheet appeared.
    $mark = sharedMark();

    expect($mark->share_token)->toBeString()
        ->and(mb_strlen((string) $mark->share_token))->toBe(24)
        ->and($mark->shareUrl())->toContain($mark->share_token);
});

it('gives every mark its own token', function (): void {
    expect(sharedMark()->share_token)->not->toBe(sharedMark()->share_token);
});

it('opens a shared mark without a session', function (): void {
    $mark = sharedMark();

    $this->get("/l/{$mark->share_token}")
        ->assertOk()
        ->assertSee('Gimnasio')
        ->assertSee('Guido');
});

it('gives the crawler an image to draw', function (): void {
    $mark = sharedMark();

    $response = $this->get("/l/{$mark->share_token}");

    // Without og:image WhatsApp falls back to the favicon-sized tile, which
    // is what every share in the group used to look like.
    $response->assertSee('og:image', escape: false)
        ->assertSee('twitter:card', escape: false)
        ->assertSee('summary_large_image', escape: false)
        ->assertSee(route('share.card', ['token' => $mark->share_token, 'format' => 'og']), escape: false);
});

it('leaves the unfurl to the picture', function (): void {
    $mark = sharedMark();

    // The card already says the goal, the day and the streak. A title naming
    // the sender and a description pitching the app were two lines of grey
    // under a photo that had already made the point.
    $this->get("/l/{$mark->share_token}")
        ->assertSee('property="og:title" content="Logralo"', escape: false)
        ->assertDontSee('og:description', escape: false);
});

it('counts the opens in Spanish', function (): void {
    $mark = sharedMark();
    $mark->forceFill(['share_views' => 7])->save();

    // Str::plural is an English inflector, and its third argument is
    // prependCount rather than the plural form, which is how this line shipped
    // reading "La abrieron 7 7 vezs".
    $this->actingAs($mark->user)
        ->get("/l/{$mark->share_token}")
        ->assertSee('La abrieron 7 veces.')
        ->assertDontSee('vezs');
});

it('counts a single open in the singular', function (): void {
    $mark = sharedMark();
    $mark->forceFill(['share_views' => 1])->save();

    $this->actingAs($mark->user)
        ->get("/l/{$mark->share_token}")
        ->assertSee('La abrieron una vez.');
});

it('shows a stranger the group, not a login form', function (): void {
    $mark = sharedMark();

    $this->get("/l/{$mark->share_token}")
        ->assertOk()
        ->assertSee('grupo de amigos')
        ->assertDontSee('name="password"', escape: false);
});

it('offers a member the way back into the app, anchored on the mark', function (): void {
    $mark = sharedMark();

    $this->actingAs(User::factory()->create())
        ->get("/l/{$mark->share_token}")
        ->assertSee('#mark-'.$mark->id, escape: false);
});

it('refuses a token that never existed', function (): void {
    $this->get('/l/'.str_repeat('a', 24))->assertNotFound();
});

it('refuses a token of the wrong shape before it reaches the database', function (): void {
    $this->get('/l/short')->assertNotFound();
});

it('opens a shared recap', function (): void {
    $recap = MonthlyRecap::factory()->create();

    $this->get("/l/{$recap->share_token}")->assertOk();
});

it('renders the card image and keeps it', function (): void {
    Storage::fake('photos');

    $mark = sharedMark();

    $response = $this->get(route('share.card', ['token' => $mark->share_token, 'format' => 'og']));

    $response->assertOk()->assertHeader('content-type', 'image/jpeg');

    // Composed once and cached on the photo disk, because an unfurl is fetched
    // whenever a chat client feels like it.
    expect(Storage::disk('photos')->exists("shares/{$mark->share_token}/".ShareCardRenderer::filename(ShareCardFormat::Unfurl)))->toBeTrue();
});

it('does not keep serving a card an older design drew', function (): void {
    Storage::fake('photos');

    $mark = sharedMark();

    // What the previous design left on the disk, under the name that design
    // stored it as. Cards are composed once and kept, so without a version in
    // the name every link already sent to a chat would unfurl this forever.
    Storage::disk('photos')->put("shares/{$mark->share_token}/og.jpg", 'drawn last year');

    $response = $this->get(route('share.card', ['token' => $mark->share_token, 'format' => 'og']));

    $response->assertOk()->assertHeader('content-type', 'image/jpeg');
    expect($response->getContent())->not->toBe('drawn last year');
});

it('never lets a cache outlive a revoked card', function (): void {
    Storage::fake('photos');

    $mark = sharedMark();

    $response = $this->get(route('share.card', ['token' => $mark->share_token, 'format' => 'og']));

    // A card can carry a private photo, so no shared cache may store it and
    // every request has to come back through the token lookup. `immutable`
    // would have let a CDN keep serving a revoked photo for a year.
    $cacheControl = (string) $response->headers->get('cache-control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-cache')
        ->and($cacheControl)->not->toContain('immutable')
        ->and($response->headers->get('etag'))->not->toBeNull();
});

it('renders the portrait card for sending the image', function (): void {
    Storage::fake('photos');

    $mark = sharedMark();

    $this->get(route('share.card', ['token' => $mark->share_token, 'format' => 'portrait']))->assertOk();

    expect(Storage::disk('photos')->exists("shares/{$mark->share_token}/".ShareCardRenderer::filename(ShareCardFormat::Portrait)))->toBeTrue();
});

it('refuses a card format it does not draw', function (): void {
    $mark = sharedMark();

    $this->get("/l/{$mark->share_token}/banner.jpg")->assertNotFound();
});

it('unfurls a bare link to the app as Logralo rather than the login page', function (): void {
    Storage::fake('photos');

    $this->get('/og.jpg')->assertOk()->assertHeader('content-type', 'image/jpeg');

    $this->get('/entrar')
        ->assertSee('og:image', escape: false)
        ->assertSee(route('share.default-card'), escape: false);
});
