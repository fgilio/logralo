<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Queries\MonthlyStandings;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** The table as the pulse strip reads it, right now. */
function monthlyTable(): Collection
{
    return resolve(MonthlyStandings::class)->current();
}

function monthlyStandingFor(string $name): ?Standing
{
    return monthlyTable()->first(fn (Standing $standing): bool => $standing->name === $name);
}

/** Five days into August, Montevideo time. */
function travelToAugustFifth(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-08-05 09:00', 'America/Montevideo')->utc());
}

it('scores a member as points over their own possible marks', function (): void {
    travelToAugustFifth();

    $user = User::factory()->create(['name' => 'Ana']);
    $gym = Goal::factory()->for($user)->create(['position' => 1]);
    $read = Goal::factory()->for($user)->create(['position' => 2]);

    // 3 full marks + 2 ghost marks over the 5 days elapsed, on 2 active goals.
    Mark::factory()->for($gym)->on('2026-08-01')->withPhoto()->create();
    Mark::factory()->for($gym)->on('2026-08-02')->withPhoto()->create();
    Mark::factory()->for($read)->on('2026-08-01')->withPhoto()->create();
    Mark::factory()->for($gym)->on('2026-08-03')->create();
    Mark::factory()->for($read)->on('2026-08-02')->create();

    $standing = monthlyStandingFor('Ana');

    expect($standing)->not->toBeNull()
        ->and($standing->fullMarks)->toBe(3)
        ->and($standing->ghostMarks)->toBe(2)
        ->and($standing->possibleMarks)->toBe(10)
        ->and($standing->points())->toBe(4.0)
        ->and($standing->score())->toBe(0.4)
        ->and($standing->percentage())->toBe(40.0)
        ->and($standing->rank)->toBe(1);
});

it('ignores days that have not started yet and days of last month', function (): void {
    travelToAugustFifth();

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->on('2026-07-31')->withPhoto()->create();
    Mark::factory()->for($goal)->on('2026-08-04')->withPhoto()->create();
    Mark::factory()->for($goal)->on('2026-08-06')->withPhoto()->create();

    $standing = monthlyStandingFor('Ana');

    expect($standing->fullMarks)->toBe(1)
        ->and($standing->possibleMarks)->toBe(5);
});

it('lets a two-goal member beat a five-goal member', function (): void {
    travelToAugustFifth();

    $small = User::factory()->create(['name' => 'Ana']);
    $large = User::factory()->create(['name' => 'Beto']);

    $smallGoals = Goal::factory()->count(2)->for($small)->create();
    $largeGoals = Goal::factory()->count(5)->for($large)->create();

    // 8 of 10 possible for the two-goal member.
    $days = ['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'];

    foreach ($smallGoals as $goal) {
        foreach ($days as $day) {
            Mark::factory()->for($goal)->on($day)->withPhoto()->create();
        }
    }

    // 15 of 25 possible for the five-goal member — more marks, worse month.
    foreach ($largeGoals as $goal) {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $day) {
            Mark::factory()->for($goal)->on($day)->withPhoto()->create();
        }
    }

    $table = monthlyTable();

    expect($table->pluck('name')->all())->toBe(['Ana', 'Beto'])
        ->and($table->first()->percentage())->toBe(80.0)
        ->and($table->first()->rank)->toBe(1)
        ->and($table->last()->fullMarks)->toBe(15)
        ->and($table->last()->percentage())->toBe(60.0)
        ->and($table->last()->rank)->toBe(2);
});

it('counts an archived goal on neither side of the ratio', function (): void {
    travelToAugustFifth();

    $user = User::factory()->create(['name' => 'Ana']);
    $kept = Goal::factory()->for($user)->create(['position' => 1]);
    $dropped = Goal::factory()->for($user)->archived()->create(['position' => 2]);

    Mark::factory()->for($kept)->on('2026-08-01')->withPhoto()->create();
    Mark::factory()->for($dropped)->on('2026-08-01')->withPhoto()->create();
    Mark::factory()->for($dropped)->on('2026-08-02')->create();

    $standing = monthlyStandingFor('Ana');

    expect($standing->fullMarks)->toBe(1)
        ->and($standing->ghostMarks)->toBe(0)
        ->and($standing->possibleMarks)->toBe(5);
});

it('leaves a member with no active goals out of the table', function (): void {
    travelToAugustFifth();

    $player = User::factory()->create(['name' => 'Ana']);
    Goal::factory()->for($player)->create();

    $quitter = User::factory()->create(['name' => 'Beto']);
    Goal::factory()->for($quitter)->archived()->create();

    User::factory()->create(['name' => 'Caro']);

    expect(monthlyTable()->pluck('name')->all())->toBe(['Ana']);
});

it('gives tied members the same rank and skips the one after', function (): void {
    travelToAugustFifth();

    $names = ['Ana' => 2, 'Beto' => 2, 'Caro' => 1];

    foreach ($names as $name => $marks) {
        $user = User::factory()->create(['name' => $name]);
        $goal = Goal::factory()->for($user)->create();

        foreach (array_slice(['2026-08-01', '2026-08-02'], 0, $marks) as $day) {
            Mark::factory()->for($goal)->on($day)->withPhoto()->create();
        }
    }

    $table = monthlyTable();

    expect($table->pluck('name')->all())->toBe(['Ana', 'Beto', 'Caro'])
        ->and($table->pluck('rank')->all())->toBe([1, 1, 3])
        ->and($table->map(fn (Standing $s): float => $s->percentage())->all())
        ->toBe([40.0, 40.0, 20.0]);
});

it('measures every member on their own timezone month', function (): void {
    // 21:00 UTC on the last day of August: still August in Montevideo, already
    // September for a member fourteen hours ahead.
    $this->travelTo(CarbonImmutable::parse('2026-08-31 21:00', 'UTC'));

    $here = User::factory()->create(['name' => 'Ana']);
    $ahead = User::factory()->inTimezone('Pacific/Kiritimati')->create(['name' => 'Beto']);

    $hereGoal = Goal::factory()->for($here)->create();
    $aheadGoal = Goal::factory()->for($ahead)->create();

    Mark::factory()->for($hereGoal)->on('2026-08-31')->withPhoto()->create();

    // August is over for Beto, so his August mark belongs to a closed month.
    Mark::factory()->for($aheadGoal)->on('2026-08-30')->withPhoto()->create();
    Mark::factory()->for($aheadGoal)->on('2026-09-01')->withPhoto()->create();

    expect($here->clock()->today()->toDateString())->toBe('2026-08-31')
        ->and($ahead->clock()->today()->toDateString())->toBe('2026-09-01');

    $hereStanding = monthlyStandingFor('Ana');
    $aheadStanding = monthlyStandingFor('Beto');

    expect($hereStanding->possibleMarks)->toBe(31)
        ->and($hereStanding->fullMarks)->toBe(1)
        ->and($aheadStanding->possibleMarks)->toBe(1)
        ->and($aheadStanding->fullMarks)->toBe(1)
        ->and($aheadStanding->percentage())->toBe(100.0);
});

it('puts the highest score first whatever the members are called', function (): void {
    travelToAugustFifth();

    $best = User::factory()->create(['name' => 'Zoe']);
    $rest = User::factory()->create(['name' => 'Ana']);

    $bestGoal = Goal::factory()->for($best)->create();
    $restGoal = Goal::factory()->for($rest)->create();

    foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
        Mark::factory()->for($bestGoal)->on($day)->withPhoto()->create();
    }

    Mark::factory()->for($restGoal)->on('2026-08-01')->withPhoto()->create();

    $table = monthlyTable();

    expect($table->pluck('name')->all())->toBe(['Zoe', 'Ana'])
        ->and($table->first()->rank)->toBe(1)
        ->and($table->last()->rank)->toBe(2);
});

it('reports a finished month over every one of its days', function (): void {
    travelToAugustFifth();

    $user = User::factory()->create(['name' => 'Ana']);

    // Dated into July, where its marks are.
    $goal = Goal::factory()->for($user)->create(['created_at' => CarbonImmutable::parse('2026-07-01')]);

    Mark::factory()->for($goal)->on('2026-07-01')->withPhoto()->create();
    Mark::factory()->for($goal)->on('2026-07-02')->create();
    Mark::factory()->for($goal)->on('2026-08-01')->withPhoto()->create();

    $standing = resolve(MonthlyStandings::class)
        ->forMonth(CarbonImmutable::parse('2026-07-01'))
        ->first();

    expect($standing->fullMarks)->toBe(1)
        ->and($standing->ghostMarks)->toBe(1)
        ->and($standing->possibleMarks)->toBe(31);
});
