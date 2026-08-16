<?php

declare(strict_types=1);

use App\Actions\MarkGoal;
use App\Exceptions\DayClosedException;
use App\Exceptions\DuplicateMarkException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use Carbon\CarbonImmutable;

// The grace window is the one rule the whole product hangs on: a member's day
// D takes marks until D+1 at 12:00 in their own timezone. Every case here runs
// in a zone away from UTC, so an implementation that reads the clock in UTC
// fails rather than passes by luck.

it('still accepts yesterday one minute before the grace hour', function (): void {
    // 11:59 in Montevideo is 14:59 UTC — already past the UTC grace hour.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 11:59', 'America/Montevideo')->utc());

    $user = User::factory()->inTimezone('America/Montevideo')->create();
    $goal = Goal::factory()->for($user)->create();

    expect($user->clock()->isGraceOpen())->toBeTrue()
        ->and($user->clock()->openDays())->toHaveCount(2);

    $mark = resolve(MarkGoal::class)->handle($goal, $user->clock()->yesterday());

    expect($mark->marked_on->toDateString())->toBe('2026-08-10');
});

it('refuses yesterday from the grace hour onward', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 12:00', 'America/Montevideo')->utc());

    $user = User::factory()->inTimezone('America/Montevideo')->create();
    $goal = Goal::factory()->for($user)->create();
    $yesterday = $user->clock()->yesterday();

    expect($user->clock()->isGraceOpen())->toBeFalse()
        ->and($user->clock()->openDays())->toHaveCount(1);

    expect(fn (): Mark => resolve(MarkGoal::class)->handle($goal, $yesterday))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('still accepts yesterday one minute before the grace hour east of UTC', function (): void {
    // 11:59 in Tokyo is 02:59 UTC — the day before, in UTC terms.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 11:59', 'Asia/Tokyo')->utc());

    $user = User::factory()->inTimezone('Asia/Tokyo')->create();
    $goal = Goal::factory()->for($user)->create();

    expect($user->clock()->isGraceOpen())->toBeTrue();

    $mark = resolve(MarkGoal::class)->handle($goal, $user->clock()->yesterday());

    expect($mark->marked_on->toDateString())->toBe('2026-08-10');
});

it('refuses yesterday from the grace hour onward east of UTC', function (): void {
    // 12:00 in Tokyo is 03:00 UTC, still hours short of the UTC grace hour.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 12:00', 'Asia/Tokyo')->utc());

    $user = User::factory()->inTimezone('Asia/Tokyo')->create();
    $goal = Goal::factory()->for($user)->create();
    $yesterday = $user->clock()->yesterday();

    expect($user->clock()->isGraceOpen())->toBeFalse();

    expect(fn (): Mark => resolve(MarkGoal::class)->handle($goal, $yesterday))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('opens and closes the window per member at the very same instant', function (): void {
    // 14:30 UTC: 11:30 in Montevideo, 23:30 in Tokyo.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 14:30', 'UTC'));

    $montevideo = User::factory()->inTimezone('America/Montevideo')->create();
    $montevideoGoal = Goal::factory()->for($montevideo)->create();
    $tokyo = User::factory()->inTimezone('Asia/Tokyo')->create();
    $tokyoGoal = Goal::factory()->for($tokyo)->create();

    expect($montevideo->clock()->isGraceOpen())->toBeTrue()
        ->and($tokyo->clock()->isGraceOpen())->toBeFalse();

    $mark = resolve(MarkGoal::class)->handle($montevideoGoal, $montevideo->clock()->yesterday());

    expect($mark->marked_on->toDateString())->toBe('2026-08-10');

    $tokyoYesterday = $tokyo->clock()->yesterday();

    expect(fn (): Mark => resolve(MarkGoal::class)->handle($tokyoGoal, $tokyoYesterday))
        ->toThrow(DayClosedException::class);
});

it('keeps today open at every hour of the local day', function (): void {
    foreach (['00:00', '11:59', '12:00', '23:59'] as $localTime) {
        $this->travelTo(CarbonImmutable::parse("2026-08-11 {$localTime}", 'America/Montevideo')->utc());

        $user = User::factory()->inTimezone('America/Montevideo')->create();
        $goal = Goal::factory()->for($user)->create();

        $mark = resolve(MarkGoal::class)->handle($goal, $user->clock()->today());

        expect($mark->marked_on->toDateString())->toBe('2026-08-11');
    }
});

it('never reopens the day before yesterday', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 00:01', 'America/Montevideo')->utc());

    $user = User::factory()->inTimezone('America/Montevideo')->create();
    $goal = Goal::factory()->for($user)->create();
    $clock = $user->clock();

    expect($clock->openDays())->toHaveCount(2)
        ->and($clock->isOpen($clock->today()->subDays(2)))->toBeFalse();

    expect(fn (): Mark => resolve(MarkGoal::class)->handle($goal, $clock->today()->subDays(2)))
        ->toThrow(DayClosedException::class);
});

it('closes a day at the grace hour of the next local day', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $clock = User::factory()->inTimezone('America/Montevideo')->create()->clock();
    $closesAt = $clock->closesAt($clock->yesterday());

    expect($closesAt->toDateTimeString())->toBe('2026-08-11 12:00:00')
        ->and($closesAt->timezone->getName())->toBe('America/Montevideo')
        ->and($closesAt->utc()->toDateTimeString())->toBe('2026-08-11 15:00:00')
        ->and($clock->graceCutoffHour)->toBe(config()->integer('logralo.grace_cutoff_hour'));
});

it('rolls the window forward across a local midnight', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 23:59', 'America/Montevideo')->utc());

    $user = User::factory()->inTimezone('America/Montevideo')->create();
    $goal = Goal::factory()->for($user)->create();

    resolve(MarkGoal::class)->handle($goal, $user->clock()->today());

    // One minute later the member is on a new day, and the mark they just made
    // sits in the grace window rather than in today.
    $this->travelTo(CarbonImmutable::parse('2026-08-12 00:00', 'America/Montevideo')->utc());

    $clock = $user->fresh()?->clock();

    expect($clock?->today()->toDateString())->toBe('2026-08-12')
        ->and($clock?->yesterday()->toDateString())->toBe('2026-08-11')
        ->and($clock?->isGraceOpen())->toBeTrue();

    expect(fn (): Mark => resolve(MarkGoal::class)->handle($goal, CarbonImmutable::parse('2026-08-11', 'America/Montevideo')))
        ->toThrow(DuplicateMarkException::class);
});
