<?php

declare(strict_types=1);

use App\Actions\CloseMonth;
use App\Actions\MarkGoal;
use App\Enums\GoalVisibility;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Notifications\MonthClosed;
use App\Notifications\PushNotification;
use App\Notifications\StreakAboutToBreak;
use App\Notifications\StreakMilestoneReached;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    // Mid-morning Montevideo: today is open and so is yesterday's grace.
    $this->travelTo(CarbonImmutable::parse('2026-08-12 10:00', 'America/Montevideo')->utc());

    $this->ana = User::factory()->create(['name' => 'Ana']);
    $this->beto = User::factory()->create(['name' => 'Beto']);
    $this->caro = User::factory()->create(['name' => 'Caro']);
});

/**
 * Marks a goal on each of the given days without going through the Action.
 *
 * Full marks, so the run these build never trips the photo rule on the tap
 * the test is actually about.
 */
function markedOn(Goal $goal, string ...$days): void
{
    foreach ($days as $day) {
        Mark::factory()->for($goal)->on($day)->withPhoto()->create();
    }
}

it('tells the rest of the group when a mark lands on a milestone streak', function (): void {
    $goal = Goal::factory()->for($this->ana)->create(['name' => 'Gimnasio', 'emoji' => '🏋️']);
    markedOn($goal, '2026-08-10', '2026-08-11');

    resolve(MarkGoal::class)->handle($goal, $this->ana->clock()->today());

    Notification::assertSentTo([$this->beto, $this->caro], StreakMilestoneReached::class);
    Notification::assertNotSentTo($this->ana, StreakMilestoneReached::class);
});

it('says nothing on a streak that is not a round number', function (): void {
    $goal = Goal::factory()->for($this->ana)->create();
    markedOn($goal, '2026-08-11');

    resolve(MarkGoal::class)->handle($goal, $this->ana->clock()->today());

    Notification::assertNothingSent();
});

it('keeps a private goal out of the group buzz however long the streak', function (): void {
    $goal = Goal::factory()->for($this->ana)->create(['visibility' => GoalVisibility::Private]);
    markedOn($goal, '2026-08-10', '2026-08-11');

    resolve(MarkGoal::class)->handle($goal, $this->ana->clock()->today());

    Notification::assertNothingSent();
});

it('counts the marked day rather than today when a milestone lands in grace', function (): void {
    // Yesterday would be the third day of the run; today is still unmarked,
    // so counting back from today would find nothing to celebrate.
    $goal = Goal::factory()->for($this->ana)->create();
    markedOn($goal, '2026-08-09', '2026-08-10');

    resolve(MarkGoal::class)->handle($goal, $this->ana->clock()->yesterday());

    Notification::assertSentTo($this->beto, StreakMilestoneReached::class);
});

it('tells everybody, winner included, that the month is closed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 18:00', 'America/Montevideo')->utc());

    foreach ([$this->ana, $this->beto, $this->caro] as $member) {
        Goal::factory()->for($member)->create(['created_at' => CarbonImmutable::parse('2026-08-01')]);
    }

    $recap = resolve(CloseMonth::class)->handle(CarbonImmutable::parse('2026-08-01'));

    expect($recap)->not->toBeNull();

    Notification::assertSentTo([$this->ana, $this->beto, $this->caro], MonthClosed::class);
});

it('nudges a member whose streak breaks when their grace window shuts', function (): void {
    $goal = Goal::factory()->for($this->ana)->create();
    markedOn($goal, '2026-08-09', '2026-08-10');

    $this->artisan('logralo:push-reminders')->assertSuccessful();

    Notification::assertSentTo($this->ana, StreakAboutToBreak::class);
    Notification::assertNotSentTo($this->beto, StreakAboutToBreak::class);
});

it('stays quiet while the grace window is still hours away', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-12 07:00', 'America/Montevideo')->utc());

    $goal = Goal::factory()->for($this->ana)->create();
    markedOn($goal, '2026-08-09', '2026-08-10');

    $this->artisan('logralo:push-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('stays quiet when the closing day is already marked', function (): void {
    $goal = Goal::factory()->for($this->ana)->create();
    markedOn($goal, '2026-08-09', '2026-08-10', '2026-08-11');

    $this->artisan('logralo:push-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('stays quiet when an unmarked day has no run behind it', function (): void {
    Goal::factory()->for($this->ana)->create();

    $this->artisan('logralo:push-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('nudges once however many times the hourly sweep runs inside the window', function (): void {
    $goal = Goal::factory()->for($this->ana)->create();
    markedOn($goal, '2026-08-09', '2026-08-10');

    $this->artisan('logralo:push-reminders')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-08-12 11:00', 'America/Montevideo')->utc());
    $this->artisan('logralo:push-reminders')->assertSuccessful();

    Notification::assertSentTimes(StreakAboutToBreak::class, 1);
});

it('counts every goal whose run ends at the same cutoff', function (): void {
    $gym = Goal::factory()->for($this->ana)->create();
    $reading = Goal::factory()->for($this->ana)->create();

    markedOn($gym, '2026-08-09', '2026-08-10');
    markedOn($reading, '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-08', '2026-08-09', '2026-08-10');

    $this->artisan('logralo:push-reminders')->assertSuccessful();

    Notification::assertSentTo(
        $this->ana,
        StreakAboutToBreak::class,
        function (StreakAboutToBreak $notification): bool {
            $message = $notification->toWebPush($this->ana)->toArray();

            return $message['title'] === 'Se te van 2 rachas'
                && $message['body'] === 'Marcá ayer antes de las 12:00.';
        },
    );
});

it('draws the group buzz as a headline over the goal it belongs to', function (): void {
    $goal = Goal::factory()->for($this->ana)->create(['name' => 'Gimnasio', 'emoji' => '🏋️']);

    $message = new StreakMilestoneReached('Ana', $goal->emoji, $goal->name, 7, 'Una semana entera')
        ->toWebPush($this->beto)
        ->toArray();

    expect($message['title'])->toBe('Ana: Una semana entera')
        ->and($message['body'])->toBe('🏋️ Gimnasio')
        ->and($message['tag'])->toBe('milestone:Ana:Gimnasio:7');
});

it('gives every buzz the envelope sw.js reads', function (PushNotification $notification): void {
    // The half of the payload no message decides for itself. Renamed here and
    // the lock screen draws a notification with no icon, or one that opens
    // nothing when it is tapped.
    $message = $notification->toWebPush($this->ana)->toArray();

    expect($message['icon'])->toBe('/icons/icon-192.png')
        ->and($message['data'])->toBe(['url' => '/']);
})->with([
    'milestone' => fn (): PushNotification => new StreakMilestoneReached('Ana', '🏋️', 'Gimnasio', 7, 'Una semana entera'),
    'recap' => fn (): PushNotification => new MonthClosed('2026-08', 'Beto', 'Ganó'),
    'grace' => fn (): PushNotification => new StreakAboutToBreak(1, 12, '12:00'),
]);

it('names the month and the winner in the recap buzz', function (): void {
    $message = new MonthClosed('2026-08', 'Beto', 'Ganó')->toWebPush($this->ana)->toArray();

    expect($message['title'])->toBe('Se cerró agosto')
        ->and($message['body'])->toBe('Ganó Beto. Mirá cómo quedó la tabla.');
});

it('puts the verb in the plural when the podium is shared', function (): void {
    $message = new MonthClosed('2026-08', 'Beto y Caro', 'Ganaron')->toWebPush($this->ana)->toArray();

    expect($message['body'])->toBe('Ganaron Beto y Caro. Mirá cómo quedó la tabla.');
});

it('closes a month nobody won without naming one', function (): void {
    $message = new MonthClosed('2026-08', '', 'Ganó')->toWebPush($this->ana)->toArray();

    expect($message['body'])->toBe('Mirá cómo quedó la tabla.');
});

it('says a single streak in the singular', function (): void {
    $message = new StreakAboutToBreak(1, 12, '12:00')->toWebPush($this->ana)->toArray();

    expect($message['title'])->toBe('Se te va una racha de 12 días');
});
