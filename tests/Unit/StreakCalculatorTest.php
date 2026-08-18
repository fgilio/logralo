<?php

declare(strict_types=1);

use App\Services\StreakCalculator;
use App\ValueObjects\UserClock;
use Carbon\CarbonImmutable;

/**
 * Every case here is run on a Montevideo member whose day 2026-08-10 closes on
 * 2026-08-11 at 12:00 local.
 */
function montevideoClockAt(string $localTime): UserClock
{
    test()->travelTo(CarbonImmutable::parse($localTime, 'America/Montevideo')->utc());

    return UserClock::in('America/Montevideo');
}

/** @return list<string> */
function runOfDates(string $lastDate, int $days): array
{
    $day = CarbonImmutable::parse($lastDate);

    return collect(range(0, $days - 1))
        ->map(fn (int $back): string => $day->subDays($back)->toDateString())
        ->values()
        ->all();
}

it('does not break the streak on an unmarked but still open today', function (): void {
    $clock = montevideoClockAt('2026-08-11 09:00');

    $streak = new StreakCalculator()->current(
        ['2026-08-08', '2026-08-09', '2026-08-10'],
        $clock,
    );

    expect($streak)->toBe(3);
});

it('does not break the streak on an unmarked yesterday during grace', function (): void {
    $clock = montevideoClockAt('2026-08-11 09:00');

    $streak = new StreakCalculator()->current(
        ['2026-08-11', '2026-08-09'],
        $clock,
    );

    expect($streak)->toBe(2);
});

it('breaks the streak on a day that closed unmarked', function (): void {
    $clock = montevideoClockAt('2026-08-11 15:00');

    $streak = new StreakCalculator()->current(
        ['2026-08-11', '2026-08-09', '2026-08-08'],
        $clock,
    );

    expect($streak)->toBe(1);
});

it('counts a long unbroken run', function (): void {
    $clock = montevideoClockAt('2026-08-11 15:00');

    $streak = new StreakCalculator()->current(
        runOfDates('2026-08-11', 45),
        $clock,
    );

    expect($streak)->toBe(45);
});

it('reads an empty history as no streak', function (): void {
    $clock = montevideoClockAt('2026-08-11 09:00');

    expect(new StreakCalculator()->current([], $clock))->toBe(0);
});

it('ignores the order the marked dates arrive in', function (): void {
    $clock = montevideoClockAt('2026-08-11 15:00');

    $streak = new StreakCalculator()->current(
        ['2026-08-09', '2026-08-11', '2026-08-10'],
        $clock,
    );

    expect($streak)->toBe(3);
});

it('keeps the current streak across paused days without adding them', function (): void {
    $clock = montevideoClockAt('2026-08-18 09:00');

    $streak = new StreakCalculator()->current(
        ['2026-08-08', '2026-08-09', '2026-08-10'],
        $clock,
        [['from' => '2026-08-11', 'through' => '2026-08-17']],
    );

    expect($streak)->toBe(3);
});

it('breaks a resumed streak when a later active day closes unmarked', function (): void {
    $clock = montevideoClockAt('2026-08-19 15:00');

    $streak = new StreakCalculator()->current(
        ['2026-08-08', '2026-08-09', '2026-08-10'],
        $clock,
        [['from' => '2026-08-11', 'through' => '2026-08-17']],
    );

    expect($streak)->toBe(0);
});

it('counts the run ending on a mid-history day', function (): void {
    $dates = ['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-08'];

    $calculator = new StreakCalculator();

    expect($calculator->endingOn($dates, CarbonImmutable::parse('2026-08-04')))->toBe(4)
        ->and($calculator->endingOn($dates, CarbonImmutable::parse('2026-08-05')))->toBe(5)
        ->and($calculator->endingOn($dates, CarbonImmutable::parse('2026-08-08')))->toBe(1);
});

it('reads an unmarked day as no streak at all', function (): void {
    $calculator = new StreakCalculator();

    expect($calculator->endingOn(['2026-08-01', '2026-08-02'], CarbonImmutable::parse('2026-08-06')))->toBe(0)
        ->and($calculator->endingOn([], CarbonImmutable::parse('2026-08-06')))->toBe(0);
});

it('ignores the time of day on the day it ends on', function (): void {
    $streak = new StreakCalculator()->endingOn(
        ['2026-08-03', '2026-08-04'],
        CarbonImmutable::parse('2026-08-04 23:45', 'America/Montevideo'),
    );

    expect($streak)->toBe(2);
});

it('counts a run ending after more than one pause', function (): void {
    $streak = new StreakCalculator()->endingOn(
        ['2026-08-08', '2026-08-09', '2026-08-10', '2026-08-15', '2026-08-18'],
        CarbonImmutable::parse('2026-08-18'),
        [
            ['from' => '2026-08-11', 'through' => '2026-08-14'],
            ['from' => '2026-08-16', 'through' => '2026-08-17'],
        ],
    );

    expect($streak)->toBe(5);
});

it('keeps the length of a run that started before the window', function (): void {
    $best = new StreakCalculator()->bestWithin(
        ['2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01', '2026-08-02'],
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($best)->toBe(6);
});

it('stops counting a run at the end of the window', function (): void {
    $best = new StreakCalculator()->bestWithin(
        runOfDates('2026-09-05', 9),
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($best)->toBe(4);
});

it('picks the longest of several runs inside the window', function (): void {
    $best = new StreakCalculator()->bestWithin(
        [
            '2026-08-02', '2026-08-03',
            '2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14',
            '2026-08-20', '2026-08-21', '2026-08-22',
        ],
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
    );

    expect($best)->toBe(5);
});

it('keeps a monthly run connected across a pause', function (): void {
    $best = new StreakCalculator()->bestWithin(
        ['2026-07-30', '2026-07-31', '2026-08-05', '2026-08-06'],
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31'),
        [['from' => '2026-08-01', 'through' => '2026-08-04']],
    );

    expect($best)->toBe(4);
});

it('reads an empty window history as no best streak', function (): void {
    $calculator = new StreakCalculator();

    expect($calculator->bestWithin([], CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')))->toBe(0)
        ->and($calculator->bestWithin(
            ['2026-07-04', '2026-09-04'],
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        ))->toBe(0);
});
