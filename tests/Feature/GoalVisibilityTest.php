<?php

declare(strict_types=1);

use App\Actions\CloseMonth;
use App\Enums\GoalVisibility;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Queries\FeedPage;
use App\Queries\GroupPulse;
use App\Queries\MonthlyStandings;
use App\ValueObjects\PulseEntry;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());
});

/*
|--------------------------------------------------------------------------
| The feed
|--------------------------------------------------------------------------
*/

it('keeps a private goal marks in the owner feed', function (): void {
    $owner = User::factory()->create();
    $secret = Goal::factory()->for($owner)->private()->create();
    $mark = Mark::factory()->for($secret)->on('2026-08-11')->create();

    $page = resolve(FeedPage::class)->load($owner, 10);

    expect($page->entries->first()->key())->toBe("mark-{$mark->id}");
});

it('hides a private goal marks from everyone else', function (): void {
    $owner = User::factory()->create();
    $secret = Goal::factory()->for($owner)->private()->create();
    $shared = Goal::factory()->for($owner)->create();

    Mark::factory()->for($secret)->on('2026-08-11')->create();
    $visible = Mark::factory()->for($shared)->on('2026-08-11')->create();

    $friend = User::factory()->create();
    $page = resolve(FeedPage::class)->load($friend, 10);

    expect($page->entries->map(fn ($entry): string => $entry->key())->all())
        ->toBe(["mark-{$visible->id}"]);
});

it('keeps another member from reacting to a private mark', function (): void {
    $owner = User::factory()->create();
    $secret = Goal::factory()->for($owner)->private()->create();
    $mark = Mark::factory()->for($secret)->on('2026-08-11')->create();

    $friend = User::factory()->create();

    Livewire::actingAs($friend)
        ->test('feed')
        ->call('react', $mark->id, 'muscle');
})->throws(ModelNotFoundException::class);

it('lets the owner react to their own private mark', function (): void {
    $owner = User::factory()->create();
    $secret = Goal::factory()->for($owner)->private()->create();
    $mark = Mark::factory()->for($secret)->on('2026-08-11')->create();

    Livewire::actingAs($owner)
        ->test('feed')
        ->call('react', $mark->id, 'muscle');

    expect($mark->reactions()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The pulse ring and the monthly table
|--------------------------------------------------------------------------
*/

it('leaves private goals out of the pulse ring, for the owner too', function (): void {
    $owner = User::factory()->create(['name' => 'Ana']);
    Goal::factory()->for($owner)->create();
    $secret = Goal::factory()->for($owner)->private()->create();

    Mark::factory()->for($secret)->on('2026-08-11')->create();

    $entry = resolve(GroupPulse::class)->today()
        ->first(fn (PulseEntry $entry): bool => $entry->user->name === 'Ana');

    expect($entry->activeGoals)->toBe(1)
        ->and($entry->markedToday)->toBe(0);
});

it('leaves private goals out of both sides of the monthly score', function (): void {
    $owner = User::factory()->create(['name' => 'Ana']);
    $shared = Goal::factory()->for($owner)->create(['created_at' => '2026-07-01']);
    $secret = Goal::factory()->for($owner)->private()->create(['created_at' => '2026-07-01']);

    Mark::factory()->for($shared)->on('2026-08-10')->create();
    Mark::factory()->for($secret)->on('2026-08-10')->create();
    Mark::factory()->for($secret)->on('2026-08-11')->create();

    $standing = resolve(MonthlyStandings::class)->current()
        ->first(fn (Standing $standing): bool => $standing->name === 'Ana');

    // Eleven days on one counted goal, one ghost mark on it. The two marks on
    // the private goal move neither the numerator nor the denominator.
    expect($standing->possibleMarks)->toBe(11)
        ->and($standing->ghostMarks)->toBe(1)
        ->and($standing->fullMarks)->toBe(0);
});

it('never lets a private goal win the recap best streak', function (): void {
    $owner = User::factory()->create();
    $shared = Goal::factory()->for($owner)->create(['created_at' => '2026-07-01']);
    $secret = Goal::factory()->for($owner)->private()->create(['created_at' => '2026-07-01']);

    Mark::factory()->for($shared)->on('2026-07-10')->create();

    foreach (range(10, 15) as $day) {
        Mark::factory()->for($secret)->on("2026-07-{$day}")->create();
    }

    $recap = resolve(CloseMonth::class)->handle(CarbonImmutable::parse('2026-07-01'));

    expect($recap->best_streak_goal_id)->toBe($shared->id)
        ->and($recap->best_streak_days)->toBe(1);
});

it('drops a member whose goals are all private from the table', function (): void {
    $owner = User::factory()->create(['name' => 'Ana']);
    Goal::factory()->for($owner)->private()->create(['created_at' => '2026-07-01']);

    expect(resolve(MonthlyStandings::class)->current())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Through the profile screen
|--------------------------------------------------------------------------
*/

it('creates a private goal from the profile screen', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::profile')
        ->set('goalEmoji', '🧘')
        ->set('goalName', 'Meditar')
        ->set('goalPrivate', true)
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($user->activeGoals()->sole()->visibility)->toBe(GoalVisibility::Private);
});

it('flips visibility both ways from the profile screen', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->private()->create();

    $component = Livewire::actingAs($user)
        ->test('pages::profile')
        ->call('editGoal', $goal->id)
        ->assertSet('goalPrivate', true)
        ->set('goalPrivate', false)
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($goal->fresh()->visibility)->toBe(GoalVisibility::Group);

    $component
        ->call('editGoal', $goal->id)
        ->assertSet('goalPrivate', false)
        ->set('goalPrivate', true)
        ->call('saveGoal')
        ->assertHasNoErrors();

    expect($goal->fresh()->visibility)->toBe(GoalVisibility::Private);
});

it('applies a visibility flip to marks that already exist', function (): void {
    $owner = User::factory()->create();
    $goal = Goal::factory()->for($owner)->private()->create();
    $mark = Mark::factory()->for($goal)->on('2026-08-10')->create();

    $friend = User::factory()->create();
    $friendFeed = fn () => resolve(FeedPage::class)->load($friend, 10)
        ->entries->map(fn ($entry): string => $entry->key())->all();

    expect($friendFeed())->toBe([]);

    $goal->update(['visibility' => GoalVisibility::Group]);

    expect($friendFeed())->toBe(["mark-{$mark->id}"]);

    $goal->update(['visibility' => GoalVisibility::Private]);

    expect($friendFeed())->toBe([]);
});

it('defaults every goal to the group', function (): void {
    $user = User::factory()->create();

    $goal = $user->goals()->create(['emoji' => '🏃', 'name' => 'Correr', 'position' => 1]);

    expect($goal->fresh()->visibility)->toBe(GoalVisibility::Group)
        ->and($goal->isPrivate())->toBeFalse();
});
