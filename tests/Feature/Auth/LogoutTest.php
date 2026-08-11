<?php

declare(strict_types=1);

use App\Models\User;

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
