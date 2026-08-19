<?php

declare(strict_types=1);

use App\Actions\ArchiveGoal;
use App\Actions\CloseMonth;
use App\Actions\MarkGoal;
use App\Actions\RestoreGoal;
use App\Exceptions\MonthClosedException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/** July 2026, the month every test here closes. */
function julyMonth(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-07-01');
}

function closeJulyRecap(): ?MonthlyRecap
{
    return resolve(CloseMonth::class)->handle(julyMonth());
}

/**
 * A goal that was already on the member's board during July.
 *
 * These tests travel to August, so a fixture left at the default
 * created_at counts for no month under test.
 *
 * @param  array<string, mixed>  $attributes
 */
function julyGoal(User $user, array $attributes = []): Goal
{
    return Goal::factory()->for($user)->create([
        'created_at' => julyMonth(),
        ...$attributes,
    ]);
}

it('refuses to close while one member is still inside their grace window', function (): void {
    // 06:00 UTC on 1 August: grace on 31 July is over for a member fourteen
    // hours ahead, but the Montevideo member has until 15:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-08-01 06:00', 'UTC'));

    $here = User::factory()->create(['name' => 'Ana']);
    $ahead = User::factory()->inTimezone('Pacific/Kiritimati')->create(['name' => 'Beto']);

    julyGoal($here);
    julyGoal($ahead);

    expect($here->clock()->isOpen(CarbonImmutable::parse('2026-07-31', $here->timezone)))->toBeTrue()
        ->and($ahead->clock()->isOpen(CarbonImmutable::parse('2026-07-31', $ahead->timezone)))->toBeFalse()
        ->and(resolve(CloseMonth::class)->isClosable(julyMonth()))->toBeFalse()
        ->and(closeJulyRecap())->toBeNull()
        ->and(MonthlyRecap::query()->count())->toBe(0);
});

it('closes the month once the last member is out of grace', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 06:00', 'UTC'));

    $here = User::factory()->create(['name' => 'Ana']);
    $ahead = User::factory()->inTimezone('Pacific/Kiritimati')->create(['name' => 'Beto']);

    $hereGoal = julyGoal($here);
    julyGoal($ahead);

    Mark::factory()->for($hereGoal)->on('2026-07-31')->withPhoto()->create();

    expect(closeJulyRecap())->toBeNull();

    // 15:00 UTC is noon in Montevideo: the last grace window has shut.
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    expect(resolve(CloseMonth::class)->isClosable(julyMonth()))->toBeTrue();

    $recap = closeJulyRecap();

    expect($recap)->not->toBeNull()
        ->and($recap->month->toDateString())->toBe('2026-07-01')
        ->and($recap->winner_user_id)->toBe($here->id)
        ->and($recap->runner_up_user_id)->toBe($ahead->id)
        ->and(MonthlyRecap::query()->count())->toBe(1);
});

it('posts the recap on the last day of the month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    julyGoal(User::factory()->create());

    expect(closeJulyRecap()->posted_on->toDateString())->toBe('2026-07-31');
});

it('returns the same recap on a second call and creates nothing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = julyGoal($user);
    Mark::factory()->for($goal)->on('2026-07-04')->withPhoto()->create();

    $first = closeJulyRecap();
    $second = closeJulyRecap();

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->standings)->toBe($first->standings)
        ->and(MonthlyRecap::query()->count())->toBe(1);
});

it('counts only the goals that existed before the month ended', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);

    Mark::factory()->for(julyGoal($user))->on('2026-07-01')->withPhoto()->create();

    // Created after July ended, in the gap before the sweep runs.
    Goal::factory()->for($user)->create(['position' => 2]);

    $frozen = closeJulyRecap()->standingEntries()->first();

    expect($frozen->possibleMarks)->toBe(31)
        ->and($frozen->fullMarks)->toBe(1);
});

it('omits a member whose only goal was created after the month ended', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $ana = User::factory()->create(['name' => 'Ana']);
    $beto = User::factory()->create(['name' => 'Beto']);

    Mark::factory()->for(julyGoal($ana))->on('2026-07-01')->withPhoto()->create();

    Goal::factory()->for($beto)->create();

    expect(closeJulyRecap()->standingEntries()->pluck('name')->all())->toBe(['Ana']);
});

it('drops a mark on a goal the month never saw', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);

    Mark::factory()->for(julyGoal($user))->on('2026-07-31')->withPhoto()->create();

    // Grace reaches this far in production: a goal created on the 1st accepts
    // a mark dated to the 31st until noon. Counting that mark
    // while its goal is out of the denominator is how a
    // frozen score passes 100%.
    $latecomer = Goal::factory()->for($user)->create(['position' => 2]);
    Mark::factory()->for($latecomer)->on('2026-07-31')->withPhoto()->create();

    $frozen = closeJulyRecap()->standingEntries()->first();

    expect($frozen->possibleMarks)->toBe(31)
        ->and($frozen->fullMarks)->toBe(1);
});

it('freezes the standings so later archiving cannot rewrite them', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = julyGoal($user);

    Mark::factory()->for($goal)->on('2026-07-01')->withPhoto()->create();
    Mark::factory()->for($goal)->on('2026-07-02')->create();

    $recap = closeJulyRecap();

    expect($recap->standings)->toHaveCount(1);

    $frozen = $recap->standingEntries()->first();

    expect($frozen->name)->toBe('Ana')
        ->and($frozen->fullMarks)->toBe(1)
        ->and($frozen->ghostMarks)->toBe(1)
        ->and($frozen->possibleMarks)->toBe(31)
        ->and($frozen->rank)->toBe(1);

    $goal->forceFill(['archived_at' => now()])->save();

    $reread = MonthlyRecap::query()->findOrFail($recap->id);

    expect($reread->standings)->toBe($recap->standings)
        ->and($reread->standingEntries()->first()->possibleMarks)->toBe(31);
});

it('counts a goal archived after the month ended but before it closed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/Montevideo')->utc());

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = julyGoal($user);

    Mark::factory()->for($goal)->on('2026-07-01')->withPhoto()->create();
    Mark::factory()->for($goal)->on('2026-07-02')->create();

    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $frozen = closeJulyRecap()->standingEntries()->sole();

    expect($frozen->fullMarks)->toBe(1)
        ->and($frozen->ghostMarks)->toBe(1)
        ->and($frozen->possibleMarks)->toBe(31);
});

it('omits a goal archived at month end after it is restored and archived again', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-31 15:00', 'America/Montevideo')->utc());

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = julyGoal($user);

    Mark::factory()->for($goal)->on('2026-07-31')->withPhoto()->create();

    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00', 'America/Montevideo')->utc());

    resolve(RestoreGoal::class)->handle($goal);
    resolve(ArchiveGoal::class)->handle($goal);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    expect(closeJulyRecap()->standingEntries())->toBeEmpty();
});

it('uses the archive calendar after the member changes timezone before close', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-31 23:30', 'America/Adak')->utc());

    $user = User::factory()->inTimezone('America/Adak')->create(['name' => 'Ana']);
    $goal = julyGoal($user);

    resolve(ArchiveGoal::class)->handle($goal);

    $user->update(['timezone' => 'Pacific/Kiritimati']);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    expect(closeJulyRecap()->standingEntries())->toBeEmpty();
});

it('keeps a goal when a timezone change moves its archive instant into the prior month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 00:30', 'Pacific/Kiritimati')->utc());

    $user = User::factory()->inTimezone('Pacific/Kiritimati')->create(['name' => 'Ana']);
    $goal = julyGoal($user);

    Mark::factory()->for($goal)->on('2026-07-31')->withPhoto()->create();

    resolve(ArchiveGoal::class)->handle($goal);

    $user->update(['timezone' => 'America/Adak']);

    $this->travelTo(CarbonImmutable::parse('2026-08-01 23:00', 'UTC'));

    $standing = closeJulyRecap()->standingEntries()->sole();

    expect($standing->fullMarks)->toBe(1)
        ->and($standing->possibleMarks)->toBe(31);
});

it('stays shut to a member whose clock moves west after the close', function (): void {
    // Closing asks every member's clock; marking asks one. That is the whole
    // gap: any change to a member's clock after the close can reopen a day
    // the recap already scored, and this is how production gets there.
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = julyGoal($user);

    expect(closeJulyRecap())->not->toBeNull();

    // Ana lands in Mexico City and picks the timezone on her profile. Noon on
    // 1 August has not happened there yet, so 31 July is hers again.
    $user->forceFill(['timezone' => 'America/Mexico_City'])->save();

    expect($user->clock()->isGraceOpen())->toBeTrue()
        ->and($user->clock()->yesterday()->toDateString())->toBe('2026-07-31');

    expect(fn (): Mark => resolve(MarkGoal::class)->handle($goal, $user->clock()->yesterday()))
        ->toThrow(MonthClosedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('records the best streak of the month, counting a run that started before it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $runner = User::factory()->create(['name' => 'Ana']);
    $walker = User::factory()->create(['name' => 'Beto']);

    $longGoal = julyGoal($runner, ['name' => 'Correr']);
    $shortGoal = julyGoal($walker, ['name' => 'Caminar']);

    // Three days of June rolling into five of July: the flame read 8.
    foreach (['06-28', '06-29', '06-30', '07-01', '07-02', '07-03', '07-04', '07-05'] as $day) {
        Mark::factory()->for($longGoal)->on("2026-{$day}")->withPhoto()->create();
    }

    foreach (['07-10', '07-11', '07-12'] as $day) {
        Mark::factory()->for($shortGoal)->on("2026-{$day}")->withPhoto()->create();
    }

    $recap = closeJulyRecap();

    expect($recap->best_streak_days)->toBe(8)
        ->and($recap->best_streak_user_id)->toBe($runner->id)
        ->and($recap->best_streak_goal_id)->toBe($longGoal->id);
});

it('records a best streak that crosses an archive pause', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = julyGoal($user, [
        'archive_periods' => [
            [
                'archived_on' => '2026-07-03',
                'restored_on' => '2026-07-06',
                'paused_from' => '2026-07-03',
                'paused_through' => '2026-07-05',
            ],
        ],
    ]);

    foreach (['2026-07-01', '2026-07-02', '2026-07-06'] as $date) {
        Mark::factory()->for($goal)->on($date)->withPhoto()->create();
    }

    $recap = closeJulyRecap();

    expect($recap->best_streak_days)->toBe(3)
        ->and($recap->best_streak_goal_id)->toBe($goal->id);
});

it('counts standings and a streak on a goal archived after the month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->archived()->create(['created_at' => julyMonth()]);

    foreach (['07-20', '07-21', '07-22', '07-23'] as $day) {
        Mark::factory()->for($goal)->on("2026-{$day}")->withPhoto()->create();
    }

    $recap = closeJulyRecap();

    expect($recap->standingEntries()->sole()->possibleMarks)->toBe(31)
        ->and($recap->winner_user_id)->toBe($user->id)
        ->and($recap->best_streak_days)->toBe(4)
        ->and($recap->best_streak_goal_id)->toBe($goal->id);
});

it('leaves the streak fields empty when nobody marked anything', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    julyGoal(User::factory()->create());

    $recap = closeJulyRecap();

    expect($recap->best_streak_days)->toBe(0)
        ->and($recap->best_streak_user_id)->toBeNull()
        ->and($recap->best_streak_goal_id)->toBeNull();
});

it('logs one canonical line per close', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    julyGoal(User::factory()->create());

    Log::spy();

    $recap = closeJulyRecap();

    Log::shouldHaveReceived('info')->with('month.close.handled')->once();

    expect(Context::get('logralo.month'))->toBe('2026-07')
        ->and(Context::get('logralo.outcome'))->toBe('closed')
        ->and(Context::get('logralo.recap_id'))->toBe($recap->id);
});

it('logs the not-yet outcome when the month is still open', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 06:00', 'UTC'));

    julyGoal(User::factory()->create());

    Log::spy();

    expect(closeJulyRecap())->toBeNull();

    Log::shouldHaveReceived('info')->with('month.close.handled')->once();

    expect(Context::get('logralo.outcome'))->toBe('not_yet');
});
