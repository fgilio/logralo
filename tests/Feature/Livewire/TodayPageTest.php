<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\ValueObjects\PulseEntry;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * "Hoy" is the only screen: the group's pulse, whatever is still open from
 * yesterday, today's grid, and the month's table behind it.
 */

/** 09:00 in Montevideo: today is open, and so is yesterday's grace window. */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());
});

it('shows the active goals and leaves the archived ones off the grid', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Natacion', 'emoji' => '🏊']);
    Goal::factory()->for($user)->archived()->create(['name' => 'Guitarra', 'emoji' => '🎸']);

    $component = Livewire::actingAs($user)->test('pages::today');

    expect($component->get('goals')->pluck('name')->all())->toBe(['Natacion']);

    $component->assertSee('Natacion')->assertDontSee('Guitarra');
});

it('draws few goals as rows and a full set as tiles', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->count(2)->create();

    // The container says how the goals are stacked, and `aspect-square` is the
    // tile's own shape — asserting both keeps the page and the card agreeing.
    Livewire::actingAs($user)
        ->test('pages::today')
        ->assertSeeHtml('flex flex-col gap-2')
        ->assertDontSeeHtml('aspect-square');

    Goal::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('pages::today')
        ->assertSeeHtml('grid grid-cols-2 gap-3')
        ->assertSeeHtml('aspect-square');
});

it('offers the add-goal link until the member is out of slots', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->count(config()->integer('logralo.goals.max_active') - 1)->create();

    Livewire::actingAs($user)->test('pages::today')->assertSeeHtml('data-test="add-goal"');

    Goal::factory()->for($user)->create();

    Livewire::actingAs($user)->test('pages::today')->assertDontSeeHtml('data-test="add-goal"');
});

it('shows the grace banner while yesterday is still open', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Natacion']);

    $component = Livewire::actingAs($user)->test('pages::today');

    expect($component->get('graceGoals')->pluck('id')->all())->toBe([$goal->id]);

    $component
        ->assertSeeHtml('data-test="grace-banner"')
        ->assertSeeHtml('data-test="grace-goal-'.$goal->id.'"');
});

it('tells the member what time the grace window closes', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Natacion']);

    Livewire::actingAs($user)
        ->test('pages::today')
        ->assertSee('Ayer sigue abierto hasta las 12:00');
});

it('hides the grace banner once the cutoff has passed', function (): void {
    // 12:00 sharp is the cutoff, so yesterday is gone.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 12:00', 'America/Montevideo')->utc());

    $user = User::factory()->create();
    Goal::factory()->for($user)->create(['name' => 'Natacion']);

    $component = Livewire::actingAs($user)->test('pages::today');

    expect($component->get('graceGoals'))->toHaveCount(0);

    $component->assertDontSeeHtml('data-test="grace-banner"');
});

it('keeps every goal in the grace banner after one is marked', function (): void {
    $user = User::factory()->create();
    $done = Goal::factory()->for($user)->create(['name' => 'Natacion', 'position' => 1]);
    $pending = Goal::factory()->for($user)->create(['name' => 'Guitarra', 'position' => 2]);

    Mark::factory()->for($done)->on('2026-08-10')->create();

    $component = Livewire::actingAs($user)->test('pages::today');

    expect($component->get('graceGoals')->pluck('id')->all())->toBe([$done->id, $pending->id]);

    $component
        ->assertSeeHtml('data-test="grace-goal-'.$done->id.'"')
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('data-test="grace-goal-'.$pending->id.'"')
        ->assertSeeHtml('aria-pressed="false"');
});

it('keeps the grace banner after every goal is caught up', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    Livewire::actingAs($user)
        ->test('pages::today')
        ->assertSeeHtml('data-test="grace-banner"')
        ->assertSeeHtml('data-test="grace-goal-'.$goal->id.'"')
        ->assertSeeHtml('aria-pressed="true"');
});

it("reads the grace window off the member's own clock", function (): void {
    // 09:00 in Montevideo is 14:00 in Madrid: past the cutoff there.
    $user = User::factory()->inTimezone('Europe/Madrid')->create();
    Goal::factory()->for($user)->create();

    Livewire::actingAs($user)->test('pages::today')->assertDontSeeHtml('data-test="grace-banner"');
});

it('lists every member in the pulse', function (): void {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $caro = User::factory()->create(['name' => 'Caro']);

    $anaGoals = Goal::factory()->for($ana)->count(2)->create();
    Goal::factory()->for($bruno)->create();

    Mark::factory()->for($anaGoals->first())->on('2026-08-11')->create();

    $component = Livewire::actingAs($ana)->test('pages::today');

    /** @var Collection<int, PulseEntry> $pulse */
    $pulse = $component->get('pulse');

    expect($pulse)->toHaveCount(3)
        ->and($pulse->pluck('user.name')->all())->toBe(['Ana', 'Bruno', 'Caro']);

    $ring = $pulse->keyBy(fn (PulseEntry $entry): string => $entry->user->name);

    expect($ring['Ana']->activeGoals)->toBe(2)
        ->and($ring['Ana']->markedToday)->toBe(1)
        ->and($ring['Ana']->completion())->toBe(0.5)
        ->and($ring['Ana']->isComplete())->toBeFalse()
        ->and($ring['Bruno']->markedToday)->toBe(0)
        // A member with no goals still gets an avatar, just an empty ring.
        ->and($ring['Caro']->activeGoals)->toBe(0)
        ->and($ring['Caro']->completion())->toBe(0.0);

    foreach ([$ana, $bruno, $caro] as $member) {
        $component->assertSeeHtml('data-test="pulse-'.$member->id.'"');
    }
});

it("computes the standings on each member's own calendar", function (): void {
    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);
    User::factory()->create(['name' => 'Caro']);

    $anaGoal = Goal::factory()->for($ana)->create();
    $brunoGoal = Goal::factory()->for($bruno)->create();

    // Eleven days elapsed, one active goal each: eleven possible marks.
    Mark::factory()->for($anaGoal)->withPhoto()->on('2026-08-01')->create();
    Mark::factory()->for($anaGoal)->withPhoto()->on('2026-08-02')->create();
    Mark::factory()->for($brunoGoal)->on('2026-08-01')->create();

    // July is last month, so it must not count toward August.
    Mark::factory()->for($brunoGoal)->withPhoto()->on('2026-07-31')->create();

    /** @var Collection<int, Standing> $standings */
    $standings = Livewire::actingAs($ana)->test('pages::today')->get('standings');

    // Caro has no active goals, so she is not in the table at all.
    expect($standings->pluck('name')->all())->toBe(['Ana', 'Bruno']);

    $ana = $standings->firstWhere('name', 'Ana');
    $bruno = $standings->firstWhere('name', 'Bruno');

    expect($ana->fullMarks)->toBe(2)
        ->and($ana->ghostMarks)->toBe(0)
        ->and($ana->possibleMarks)->toBe(11)
        ->and($ana->rank)->toBe(1)
        ->and($bruno->fullMarks)->toBe(0)
        ->and($bruno->ghostMarks)->toBe(1)
        ->and($bruno->points())->toBe(0.5)
        ->and($bruno->possibleMarks)->toBe(11)
        ->and($bruno->rank)->toBe(2);
});

it('shares a rank between members on the same score', function (): void {
    $members = collect(['Ana', 'Bruno', 'Caro'])
        ->map(fn (string $name): User => User::factory()->create(['name' => $name]));

    $goals = $members->map(fn (User $member): Goal => Goal::factory()->for($member)->create());

    Mark::factory()->for($goals[0])->withPhoto()->on('2026-08-01')->create();
    Mark::factory()->for($goals[1])->withPhoto()->on('2026-08-01')->create();

    $standings = Livewire::actingAs($members[0])->test('pages::today')->get('standings');

    expect($standings->pluck('rank')->all())->toBe([1, 1, 3]);
});

it('leaves a member with no active goals out of the table', function (): void {
    $user = User::factory()->create(['name' => 'Ana']);
    Goal::factory()->for($user)->archived()->create();

    $component = Livewire::actingAs($user)->test('pages::today');

    expect($component->get('standings'))->toHaveCount(0);

    $component->assertSee('Todavía nadie tiene objetivos activos');
});

it('sends a member with no goals to the empty state', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::today')
        ->assertSee('Creá tu primer objetivo')
        ->assertSeeHtml('data-test="create-first-goal"');
});

it('recomputes everything when a mark lands anywhere on the page', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test('pages::today');

    expect($component->get('pulse')->first()->markedToday)->toBe(0)
        ->and($component->get('graceGoals'))->toHaveCount(1);

    Mark::factory()->for($goal)->on('2026-08-11')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    $component->dispatch('mark-updated');

    expect($component->get('pulse')->first()->markedToday)->toBe(1)
        ->and($component->get('graceGoals'))->toHaveCount(1)
        ->and($component->get('standings')->first()->ghostMarks)->toBe(2);
});
