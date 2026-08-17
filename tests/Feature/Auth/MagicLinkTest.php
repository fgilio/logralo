<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/** The link exactly as `logralo:seed-member` mints it. */
function magicLinkFor(User $user, string $plainToken, ?CarbonImmutable $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'magic-link.show',
        $expiresAt ?? CarbonImmutable::now()->addDays(config()->integer('logralo.magic_link.ttl_days')),
        ['user' => $user->id, 'token' => $plainToken],
    );
}

it('renders the interstitial on GET without burning the token', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create(['name' => 'Ana Pérez']);

    $this->get(magicLinkFor($user, 'el-token'))
        ->assertOk()
        ->assertSee('Hola, Ana')
        ->assertSee('Entrar como Ana Pérez');

    // This is the whole point of the two-step flow: WhatsApp fetches the link
    // to build its preview card, and that fetch must leave the token alive.
    $user->refresh();

    expect($user->magic_link_token)->toBe(hash('sha256', 'el-token'))
        ->and($user->magic_link_used_at)->toBeNull();

    $this->assertGuest();
});

it('survives a preview fetch and still works for the member', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();
    $link = magicLinkFor($user, 'el-token');

    $this->get($link)->assertOk();
    $this->get($link)->assertOk();

    $this->post($link)->assertRedirect(route('password.create'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('burns the token on POST and sends a new member to set a password', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();

    $this->post(magicLinkFor($user, 'el-token'))
        ->assertRedirect(route('password.create'));

    $user->refresh();

    expect($user->magic_link_token)->toBeNull()
        ->and($user->magic_link_used_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    $this->assertAuthenticatedAs($user);
});

it('sends a member who already chose a password straight to today', function (): void {
    $user = User::factory()->withMagicLink('el-token')->create();

    $this->post(magicLinkFor($user, 'el-token'))->assertRedirect(route('today'));

    $this->assertAuthenticatedAs($user->fresh());
});

it('does not mint a remember cookie', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();

    $this->post(magicLinkFor($user, 'el-token'))->assertCookieMissing(auth()->guard('web')->getRecallerName());
});

it('rejects a link that was already used', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();
    $link = magicLinkFor($user, 'el-token');

    $this->post($link)->assertRedirect(route('password.create'));

    auth()->logout();
    $this->flushSession();

    $this->post($link)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Ese enlace ya no sirve. Entrá con tu email y contraseña.');

    $this->get($link)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Ese enlace ya no sirve. Entrá con tu email y contraseña.');

    $this->assertGuest();
});

it('rejects a member who never had a link', function (): void {
    $user = User::factory()->withoutPassword()->create();

    $this->get(magicLinkFor($user, 'el-token'))->assertRedirect(route('login'));
    $this->post(magicLinkFor($user, 'el-token'))->assertRedirect(route('login'));

    $this->assertGuest();
});

it('rejects a tampered token before it ever reaches the controller', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();

    $tampered = str_replace('el-token', 'otro-token', magicLinkFor($user, 'el-token'));

    $this->get($tampered)->assertForbidden();
    $this->post($tampered)->assertForbidden();

    expect($user->fresh()->magic_link_token)->toBe(hash('sha256', 'el-token'));
});

it('rejects a swapped member id', function (): void {
    $ana = User::factory()->withoutPassword()->withMagicLink('el-token')->create();
    $beto = User::factory()->withoutPassword()->create();

    $swapped = str_replace($ana->id, $beto->id, magicLinkFor($ana, 'el-token'));

    $this->post($swapped)->assertForbidden();
    $this->assertGuest();
});

it('rejects a signature that has expired', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();

    $link = magicLinkFor($user, 'el-token', CarbonImmutable::now()->addDay());

    $this->travelTo(CarbonImmutable::now()->addDays(2));

    $this->get($link)->assertForbidden();
    $this->post($link)->assertForbidden();

    expect($user->fresh()->magic_link_token)->toBe(hash('sha256', 'el-token'));
});

it('turns an already-authenticated visitor away without burning the link', function (): void {
    $user = User::factory()->withMagicLink('el-token')->create();
    $link = magicLinkFor($user, 'el-token');

    $this->actingAs(User::factory()->create())->get($link)->assertRedirect(route('today'));
    $this->actingAs(User::factory()->create())->post($link)->assertRedirect(route('today'));

    expect($user->fresh()->magic_link_token)->toBe(hash('sha256', 'el-token'));
});

it('registers the magic-link throttle on both halves of the route', function (): void {
    foreach (['magic-link.show', 'magic-link.consume'] as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('signed')
            ->and($route->gatherMiddleware())->toContain('throttle:magic-link');
    }

    expect(RateLimiter::limiter('magic-link'))->not->toBeNull();
});

it('throttles link scanning from one address', function (): void {
    $user = User::factory()->withoutPassword()->withMagicLink('el-token')->create();
    $link = magicLinkFor($user, 'el-token');

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->get($link)->assertOk();
    }

    $this->get($link)->assertStatus(429);
});
