<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\Reaction;
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

it('reacts to a card from the bar the plus button opens', function (): void {
    $ana = User::factory()->create(['name' => 'Ana Pérez']);
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $mark = Mark::factory()->for(Goal::factory()->for($bruno)->create(['name' => 'Guitarra']))->create();

    $this->actingAs($ana);

    visit('/')->on()->iPhone15Pro()
        ->assertSee('Guitarra')
        ->click('@react-open-'.$mark->id)
        ->wait(1)
        ->click('@react-'.$mark->id.'-clap')
        ->wait(1)
        ->assertVisible('@reactions-'.$mark->id)
        ->assertNoJavaScriptErrors();

    $reaction = Reaction::query()->sole();

    expect($reaction->user_id)->toBe($ana->id)
        ->and($reaction->mark_id)->toBe($mark->id);
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

it('leaves the goal name the wide half of the new-goal sheet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Flux copies `class` onto its own `w-full` wrapper, which once let the
    // emoji box eat the row and squeezed the name box down to a sliver.
    visit('/perfil')->on()->iPhone15Pro()
        ->click('@new-goal')
        ->wait(1)
        ->assertScript("document.querySelector('[data-test=goal-name]').clientWidth > 2 * document.querySelector('[data-test=goal-emoji]').clientWidth")
        ->type('@goal-name', 'Gimnasio')
        ->click('@save-goal')
        ->wait(1)
        ->assertSee('Gimnasio')
        ->assertNoJavaScriptErrors();

    expect($user->goals()->sole()->name)->toBe('Gimnasio');
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
