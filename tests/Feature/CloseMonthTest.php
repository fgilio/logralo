<?php

declare(strict_types=1);

use App\Actions\CloseMonth;
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

it('refuses to close while one member is still inside their grace window', function (): void {
    // 06:00 UTC on 1 August: grace on 31 July is over for a member fourteen
    // hours ahead, but the Montevideo member has until 15:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-08-01 06:00', 'UTC'));

    $here = User::factory()->create(['name' => 'Ana']);
    $ahead = User::factory()->inTimezone('Pacific/Kiritimati')->create(['name' => 'Beto']);

    Goal::factory()->for($here)->create();
    Goal::factory()->for($ahead)->create();

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

    $hereGoal = Goal::factory()->for($here)->create();
    Goal::factory()->for($ahead)->create();

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

    Goal::factory()->for(User::factory()->create())->create();

    expect(closeJulyRecap()->posted_on->toDateString())->toBe('2026-07-31');
});

it('returns the same recap on a second call and creates nothing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->create();
    Mark::factory()->for($goal)->on('2026-07-04')->withPhoto()->create();

    $first = closeJulyRecap();
    $second = closeJulyRecap();

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->standings)->toBe($first->standings)
        ->and(MonthlyRecap::query()->count())->toBe(1);
});

it('freezes the standings so later archiving cannot rewrite them', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->create();

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

it('records the best streak of the month, counting a run that started before it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $runner = User::factory()->create(['name' => 'Ana']);
    $walker = User::factory()->create(['name' => 'Beto']);

    $longGoal = Goal::factory()->for($runner)->create(['name' => 'Correr']);
    $shortGoal = Goal::factory()->for($walker)->create(['name' => 'Caminar']);

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

it('counts a streak on a goal that was archived after the month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->archived()->create();

    foreach (['07-20', '07-21', '07-22', '07-23'] as $day) {
        Mark::factory()->for($goal)->on("2026-{$day}")->withPhoto()->create();
    }

    // The member has no active goal left, so the table is empty…
    $recap = closeJulyRecap();

    expect($recap->standings)->toBe([])
        ->and($recap->winner_user_id)->toBeNull()
        // …but the flame really burned, so the recap still remembers it.
        ->and($recap->best_streak_days)->toBe(4)
        ->and($recap->best_streak_goal_id)->toBe($goal->id);
});

it('leaves the streak fields empty when nobody marked anything', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    Goal::factory()->for(User::factory()->create())->create();

    $recap = closeJulyRecap();

    expect($recap->best_streak_days)->toBe(0)
        ->and($recap->best_streak_user_id)->toBeNull()
        ->and($recap->best_streak_goal_id)->toBeNull();
});

it('logs one canonical line per close', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 15:00', 'UTC'));

    Goal::factory()->for(User::factory()->create())->create();

    Log::spy();

    $recap = closeJulyRecap();

    Log::shouldHaveReceived('info')->with('month.close.handled')->once();

    expect(Context::get('logralo.month'))->toBe('2026-07')
        ->and(Context::get('logralo.outcome'))->toBe('closed')
        ->and(Context::get('logralo.recap_id'))->toBe($recap->id);
});

it('logs the not-yet outcome when the month is still open', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 06:00', 'UTC'));

    Goal::factory()->for(User::factory()->create())->create();

    Log::spy();

    expect(closeJulyRecap())->toBeNull();

    Log::shouldHaveReceived('info')->with('month.close.handled')->once();

    expect(Context::get('logralo.outcome'))->toBe('not_yet');
});
