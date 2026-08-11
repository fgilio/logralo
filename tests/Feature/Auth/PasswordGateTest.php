<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('holds a passwordless member on the set-password screen', function (string $route): void {
    $this->actingAs(User::factory()->withoutPassword()->create())
        ->get(route($route))
        ->assertRedirect(route('password.create'));
})->with(['today', 'profile']);

it('leaves the set-password screen itself reachable', function (): void {
    $this->actingAs(User::factory()->withoutPassword()->create())
        ->get(route('password.create'))
        ->assertOk()
        ->assertSee('Elegí una contraseña');
});

it('leaves the way out reachable', function (): void {
    // Deliberately outside the gate: a member who does not want to finish must
    // still be able to log out instead of being stuck in a loop.
    $this->actingAs(User::factory()->withoutPassword()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));
});

it('gets out of the way once the password exists', function (string $route): void {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertOk();
})->with(['today', 'profile']);

it('sends guests to the login screen before the gate ever runs', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['today', 'profile']);

it('is registered under the password.set alias on both member screens', function (string $route): void {
    $middleware = Route::getRoutes()->getByName($route)->gatherMiddleware();

    expect($middleware)->toContain('auth')->toContain('password.set');
})->with(['today', 'profile']);
