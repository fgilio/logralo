<?php

declare(strict_types=1);

use App\ValueObjects\UserClock;
use Carbon\CarbonImmutable;

/**
 * The same wall-clock reading, expressed as the UTC instant a member in
 * $timezone would be living through.
 */
function instantInZone(string $timezone, string $localTime): CarbonImmutable
{
    return CarbonImmutable::parse($localTime, $timezone)->utc();
}

dataset('member timezones', [
    'ahead of UTC' => 'Pacific/Kiritimati',
    'behind UTC' => 'America/Montevideo',
    'at UTC' => 'UTC',
    'half-hour offset' => 'Asia/Kolkata',
]);

it('keeps yesterday open one second before the cutoff', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 11:59:59'));

    $clock = UserClock::in($timezone);

    expect($clock->today()->toDateString())->toBe('2026-08-11')
        ->and($clock->yesterday()->toDateString())->toBe('2026-08-10')
        ->and($clock->closesAt($clock->yesterday())->format('Y-m-d H:i:s'))->toBe('2026-08-11 12:00:00')
        ->and($clock->isOpen($clock->yesterday()))->toBeTrue()
        ->and($clock->isGraceOpen())->toBeTrue();
})->with('member timezones');

it('closes yesterday exactly at the cutoff', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 12:00:00'));

    $clock = UserClock::in($timezone);

    expect($clock->isOpen($clock->yesterday()))->toBeFalse()
        ->and($clock->isGraceOpen())->toBeFalse()
        ->and($clock->isOpen($clock->today()))->toBeTrue();
})->with('member timezones');

it('never reopens a day that already closed', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 09:00:00'));

    $clock = UserClock::in($timezone);

    expect($clock->isOpen($clock->today()->subDays(2)))->toBeFalse()
        ->and($clock->isOpen($clock->today()->subDays(30)))->toBeFalse();
})->with('member timezones');

it('offers today and yesterday while grace lasts', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 11:59:59'));

    $days = UserClock::in($timezone)->openDays();

    expect($days)->toHaveCount(2)
        ->and(array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $days))
        ->toBe(['2026-08-11', '2026-08-10']);
})->with('member timezones');

it('offers only today once grace is over', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 12:00:00'));

    $days = UserClock::in($timezone)->openDays();

    expect($days)->toHaveCount(1)
        ->and($days[0]->toDateString())->toBe('2026-08-11')
        ->and($days[0]->format('H:i:s'))->toBe('00:00:00');
})->with('member timezones');

it('holds the day until the last second before local midnight', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 23:59:59'));

    $clock = UserClock::in($timezone);

    expect($clock->today()->toDateString())->toBe('2026-08-11')
        ->and($clock->isGraceOpen())->toBeFalse();
})->with('member timezones');

it('rolls the day over at local midnight', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-12 00:00:00'));

    $clock = UserClock::in($timezone);

    expect($clock->today()->toDateString())->toBe('2026-08-12')
        ->and($clock->yesterday()->toDateString())->toBe('2026-08-11')
        ->and($clock->today()->timezone->getName())->toBe($timezone)
        ->and($clock->isGraceOpen())->toBeTrue();
})->with('member timezones');

it('counts the days of the month that have started', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 09:00:00'));
    expect(UserClock::in($timezone)->daysElapsedThisMonth())->toBe(11);

    $this->travelTo(instantInZone($timezone, '2026-08-01 00:00:00'));
    expect(UserClock::in($timezone)->daysElapsedThisMonth())->toBe(1);

    $this->travelTo(instantInZone($timezone, '2026-08-31 23:00:00'));
    expect(UserClock::in($timezone)->daysElapsedThisMonth())->toBe(31);
})->with('member timezones');

it('starts the month at local midnight on the first', function (string $timezone): void {
    $this->travelTo(instantInZone($timezone, '2026-08-11 09:00:00'));

    $start = UserClock::in($timezone)->monthStart();

    expect($start->toDateString())->toBe('2026-08-01')
        ->and($start->format('H:i:s'))->toBe('00:00:00')
        ->and($start->timezone->getName())->toBe($timezone);
})->with('member timezones');

it('puts two members in different months at the same instant', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 00:30:00', 'UTC'));

    $ahead = UserClock::in('Pacific/Kiritimati');
    $behind = UserClock::in('America/Montevideo');

    expect($ahead->today()->toDateString())->toBe('2026-08-01')
        ->and($ahead->daysElapsedThisMonth())->toBe(1)
        ->and($behind->today()->toDateString())->toBe('2026-07-31')
        ->and($behind->daysElapsedThisMonth())->toBe(31);
});

it('reads a stored instant as the day the member was living', function (): void {
    // A goal created at 22:00 on the 31st in Montevideo is stored as the 1st,
    // and the monthly standings ask exactly this question about it: was it
    // there in August. Read in UTC the answer is no, and the goal drops out of
    // the denominator of the month it was in.
    $created = CarbonImmutable::parse('2026-09-01 01:00:00', 'UTC');

    expect(UserClock::in('America/Montevideo')->dayOf($created)->toDateString())->toBe('2026-08-31')
        ->and(UserClock::in('UTC')->dayOf($created)->toDateString())->toBe('2026-09-01')
        ->and(UserClock::in('Pacific/Kiritimati')->dayOf($created)->toDateString())->toBe('2026-09-01');
});

it('reads the grace hour from configuration', function (): void {
    config()->set('logralo.grace_cutoff_hour', 9);

    $clock = UserClock::in('America/Montevideo');

    expect($clock->graceCutoffHour)->toBe(9)
        ->and($clock->closesAt(CarbonImmutable::parse('2026-08-10', 'America/Montevideo'))->format('Y-m-d H:i'))
        ->toBe('2026-08-11 09:00');
});
