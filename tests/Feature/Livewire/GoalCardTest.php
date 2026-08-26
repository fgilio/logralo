<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The goal card is the whole product in one tile: one tap marks, and the sheet
 * behind it is the only way a photo, a note, or an un-mark gets in.
 */

/** 09:00 in Montevideo: today is open, and so is yesterday's grace window. */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());
});

it('marks the day when pressed', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->assertSet('date', '2026-08-11')
        ->call('press', false)
        ->assertDispatched('mark-updated');

    $mark = Mark::query()->sole();

    expect($mark->goal_id)->toBe($goal->id)
        ->and($mark->user_id)->toBe($user->id)
        ->and($mark->marked_on->toDateString())->toBe('2026-08-11')
        ->and($mark->isGhost())->toBeTrue()
        ->and($mark->note)->toBeNull();
});

it('keeps the mark when a marked card is pressed again', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    $component = Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->call('press', false);

    expect(Mark::query()->count())->toBe(1);

    // The tap that used to delete the day now only opens the sheet, where
    // un-marking is a button somebody has to mean.
    $component->call('press', true)->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(1);
});

it('un-marks the day from the sheet', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    $component = Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->call('press', false);

    expect(Mark::query()->count())->toBe(1);

    $component->call('remove')->assertDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(0);
});

it('ignores the second half of a double tap', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    // Both presses carry the unmarked card the finger saw. The second one
    // reaches a day the first has already marked, and marking it twice is what
    // a double tap must never do.
    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->call('press', false)
        ->call('press', false)
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(1);
});

it('deletes the photo of the mark it un-marks', function (): void {
    Storage::fake('local');
    Storage::fake('photos');
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->upload('photo', [UploadedFile::fake()->image('proof.jpg', 400, 500)])
        ->call('save')
        ->call('remove');

    expect(Mark::query()->count())->toBe(0)
        ->and(Storage::disk('photos')->allFiles())->toBe([]);
});

it("refuses to mount another member's goal", function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('goal-card', ['goal' => Goal::factory()->create()])
        ->assertForbidden();
});

it('does not mark when the photo rule wants proof', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    // Two ghost marks in a row: the third tap owes a photo, so it opens the
    // sheet rather than marking.
    Mark::factory()->for($goal)->on('2026-08-09')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->assertSet('requiresPhoto', true)
        ->call('press', false)
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->where('marked_on', '2026-08-11')->exists())->toBeFalse();
});

it('refuses a save with no photo when the photo rule wants proof', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->on('2026-08-09')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->set('note', 'palabra de honor')
        ->call('save')
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(2);
});

it('lets a photo through once the photo rule is satisfied', function (): void {
    Storage::fake('local');
    Storage::fake('photos');
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->on('2026-08-09')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->upload('photo', [UploadedFile::fake()->image('proof.jpg', 400, 500)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('mark-updated');

    expect(Mark::query()->where('marked_on', '2026-08-11')->sole()->isFull())->toBeTrue();
});

it('saves a photo and a note through the sheet', function (): void {
    Storage::fake('local');
    Storage::fake('photos');
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->set('note', '  Sesión   de   piernas  ')
        ->upload('photo', [UploadedFile::fake()->image('proof.jpg', 1200, 1500)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('mark-updated')
        // The sheet is emptied, so re-opening it does not offer a stale photo.
        ->assertSet('photo', null)
        ->assertSet('note', '');

    $mark = Mark::query()->sole();

    expect($mark->isFull())->toBeTrue()
        ->and($mark->note)->toBe('Sesión de piernas')
        ->and($mark->photo_width)->toBe(1080)
        ->and($mark->photo_height)->toBe(1350)
        ->and(Storage::disk('photos')->files((string) $mark->photo_key))->toHaveCount(4);
});

it('saves a bare note with no photo at all', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->set('note', 'sin cámara')
        ->call('save')
        ->assertHasNoErrors();

    $mark = Mark::query()->sole();

    expect($mark->isGhost())->toBeTrue()
        ->and($mark->note)->toBe('sin cámara');
});

it('rejects a note longer than the limit', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->set('note', str_repeat('a', config()->integer('logralo.goals.note_max_length') + 1))
        ->call('save')
        ->assertHasErrors(['note' => 'max']);

    expect(Mark::query()->count())->toBe(0);
});

it('marks yesterday while the grace window is open', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal, 'date' => '2026-08-10', 'variant' => 'chip'])
        ->assertSet('date', '2026-08-10')
        ->assertSet('variant', 'chip')
        ->call('press', false)
        ->assertDispatched('mark-updated');

    expect(Mark::query()->sole()->marked_on->toDateString())->toBe('2026-08-10');
});

it('turns the reminder into a quiet confirmation', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal, 'date' => '2026-08-10', 'variant' => 'reminder'])
        ->assertSee('Gimnasio')
        ->assertSee('Sí, lo hice')
        ->assertSee('Descartar')
        ->call('press', false)
        ->assertDispatched('grace-mark-updated')
        ->assertSee('¡Anotado! Gimnasio')
        ->assertSee('Agregar foto')
        ->assertSeeHtml('data-test="grace-undo-'.$goal->id.'"');

    expect(Mark::query()->sole()->marked_on->toDateString())->toBe('2026-08-10');
});

it('dismisses and restores a reminder without changing the goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal, 'date' => '2026-08-10', 'variant' => 'reminder'])
        ->call('dismissReminder')
        ->assertSet('dismissed', true)
        ->assertSee('Recordatorio ocultado')
        ->assertSee('Deshacer')
        ->call('finishDismissal')
        ->assertSet('showDismissUndo', false)
        ->assertDontSee('Recordatorio ocultado')
        ->call('restoreReminder')
        ->assertSet('dismissed', false)
        ->assertSee('Sí, lo hice');

    expect(Mark::query()->count())->toBe(0);
});

it('remembers a dismissed reminder across page loads', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);
    $parameters = ['goal' => $goal, 'date' => '2026-08-10', 'variant' => 'reminder'];

    Livewire::actingAs($user)
        ->test('goal-card', $parameters)
        ->call('dismissReminder');

    Livewire::actingAs($user)
        ->test('goal-card', $parameters)
        ->assertSet('dismissed', true)
        ->assertDontSee('Sí, lo hice')
        ->assertDontSee('Recordatorio ocultado');
});

it('adds a photo after a reminder was marked', function (): void {
    Storage::fake('photos');
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal, 'date' => '2026-08-10', 'variant' => 'reminder'])
        ->upload('photo', [UploadedFile::fake()->image('proof.jpg', 400, 500)])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('grace-mark-updated')
        ->assertSee('Quedó guardado con foto.');

    expect(Mark::query()->sole()->isFull())->toBeTrue();
});

it('refuses to mark yesterday once the grace window has closed', function (): void {
    // 12:00 sharp is the cutoff, so yesterday is already gone.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 12:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal, 'date' => '2026-08-10'])
        ->call('press', false)
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(0);
});

it('keeps a mark whose day has already closed', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    Mark::factory()->for($goal)->on('2026-08-05')->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal, 'date' => '2026-08-05'])
        ->call('remove')
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(1);
});

it('refuses to mark an archived goal', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->archived()->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->call('press', false)
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(0);
});

it('refuses a second mark on the same day', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    Mark::factory()->for($goal)->on('2026-08-11')->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->call('save')
        ->assertNotDispatched('mark-updated');

    expect(Mark::query()->count())->toBe(1);
});

it('counts the streak the mark belongs to', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    // With proof on the run, today's tap does not owe the camera anything.
    foreach (['2026-08-08', '2026-08-09', '2026-08-10'] as $date) {
        Mark::factory()->for($goal)->withPhoto()->on($date)->create();
    }

    $component = Livewire::actingAs($user)->test('goal-card', ['goal' => $goal]);

    expect($component->get('streak'))->toBe(3);

    // Today's own mark extends it, and the card says so straight away.
    $component->call('press', false);

    expect($component->get('streak'))->toBe(4);
});

it('celebrates a milestone reached across an archive pause', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create([
        'archive_periods' => [
            [
                'archived_on' => '2026-08-04',
                'restored_on' => '2026-08-07',
                'paused_from' => '2026-08-04',
                'paused_through' => '2026-08-06',
            ],
        ],
    ]);

    foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-07', '2026-08-08', '2026-08-09'] as $date) {
        Mark::factory()->for($goal)->withPhoto()->on($date)->create();
    }

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->call('press', false)
        ->assertDispatched('milestone-reached');
});

it("reads the day off the member's own clock when none is given", function (): void {
    // 23:30 in Montevideo is already the next day in Madrid.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 23:30', 'America/Montevideo')->utc());

    $user = User::factory()->inTimezone('Europe/Madrid')->create();
    $goal = Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('goal-card', ['goal' => $goal])
        ->assertSet('date', '2026-08-12');
});
