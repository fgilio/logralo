<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\User;

it('renders the today screen for a member with goals', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Gimnasio', 'emoji' => '🏋️']);

    $this->actingAs($user)
        ->get(route('today'))
        ->assertOk()
        ->assertSee('Gimnasio')
        ->assertSeeLivewire('pages::today');
});

it('sends a member with no goals to the empty state', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('today'))
        ->assertOk()
        ->assertSee('Creá tu primer objetivo');
});

it('renders the profile screen', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('profile'))
        ->assertOk()
        ->assertSee('Objetivos');
});

it('renders the login screen for a guest', function (): void {
    $this->get(route('login'))->assertOk()->assertSee('Entrar');
});

it('sends guests to the login screen', function (): void {
    $this->get(route('today'))->assertRedirect(route('login'));
});

it('holds a member without a password on the password screen', function (): void {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->get(route('today'))->assertRedirect(route('password.create'));
    $this->actingAs($user)->get(route('password.create'))->assertOk()->assertSee('Elegí una contraseña');
});
