<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A member's own clock.
 *
 * Every cutoff in Logralo is local to the member: their day D accepts marks
 * from D 00:00 until D+1 at the grace hour (12:00), then closes forever. All
 * of that arithmetic lives here so no caller has to think about timezones.
 */
final readonly class UserClock
{
    public function __construct(
        public string $timezone,
        public int $graceCutoffHour,
    ) {}

    public static function for(User $user): self
    {
        return new self($user->timezone, self::configuredGraceCutoffHour());
    }

    public static function in(string $timezone): self
    {
        return new self($timezone, self::configuredGraceCutoffHour());
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    public function yesterday(): CarbonImmutable
    {
        return $this->today()->subDay();
    }

    /**
     * An instant as the member reads it off their own phone.
     *
     * Timestamps are stored in the app's zone, so anything shown back to
     * somebody — or shown to us about them — passes through here first.
     */
    public function localize(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->timezone);
    }

    /**
     * The member's own calendar day an instant fell on.
     *
     * Timestamps are stored in the app's zone, so a row written at 22:00 on
     * 31 August in Montevideo reads as 1 September in UTC. Whose
     * August a row falls in is a question about the member.
     */
    public function dayOf(CarbonImmutable $instant): CarbonImmutable
    {
        return $this->localize($instant)->startOfDay();
    }

    public function oldestOpenDayAt(CarbonImmutable $instant): CarbonImmutable
    {
        $day = $this->dayOf($instant);

        return $this->localize($instant)->lessThan($this->closesAt($day->subDay()))
            ? $day->subDay()
            : $day;
    }

    /**
     * The instant day $day stops accepting marks: the next day at the grace
     * hour.
     */
    public function closesAt(CarbonImmutable $day): CarbonImmutable
    {
        // Only the calendar day matters here. Re-reading it in this clock's
        // zone means a date that arrived from a database column at UTC
        // midnight closes at the member's grace hour, not at UTC's.
        return CarbonImmutable::parse($day->toDateString(), $this->timezone)
            ->addDay()
            ->setTime($this->graceCutoffHour, 0);
    }

    public function isOpen(CarbonImmutable $day): bool
    {
        return $this->now()->lessThan($this->closesAt($day));
    }

    /**
     * True while yesterday can still be marked, which is exactly when the
     * grace banner shows.
     */
    public function isGraceOpen(): bool
    {
        return $this->isOpen($this->yesterday());
    }

    /**
     * The days a member may still mark, newest first: today, plus yesterday
     * while grace lasts.
     *
     * @return list<CarbonImmutable>
     */
    public function openDays(): array
    {
        return $this->isGraceOpen()
            ? [$this->today(), $this->yesterday()]
            : [$this->today()];
    }

    public function monthStart(): CarbonImmutable
    {
        return $this->today()->startOfMonth();
    }

    /**
     * Days of the current month that have started, today included. This is the
     * denominator's day count in the monthly score.
     */
    public function daysElapsedThisMonth(): int
    {
        return $this->today()->day;
    }

    private static function configuredGraceCutoffHour(): int
    {
        return config()->integer('logralo.grace_cutoff_hour');
    }
}
