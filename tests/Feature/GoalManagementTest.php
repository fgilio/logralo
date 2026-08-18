<?php

declare(strict_types=1);

use App\Actions\ArchiveGoal;
use App\Actions\CreateGoal;
use App\Actions\RenameGoal;
use App\Actions\RestoreGoal;
use App\Exceptions\GoalLimitReachedException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Creating
|--------------------------------------------------------------------------
*/

it('gives the first goal position one', function (): void {
    $user = User::factory()->create();

    $goal = resolve(CreateGoal::class)->handle($user, '🏋️', 'Gimnasio');

    expect($goal->position)->toBe(1)
        ->and($goal->user_id)->toBe($user->id)
        ->and($goal->emoji)->toBe('🏋️')
        ->and($goal->name)->toBe('Gimnasio')
        ->and($goal->archived_at)->toBeNull();
});

it('gives every new goal the next position', function (): void {
    $user = User::factory()->create();

    $first = resolve(CreateGoal::class)->handle($user, '🏋️', 'Gimnasio');
    $second = resolve(CreateGoal::class)->handle($user, '🏃', 'Correr');
    $third = resolve(CreateGoal::class)->handle($user, '📚', 'Leer');

    expect([$first->position, $second->position, $third->position])->toBe([1, 2, 3]);
});

it('counts archived goals when it picks the next position', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->archived()->create(['position' => 7]);

    expect(resolve(CreateGoal::class)->handle($user, '🧘', 'Meditar')->position)->toBe(8);
});

it('numbers positions per member, not across the group', function (): void {
    $other = User::factory()->create();
    Goal::factory()->for($other)->create(['position' => 4]);

    $user = User::factory()->create();

    expect(resolve(CreateGoal::class)->handle($user, '💧', 'Tomar agua')->position)->toBe(1);
});

it('refuses the sixth active goal', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $position) {
        Goal::factory()->for($user)->create(['position' => $position]);
    }

    expect(fn (): Goal => resolve(CreateGoal::class)->handle($user, '🎸', 'Guitarra'))
        ->toThrow(GoalLimitReachedException::class);

    expect($user->goals()->count())->toBe(5);
});

it('tells the member how to make room for another goal', function (): void {
    $exception = GoalLimitReachedException::make();

    expect($exception->userMessage())->toBe('Ya tenés 5 objetivos activos. Archivá uno para crear otro.');
});

it('lets a member with five archived goals create a new one', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $position) {
        Goal::factory()->for($user)->archived()->create(['position' => $position]);
    }

    expect(resolve(CreateGoal::class)->handle($user, '🎸', 'Guitarra')->position)->toBe(6)
        ->and($user->activeGoals()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Renaming
|--------------------------------------------------------------------------
*/

it('renames a goal', function (): void {
    $goal = Goal::factory()->create(['emoji' => '🏋️', 'name' => 'Gimnasio']);

    $renamed = resolve(RenameGoal::class)->handle($goal, '🏃', 'Correr', $goal->visibility);

    expect($renamed->emoji)->toBe('🏃')
        ->and($renamed->name)->toBe('Correr')
        ->and($goal->fresh()->name)->toBe('Correr');
});

it('renames an archived goal too', function (): void {
    $goal = Goal::factory()->archived()->create(['emoji' => '🏋️', 'name' => 'Gimnasio']);

    resolve(RenameGoal::class)->handle($goal, '🧘', 'Meditar', $goal->visibility);

    expect($goal->fresh())
        ->name->toBe('Meditar')
        ->emoji->toBe('🧘')
        ->archived_at->not->toBeNull();
});

it('leaves the marks of a renamed goal alone', function (): void {
    $goal = Goal::factory()->create(['name' => 'Gimnasio']);
    $mark = Mark::factory()->for($goal)->on('2026-08-10')->create();

    resolve(RenameGoal::class)->handle($goal, '🏃', 'Correr', $goal->visibility);

    expect($goal->marks()->count())->toBe(1)
        ->and($mark->fresh()->marked_on->toDateString())->toBe('2026-08-10');
});

/*
|--------------------------------------------------------------------------
| Archiving
|--------------------------------------------------------------------------
*/

it('stamps archived_at and drops the goal out of the grid', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    $kept = Goal::factory()->for($user)->create();

    resolve(ArchiveGoal::class)->handle($goal);

    expect($goal->fresh()->archived_at?->toDateTimeString())->toBe(CarbonImmutable::now()->toDateTimeString())
        ->and($goal->fresh()->isArchived())->toBeTrue()
        ->and($goal->fresh()->streakPauses())->toBe([
            ['from' => '2026-08-10', 'archived_on' => '2026-08-11', 'through' => null],
        ])
        ->and($user->activeGoals()->pluck('id')->all())->toBe([$kept->id])
        ->and($user->goals()->count())->toBe(2);
});

it('keeps every mark of an archived goal', function (): void {
    $goal = Goal::factory()->create();
    Mark::factory()->for($goal)->on('2026-08-09')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->withPhoto()->create();

    resolve(ArchiveGoal::class)->handle($goal);

    expect($goal->marks()->count())->toBe(2)
        ->and(Mark::query()->count())->toBe(2);
});

it('leaves an already archived goal on its first archive date', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/Montevideo')->utc());

    $goal = Goal::factory()->create();
    resolve(ArchiveGoal::class)->handle($goal);
    $archivedAt = $goal->fresh()->archived_at;
    $streakPauses = $goal->fresh()->streakPauses();

    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());
    resolve(ArchiveGoal::class)->handle($goal->fresh());

    expect($goal->fresh()->archived_at?->toDateTimeString())->toBe($archivedAt?->toDateTimeString())
        ->and($goal->fresh()->streakPauses())->toBe($streakPauses);
});

/*
|--------------------------------------------------------------------------
| Restoring
|--------------------------------------------------------------------------
*/

it('clears archived_at when a goal comes back', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->archived()->create(['position' => 2]);

    $restored = resolve(RestoreGoal::class)->handle($goal);

    expect($restored->archived_at)->toBeNull()
        ->and($goal->fresh()->isArchived())->toBeFalse()
        ->and($user->activeGoals()->pluck('id')->all())->toBe([$goal->id]);
});

it('puts a restored goal back at the end of the grid', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['position' => 1]);
    Goal::factory()->for($user)->create(['position' => 2]);
    $goal = Goal::factory()->for($user)->archived()->create(['position' => 1]);

    resolve(RestoreGoal::class)->handle($goal);

    expect($goal->fresh()->position)->toBe(3);
});

it('refuses to restore a goal when the grid is already full', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $position) {
        Goal::factory()->for($user)->create(['position' => $position]);
    }

    $goal = Goal::factory()->for($user)->archived()->create(['position' => 6]);

    expect(fn (): Goal => resolve(RestoreGoal::class)->handle($goal))
        ->toThrow(GoalLimitReachedException::class);

    expect($goal->fresh()->isArchived())->toBeTrue()
        ->and($user->activeGoals()->count())->toBe(5);
});

it('brings the old marks back with a restored goal', function (): void {
    $goal = Goal::factory()->archived()->create();
    Mark::factory()->for($goal)->on('2026-08-09')->create();

    resolve(RestoreGoal::class)->handle($goal);

    expect($goal->fresh()->marks()->count())->toBe(1);
});

it('resumes the streak a goal had when it was archived', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    foreach (['2026-08-07', '2026-08-08', '2026-08-09'] as $date) {
        Mark::factory()->for($goal)->on($date)->withPhoto()->create();
    }

    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-18 09:00', 'America/Montevideo')->utc());

    resolve(RestoreGoal::class)->handle($goal);

    $component = Livewire::actingAs($user)->test('goal-card', ['goal' => $goal->fresh()]);

    expect($component->get('streak'))->toBe(3);

    $component->call('press', false);

    expect($component->get('streak'))->toBe(4)
        ->and($goal->fresh()->streakPauses())->toBe([
            ['from' => '2026-08-10', 'archived_on' => '2026-08-11', 'through' => '2026-08-16'],
        ]);
});

it('lets an open day break a restored streak after it closes', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    foreach (['2026-08-07', '2026-08-08', '2026-08-09'] as $date) {
        Mark::factory()->for($goal)->on($date)->withPhoto()->create();
    }

    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-18 09:00', 'America/Montevideo')->utc());
    resolve(RestoreGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-18 12:00', 'America/Montevideo')->utc());

    $component = Livewire::actingAs($user)->test('goal-card', ['goal' => $goal->fresh()]);

    expect($component->get('streak'))->toBe(0);
});

it('keeps the original pause boundary after a timezone change', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    resolve(ArchiveGoal::class)->handle($goal);

    $user->update(['timezone' => 'Pacific/Kiritimati']);

    $this->travelTo(CarbonImmutable::parse('2026-08-13 15:00', 'Pacific/Kiritimati')->utc());

    resolve(RestoreGoal::class)->handle($goal->fresh());

    expect($goal->fresh()->streakPauses())->toBe([
        ['from' => '2026-08-10', 'archived_on' => '2026-08-11', 'through' => '2026-08-12'],
    ]);
});

it('preserves the streak through repeated archive cycles', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 15:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    foreach (['2026-08-08', '2026-08-09', '2026-08-10'] as $date) {
        Mark::factory()->for($goal)->on($date)->withPhoto()->create();
    }

    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-15 15:00', 'America/Montevideo')->utc());
    resolve(RestoreGoal::class)->handle($goal);
    Mark::factory()->for($goal)->on('2026-08-15')->withPhoto()->create();

    $this->travelTo(CarbonImmutable::parse('2026-08-16 15:00', 'America/Montevideo')->utc());
    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-18 15:00', 'America/Montevideo')->utc());
    resolve(RestoreGoal::class)->handle($goal);

    $component = Livewire::actingAs($user)->test('goal-card', ['goal' => $goal->fresh()]);

    expect($component->get('streak'))->toBe(4)
        ->and($goal->fresh()->streakPauses())->toBe([
            ['from' => '2026-08-11', 'archived_on' => '2026-08-11', 'through' => '2026-08-14'],
            ['from' => '2026-08-16', 'archived_on' => '2026-08-16', 'through' => '2026-08-17'],
        ]);
});

/*
|--------------------------------------------------------------------------
| Through the profile screen
|--------------------------------------------------------------------------
*/

it('creates a goal from the profile screen', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('goalEmoji', '🏋️')
        ->set('goalName', 'Gimnasio')
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($user->activeGoals()->pluck('name')->all())->toBe(['Gimnasio']);
});

it('renames a goal from the profile screen', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('editGoal', $goal->id)
        ->assertSet('goalName', 'Gimnasio')
        ->set('goalName', 'Correr')
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($goal->fresh()->name)->toBe('Correr')
        ->and($user->goals()->count())->toBe(1);
});

it('opens the rename sheet clean after a rejected new goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('goalName', '')
        ->call('saveGoal')
        ->assertHasErrors('goalName')
        ->call('editGoal', $goal->id)
        ->assertHasNoErrors();
});

it('leaves the other forms on the screen their errors', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('name', '')
        ->call('saveProfile')
        ->assertHasErrors('name')
        ->call('editGoal', $goal->id)
        ->assertHasErrors('name')
        ->call('newGoal')
        ->assertHasErrors('name');
});

it('keeps the profile screen from touching another member goal', function (): void {
    $user = User::factory()->create();
    $foreign = Goal::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('archiveGoal', $foreign->id);
})->throws(ModelNotFoundException::class);

it('archives and restores from the profile screen', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test('pages::profile');

    $component->call('archiveGoal', $goal->id);

    expect($goal->fresh()->isArchived())->toBeTrue();

    $component->call('restoreGoal', $goal->id);
    expect($goal->fresh()->isArchived())->toBeFalse();
});

it('refuses a sixth goal from the profile screen without an error bag', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 5) as $position) {
        Goal::factory()->for($user)->create(['position' => $position]);
    }

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('goalEmoji', '🎸')
        ->set('goalName', 'Guitarra')
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($user->activeGoals()->count())->toBe(5);
});
