<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Queries\MonthlyStandings;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** The finished month every shape here is scored against. */
function scoredMonth(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-01');
}

/**
 * Arrangements of goals and marks around a month boundary, rolled from a
 * fixed seed so a failure names a shape somebody can rebuild
 * and the suite scores the same set on every run.
 *
 * Rolled by hand rather than through `fake()`, whose sequence moves with the
 * faker version. `created_on` is the member's own local day, and
 * days run from before the month to after it, which is
 * where every rule in the scoring lives.
 *
 * A shape is a timezone and a list of goals, each carrying the local day it
 * appeared, whether it was archived, and its marks as day => hasPhoto.
 *
 * @return array<string, array<int, array<string, mixed>>>
 */
function scoringShapes(int $count): array
{
    $state = 20260701;
    $next = function (int $bound) use (&$state): int {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

        return intdiv($state, 65536) % $bound;
    };

    $day = fn (int $offset): string => scoredMonth()->addDays($offset)->toDateString();

    $timezones = ['America/Montevideo', 'Pacific/Kiritimati', 'Asia/Kolkata', 'UTC'];
    $shapes = [];

    for ($shape = 1; $shape <= $count; $shape++) {
        $goalCount = $next(4) + 1;
        $goals = [];

        for ($goal = 0; $goal < $goalCount; $goal++) {
            $markCount = $next(8) + 1;
            $marks = [];

            for ($mark = 0; $mark < $markCount; $mark++) {
                // The key keeps one mark per day, which is what the unique
                // index on (goal_id, marked_on) allows. The value is
                // whether it carries a photo.
                $marks[$day($next(38) - 3)] = $next(2) === 1;
            }

            $goals[] = [
                'created_on' => $day($next(45) - 12),
                'archived' => $next(6) === 0,
                'marks' => $marks,
            ];
        }

        $shapes["shape {$shape}"] = [[
            'timezone' => $timezones[$next(count($timezones))],
            'goals' => $goals,
        ]];
    }

    return $shapes;
}

/**
 * Noon on a local day.
 *
 * Midday rather than midnight so a shape says the same thing in every
 * timezone: the goal was on the board on that member's
 * own calendar day, whatever the offset.
 */
function localNoon(string $day, string $timezone): CarbonImmutable
{
    return CarbonImmutable::parse("{$day} 12:00", $timezone);
}

/**
 * @param  array<string, mixed>  $shape
 */
function buildShape(array $shape): User
{
    $member = User::factory()->inTimezone($shape['timezone'])->create(['name' => 'Ana']);

    foreach ($shape['goals'] as $position => $goal) {
        $model = Goal::factory()->for($member)->create([
            'position' => $position + 1,
            'created_at' => localNoon($goal['created_on'], $shape['timezone']),
            'archived_at' => $goal['archived'] ? CarbonImmutable::parse('2026-08-02 12:00', 'UTC') : null,
        ]);

        foreach ($goal['marks'] as $day => $withPhoto) {
            $mark = Mark::factory()->for($model)->on($day);

            ($withPhoto ? $mark->withPhoto() : $mark)->create();
        }
    }

    return $member;
}

/** @return Collection<int, Standing> */
function julyStandings(): Collection
{
    return resolve(MonthlyStandings::class)->forMonth(scoredMonth());
}

/** @return array<int, array<string, mixed>> */
function julyTable(): array
{
    return julyStandings()->map(fn (Standing $standing): array => $standing->toArray())->all();
}

it('leaves a finished month untouched by a goal added after it ended', function (array $shape): void {
    // Past every member's grace on 31 July, which is when the sweep runs.
    $this->travelTo(CarbonImmutable::parse('2026-08-02 15:00', 'UTC'));

    $member = buildShape($shape);

    $before = julyTable();

    // One mark per goal per day is all the unique index allows, so a table
    // that counts more than it made possible is counting a mark
    // whose goal the denominator left out.
    foreach (julyStandings() as $standing) {
        expect($standing->fullMarks + $standing->ghostMarks)
            ->toBeLessThanOrEqual($standing->possibleMarks);
    }

    // The goal the month never saw, carrying marks dated into it. Grace is how
    // production reaches this: a goal created on the 1st accepts a mark
    // for the 31st until noon. July is closed to it either way.
    $latecomer = Goal::factory()->for($member)->create([
        'position' => 6,
        'created_at' => localNoon('2026-08-01', $shape['timezone']),
    ]);

    foreach (['2026-07-05', '2026-07-15', '2026-07-31'] as $day) {
        Mark::factory()->for($latecomer)->on($day)->withPhoto()->create();
    }

    expect(julyTable())->toBe($before);
})->with(scoringShapes(40));

it('rolls shapes that reach the rules they are meant to score', function (): void {
    // A generator that stops producing the awkward arrangements passes both
    // tests above while proving nothing. These are the counts the seed
    // above yields, so a change to it has to answer for them.
    $late = 0;
    $lateCarryingAMark = 0;
    $archivedCarryingAMark = 0;
    $tables = 0;

    foreach (scoringShapes(40) as [$shape]) {
        $counted = 0;

        foreach ($shape['goals'] as $goal) {
            $inMonth = array_filter(
                array_keys($goal['marks']),
                fn (string $day): bool => $day >= '2026-07-01' && $day <= '2026-07-31',
            );

            if ($goal['created_on'] > '2026-07-31') {
                $late++;
                $lateCarryingAMark += $inMonth === [] ? 0 : 1;

                continue;
            }

            if ($goal['archived']) {
                $archivedCarryingAMark += $inMonth === [] ? 0 : 1;

                continue;
            }

            $counted++;
        }

        $tables += $counted > 0 ? 1 : 0;
    }

    expect($late)->toBeGreaterThanOrEqual(3)
        ->and($lateCarryingAMark)->toBeGreaterThanOrEqual(3)
        ->and($archivedCarryingAMark)->toBeGreaterThanOrEqual(10)
        // Without a counted goal the member is absent from the table, and a
        // shape that scores nothing cannot catch a scoring bug.
        ->and($tables)->toBeGreaterThanOrEqual(30);
});
