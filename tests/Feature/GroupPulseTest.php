<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Queries\GroupPulse;
use App\ValueObjects\PulseEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** @return Collection<int, PulseEntry> */
function pulseToday(): Collection
{
    return resolve(GroupPulse::class)->today();
}

function pulseEntryFor(string $name): ?PulseEntry
{
    return pulseToday()->first(fn (PulseEntry $entry): bool => $entry->user->name === $name);
}

beforeEach(function (): void {
    // Mid-morning in Montevideo, so "today" is unambiguous for everyone here.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());
});

it('counts how much of today a member has closed', function (): void {
    $user = User::factory()->create(['name' => 'Ana']);
    $goals = Goal::factory()->count(2)->for($user)->create();

    Mark::factory()->for($goals->first())->on('2026-08-11')->create();

    $entry = pulseEntryFor('Ana');

    expect($entry->activeGoals)->toBe(2)
        ->and($entry->markedToday)->toBe(1)
        ->and($entry->completion())->toBe(0.5)
        ->and($entry->isComplete())->toBeFalse();

    Mark::factory()->for($goals->last())->on('2026-08-11')->withPhoto()->create();

    $entry = pulseEntryFor('Ana');

    expect($entry->markedToday)->toBe(2)
        ->and($entry->completion())->toBe(1.0)
        ->and($entry->isComplete())->toBeTrue();
});

it('ignores marks from any day but today', function (): void {
    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->on('2026-08-10')->withPhoto()->create();

    $entry = pulseEntryFor('Ana');

    expect($entry->markedToday)->toBe(0)
        ->and($entry->completion())->toBe(0.0)
        ->and($entry->isComplete())->toBeFalse();
});

it('ignores today marks that belong to an archived goal', function (): void {
    $user = User::factory()->create(['name' => 'Ana']);
    $kept = Goal::factory()->for($user)->create(['position' => 1]);
    $dropped = Goal::factory()->for($user)->archived()->create(['position' => 2]);

    Mark::factory()->for($dropped)->on('2026-08-11')->withPhoto()->create();

    $entry = pulseEntryFor('Ana');

    expect($entry->activeGoals)->toBe(1)
        ->and($entry->markedToday)->toBe(0);

    Mark::factory()->for($kept)->on('2026-08-11')->withPhoto()->create();

    expect(pulseEntryFor('Ana')->isComplete())->toBeTrue();
});

it('reads a member with no goals as zero of zero and never complete', function (): void {
    User::factory()->create(['name' => 'Ana']);

    $bare = pulseEntryFor('Ana');

    expect($bare)->not->toBeNull()
        ->and($bare->activeGoals)->toBe(0)
        ->and($bare->markedToday)->toBe(0)
        ->and($bare->completion())->toBe(0.0)
        ->and($bare->isComplete())->toBeFalse();
});

it('measures each member against their own today', function (): void {
    // 12:00 UTC: still 11 August in Montevideo, already 12 August fourteen
    // hours ahead.
    $here = User::factory()->create(['name' => 'Ana']);
    $ahead = User::factory()->inTimezone('Pacific/Kiritimati')->create(['name' => 'Beto']);

    expect($here->clock()->today()->toDateString())->toBe('2026-08-11')
        ->and($ahead->clock()->today()->toDateString())->toBe('2026-08-12');

    $hereGoal = Goal::factory()->for($here)->create();
    $aheadGoal = Goal::factory()->for($ahead)->create();

    Mark::factory()->for($hereGoal)->on('2026-08-11')->withPhoto()->create();
    Mark::factory()->for($aheadGoal)->on('2026-08-11')->withPhoto()->create();

    expect(pulseEntryFor('Ana')->isComplete())->toBeTrue()
        ->and(pulseEntryFor('Beto')->markedToday)->toBe(0);

    Mark::factory()->for($aheadGoal)->on('2026-08-12')->withPhoto()->create();

    expect(pulseEntryFor('Beto')->markedToday)->toBe(1)
        ->and(pulseEntryFor('Beto')->isComplete())->toBeTrue();
});

it('lists every member by name, goals or not', function (): void {
    User::factory()->create(['name' => 'Caro']);
    $ana = User::factory()->create(['name' => 'Ana']);
    User::factory()->create(['name' => 'Beto']);

    Goal::factory()->for($ana)->create();

    expect(pulseToday()->map(fn (PulseEntry $entry): string => $entry->user->name)->all())
        ->toBe(['Ana', 'Beto', 'Caro']);
});
