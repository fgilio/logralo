<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;

/**
 * The phone smoke tests.
 *
 * A short list on purpose: a real browser is the only place that proves the
 * Alpine tap handler, the Livewire round trip and the Flux sheet still agree
 * with each other. Everything narrower than that is a feature test.
 */
it('shows the member their goals on a phone, without breaking the console', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio', 'emoji' => '🏋️']);

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertSee('Logralo')
        ->assertSee('Gimnasio')
        ->assertVisible('@goal-card-'.$goal->id)
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'false')
        ->assertNoJavaScriptErrors();
});

it('marks a goal when the card is tapped', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Correr']);

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'false')
        ->click('@goal-card-'.$goal->id)
        ->wait(1)
        ->assertAriaAttribute('@goal-card-'.$goal->id, 'pressed', 'true')
        ->assertNoJavaScriptErrors();

    // No photo went with the tap, so the day is claimed as a ghost mark.
    $mark = Mark::query()->where('goal_id', $goal->id)->sole();

    expect($mark->photo_key)->toBeNull()
        ->and($mark->marked_on->toDateString())->toBe($user->clock()->today()->toDateString());
});

it('opens the month table from the trophy button', function (): void {
    $user = User::factory()->create(['name' => 'Ana Pérez']);
    Goal::factory()->for($user)->create();

    $this->actingAs($user);

    visit('/')->on()->iPhone15Pro()
        ->assertMissing('@standings')
        ->click('@open-standings')
        ->wait(1)
        ->assertVisible('@standings')
        ->assertSeeIn('@standings', 'Ana Pérez')
        ->assertNoJavaScriptErrors();
});

it('renders the login screen and refuses the wrong password', function (): void {
    User::factory()->create(['email' => 'ana@logralo.test']);

    visit('/entrar')->on()->iPhone15Pro()
        ->assertSee('Volvé a la racha')
        ->type('@email', 'ana@logralo.test')
        ->type('@password', 'not-the-password')
        ->click('@submit')
        ->wait(1)
        ->assertPathIs('/entrar')
        ->assertSee(__('auth.failed'))
        ->assertNoJavaScriptErrors();
});
