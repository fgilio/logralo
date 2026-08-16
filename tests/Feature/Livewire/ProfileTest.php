<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/*
 * The profile screen is everything that is not "Hoy": the goal grid, the
 * member's own data, and the password.
 */

it("fills the form with the member's own data", function (): void {
    $user = User::factory()->inTimezone('Europe/Madrid')->create(['name' => 'Ana']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->assertSet('name', 'Ana')
        ->assertSet('timezone', 'Europe/Madrid');
});

it('creates a goal', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('newGoal')
        ->set('goalEmoji', '🏊')
        ->set('goalName', 'Natacion')
        ->call('saveGoal')
        ->assertHasNoErrors()
        // The sheet is emptied so the next "Nuevo objetivo" starts blank.
        ->assertSet('goalName', '')
        ->assertSet('editingGoalId', null)
        ->assertSee('Natacion');

    $goal = $user->goals()->sole();

    expect($goal->emoji)->toBe('🏊')
        ->and($goal->name)->toBe('Natacion')
        ->and($goal->position)->toBe(1)
        ->and($goal->isArchived())->toBeFalse();
});

it('numbers each new goal after the last one', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['position' => 7]);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('goalEmoji', '🏊')
        ->set('goalName', 'Natacion')
        ->call('saveGoal');

    expect($user->goals()->where('name', 'Natacion')->sole()->position)->toBe(8);
});

it('wants an emoji and a name', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::profile')
        ->set('goalEmoji', '')
        ->set('goalName', '')
        ->call('saveGoal')
        ->assertHasErrors(['goalEmoji' => 'required', 'goalName' => 'required']);

    expect(Goal::query()->count())->toBe(0);
});

it('refuses a name longer than the limit', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::profile')
        ->set('goalEmoji', '🏊')
        ->set('goalName', str_repeat('a', config()->integer('logralo.goals.name_max_length') + 1))
        ->call('saveGoal')
        ->assertHasErrors(['goalName' => 'max']);

    expect(Goal::query()->count())->toBe(0);
});

it('refuses a goal once the grid is full', function (): void {
    $max = config()->integer('logralo.goals.max_active');
    $user = User::factory()->create();
    Goal::factory()->for($user)->count($max)->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('goalEmoji', '🏊')
        ->set('goalName', 'Natacion')
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($user->goals()->count())->toBe($max)
        ->and(Goal::query()->where('name', 'Natacion')->exists())->toBeFalse();
});

it('renames a goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['emoji' => '🏊', 'name' => 'Natacion']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('editGoal', $goal->id)
        ->assertSet('editingGoalId', $goal->id)
        ->assertSet('goalEmoji', '🏊')
        ->assertSet('goalName', 'Natacion')
        ->set('goalEmoji', '🎸')
        ->set('goalName', 'Guitarra')
        ->call('saveGoal')
        ->assertHasNoErrors()
        ->assertSee('Guitarra')
        ->assertDontSee('Natacion');

    expect($goal->fresh()->name)->toBe('Guitarra')
        ->and($goal->fresh()->emoji)->toBe('🎸')
        // Renaming makes exactly one goal, not another one.
        ->and($user->goals()->count())->toBe(1);
});

it('archives a goal and keeps it in the archived list', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Natacion']);

    $component = Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('archiveGoal', $goal->id);

    expect($goal->fresh()->isArchived())->toBeTrue()
        ->and($component->get('activeGoals'))->toHaveCount(0)
        ->and($component->get('archivedGoals')->pluck('id')->all())->toBe([$goal->id]);

    $component->assertSee('Archivados (1)');
});

it('restores an archived goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->archived()->create(['name' => 'Natacion', 'position' => 1]);

    $component = Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('restoreGoal', $goal->id);

    expect($goal->fresh()->isArchived())->toBeFalse()
        ->and($component->get('activeGoals')->pluck('id')->all())->toBe([$goal->id])
        ->and($component->get('archivedGoals'))->toHaveCount(0);
});

it('refuses to restore a goal into a full grid', function (): void {
    $max = config()->integer('logralo.goals.max_active');
    $user = User::factory()->create();
    Goal::factory()->for($user)->count($max)->create();
    $archived = Goal::factory()->for($user)->archived()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('restoreGoal', $archived->id)
        ->assertHasNoErrors();

    expect($archived->fresh()->isArchived())->toBeTrue();
});

it("refuses to touch another member's goal", function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::profile')
        ->call('archiveGoal', Goal::factory()->create()->id);
})->throws(ModelNotFoundException::class);

it('updates the name and the timezone', function (): void {
    $user = User::factory()->create(['name' => 'Ana']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('name', 'Ana Maria')
        ->set('timezone', 'Europe/Madrid')
        ->call('saveProfile')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Ana Maria')
        ->and($user->timezone)->toBe('Europe/Madrid')
        ->and($user->clock()->timezone)->toBe('Europe/Madrid');
});

it('rejects a timezone that is not a real one', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('saveProfile')
        ->assertHasErrors(['timezone']);

    expect($user->fresh()->timezone)->toBe('America/Montevideo');
});

it('wants a name it can fit', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::profile')
        ->set('name', '')
        ->call('saveProfile')
        ->assertHasErrors(['name' => 'required'])
        ->set('name', str_repeat('a', 61))
        ->call('saveProfile')
        ->assertHasErrors(['name' => 'max']);
});

it('changes the password', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('current_password', 'password')
        ->set('password', 'contrasena9')
        ->set('password_confirmation', 'contrasena9')
        ->call('updatePassword')
        ->assertHasNoErrors()
        // Nothing is left in the form for the next visitor to this phone.
        ->assertSet('current_password', '')
        ->assertSet('password', '')
        ->assertSet('password_confirmation', '');

    $user->refresh();

    expect(Hash::check('contrasena9', (string) $user->password))->toBeTrue()
        ->and($user->password_set_at?->toDateTimeString())->toBe(CarbonImmutable::now()->toDateTimeString());
});

it('wants the current password before it changes anything', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('current_password', 'not-the-password')
        ->set('password', 'contrasena9')
        ->set('password_confirmation', 'contrasena9')
        ->call('updatePassword')
        ->assertHasErrors(['current_password'])
        // A failed attempt empties the form rather than leaving it primed.
        ->assertSet('password', '');

    expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue();
});

it("holds the new password to the app's own policy", function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('current_password', 'password')
        ->set('password', 'corta1')
        ->set('password_confirmation', 'corta1')
        ->call('updatePassword')
        ->assertHasErrors(['password']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('current_password', 'password')
        ->set('password', 'sinnumeros')
        ->set('password_confirmation', 'sinnumeros')
        ->call('updatePassword')
        ->assertHasErrors(['password']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('current_password', 'password')
        ->set('password', 'contrasena9')
        ->set('password_confirmation', 'otracosa9')
        ->call('updatePassword')
        ->assertHasErrors(['password' => 'confirmed']);

    expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue();
});

it('rolls the remember token so other sessions fall out', function (): void {
    $user = User::factory()->create();
    $before = $user->remember_token;

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('current_password', 'password')
        ->set('password', 'contrasena9')
        ->set('password_confirmation', 'contrasena9')
        ->call('updatePassword');

    expect($user->fresh()->remember_token)->not->toBe($before);
});

it('counts the goals against the cap on screen', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->count(2)->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->assertSee('2/'.config('logralo.goals.max_active'))
        ->assertSeeHtml('data-test="new-goal"');
});
