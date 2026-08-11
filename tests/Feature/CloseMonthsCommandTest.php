<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * A member with one goal, marked on each of the given days.
 *
 * @param  list<string>  $days
 */
function sweepMemberMarkedOn(string $name, array $days): Goal
{
    $goal = Goal::factory()->for(User::factory()->create(['name' => $name]))->create();

    foreach ($days as $day) {
        Mark::factory()->for($goal)->on($day)->withPhoto()->create();
    }

    return $goal;
}

beforeEach(function (): void {
    // Noon in Montevideo on 1 August: June and July are both shut for everyone.
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));
});

it('sweeps every closeable month, oldest first', function (): void {
    sweepMemberMarkedOn('Ana', ['2026-06-10', '2026-07-10']);

    $exit = Artisan::call('logralo:close-months');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(MonthlyRecap::query()->orderBy('month')->pluck('month')->map(
            fn (CarbonImmutable $month): string => $month->format('Y-m')
        )->all())->toBe(['2026-06', '2026-07'])
        ->and(mb_strpos($output, '2026-06'))->toBeLessThan(mb_strpos($output, '2026-07'))
        ->and($output)->toContain('2 month(s) closed.');
});

it('never closes the month that is still running', function (): void {
    sweepMemberMarkedOn('Ana', ['2026-07-10', '2026-08-01']);

    Artisan::call('logralo:close-months');

    expect(MonthlyRecap::query()->pluck('month')->map(
        fn (CarbonImmutable $month): string => $month->format('Y-m')
    )->all())->toBe(['2026-07']);
});

it('closes nothing when nobody has ever marked anything', function (): void {
    Goal::factory()->for(User::factory()->create())->create();

    Artisan::call('logralo:close-months');

    expect(Artisan::output())->toContain('No months to close.')
        ->and(MonthlyRecap::query()->count())->toBe(0);
});

it('closes a single month given with --month', function (): void {
    sweepMemberMarkedOn('Ana', ['2026-06-10', '2026-07-10']);

    Artisan::call('logralo:close-months', ['--month' => '2026-07']);

    $recaps = MonthlyRecap::query()->get();

    expect($recaps)->toHaveCount(1)
        ->and($recaps->first()->month->format('Y-m'))->toBe('2026-07')
        ->and($recaps->first()->posted_on->toDateString())->toBe('2026-07-31');
});

it('is safe to run again: a second sweep closes nothing new', function (): void {
    sweepMemberMarkedOn('Ana', ['2026-07-10']);

    Artisan::call('logralo:close-months');
    $first = MonthlyRecap::query()->sole();

    Artisan::call('logralo:close-months');

    expect(Artisan::output())->toContain('No months to close.')
        ->and(MonthlyRecap::query()->sole()->id)->toBe($first->id);
});

it('emits one canonical log line per sweep', function (): void {
    sweepMemberMarkedOn('Ana', ['2026-06-10', '2026-07-10']);

    Log::spy();

    Artisan::call('logralo:close-months');

    // One sweep line, plus one close line per month the sweep worked through.
    Log::shouldHaveReceived('info')->with('month.sweep.handled')->once();
    Log::shouldHaveReceived('info')->with('month.close.handled')->twice();

    expect(Context::get('logralo.months_closed'))->toBe(2)
        ->and(Context::get('logralo.outcome'))->toBe('completed');
});

it('schedules the sweep hourly', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'logralo:close-months'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
