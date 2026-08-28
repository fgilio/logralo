<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Session\TokenMismatchException;

it('logs the member out and sends them back to the login screen', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('throws the rest of the session away with the login', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->withSession(['url.intended' => '/perfil'])
        ->post(route('logout'));

    $response->assertSessionMissing('url.intended');

    expect(session()->all())->not->toHaveKey('url.intended');
});

it('lets a member who never chose a password leave', function (): void {
    $this->actingAs(User::factory()->withoutPassword()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('cannot be triggered by a link, a prefetch or an image tag', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('logout'))
        ->assertStatus(405);

    $this->assertAuthenticated();
});

it('is closed to guests', function (): void {
    $this->post(route('logout'))->assertRedirect(route('login'));
});

it("answers a page left open too long in the app's own voice", function (): void {
    // The form here is the reachable one — a phone that sat on "Hoy" overnight
    // posts a token the session no longer knows — but the answer is the app's
    // for every stale POST, so it is pinned once. Driven through the handler
    // rather than through a request, because CSRF passes unconditionally under
    // the test runner and there is no stale token to send.
    $response = resolve(ExceptionHandler::class)
        ->render(request(), new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419)
        ->and($response->getContent())->toContain('Se venció la página')
        ->and($response->getContent())->toContain('Volver a Logralo')
        ->and($response->getContent())->not->toContain('Page Expired');
});
