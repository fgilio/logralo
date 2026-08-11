<?php

declare(strict_types=1);

use App\Enums\ReactionEmoji;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\Reaction;
use App\Models\User;
use App\ValueObjects\FeedResult;
use App\ValueObjects\MarkEntry;
use App\ValueObjects\RecapEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The group feed is the only history the app has: everyone's marks newest
 * first, the month-end recaps mixed in, and one reaction per member per card.
 */

/** 09:00 in Montevideo, the day everything in this file happens on. */
function feedMorning(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());
}

it('renders the marks of every member', function (): void {
    feedMorning();

    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);

    Mark::factory()->for(Goal::factory()->for($ana)->create(['name' => 'Natacion']))->on('2026-08-11')->create();
    Mark::factory()->for(Goal::factory()->for($bruno)->create(['name' => 'Guitarra']))->on('2026-08-11')->create();

    Livewire::actingAs($ana)
        ->test('feed')
        ->assertSee('Ana')
        ->assertSee('Bruno')
        ->assertSee('Natacion')
        ->assertSee('Guitarra');
});

it('starts at the configured page size', function (): void {
    feedMorning();

    Livewire::actingAs(User::factory()->create())
        ->test('feed')
        ->assertSet('limit', (int) config('logralo.feed.page_size'));
});

it('shows nothing but the empty state when no one has marked', function (): void {
    feedMorning();

    $component = Livewire::actingAs(User::factory()->create())->test('feed');

    /** @var FeedResult $page */
    $page = $component->get('page');

    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMore)->toBeFalse();

    $component->assertSee('Todavía no hay nada');
});

it('sorts newest first, by day and then by the moment it was marked', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();
    $other = Goal::factory()->for($user)->create();

    $older = Mark::factory()->for($goal)->on('2026-08-10')->create();

    // Two marks on the same day: the later one goes above.
    $first = Mark::factory()->for($goal)->on('2026-08-11')->create();
    $this->travelTo(CarbonImmutable::parse('2026-08-11 10:00', 'America/Montevideo')->utc());
    $second = Mark::factory()->for($other)->on('2026-08-11')->create();

    $entries = Livewire::actingAs($user)->test('feed')->get('page')->entries;

    expect($entries->map(fn (MarkEntry $entry): string => $entry->mark->id)->all())
        ->toBe([$second->id, $first->id, $older->id]);
});

it('groups the entries under one key per day', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->on('2026-08-11')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();
    Mark::factory()->for($goal)->on('2026-08-09')->create();

    $component = Livewire::actingAs($user)->test('feed');

    expect($component->get('days')->keys()->all())->toBe(['2026-08-11', '2026-08-10', '2026-08-09']);

    $component->assertSee('Hoy')->assertSee('Ayer');
});

it('raises the limit when loadMore is called', function (): void {
    feedMorning();

    $pageSize = (int) config('logralo.feed.page_size');
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    // One more mark than a page holds, one per day walking backwards.
    foreach (range(0, $pageSize) as $offset) {
        Mark::factory()->for($goal)
            ->on(CarbonImmutable::parse('2026-08-11')->subDays($offset)->toDateString())
            ->create();
    }

    $component = Livewire::actingAs($user)->test('feed');

    expect($component->get('page')->entries)->toHaveCount($pageSize)
        ->and($component->get('page')->hasMore)->toBeTrue();

    $component->call('loadMore')->assertSet('limit', $pageSize * 2);

    expect($component->get('page')->entries)->toHaveCount($pageSize + 1)
        ->and($component->get('page')->hasMore)->toBeFalse();
});

it('stops raising the limit once the history runs out', function (): void {
    feedMorning();

    $user = User::factory()->create();
    Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();

    Livewire::actingAs($user)
        ->test('feed')
        ->call('loadMore')
        ->assertSet('limit', (int) config('logralo.feed.page_size'));
});

it('mixes the month-end recaps in with the marks', function (): void {
    feedMorning();

    $user = User::factory()->create(['name' => 'Ana']);
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->on('2026-08-11')->create();
    Mark::factory()->for($goal)->on('2026-07-20')->create();

    $recap = MonthlyRecap::factory()->create([
        'month' => '2026-07-01',
        'posted_on' => '2026-07-31',
        'best_streak_days' => 9,
        'best_streak_user_id' => $user->id,
        'best_streak_goal_id' => $goal->id,
        'standings' => [[
            'user_id' => $user->id,
            'name' => 'Ana',
            'full_marks' => 20,
            'ghost_marks' => 4,
            'possible_marks' => 31,
            'rank' => 1,
        ]],
    ]);

    $component = Livewire::actingAs($user)->test('feed');

    $entries = $component->get('page')->entries;

    // The recap is posted on the last day of its month, so it sits between the
    // August mark and the July one.
    expect($entries->map(fn ($entry): string => $entry->key())->all())->toBe([
        'mark-'.Mark::query()->where('marked_on', '2026-08-11')->sole()->id,
        'recap-'.$recap->id,
        'mark-'.Mark::query()->where('marked_on', '2026-07-20')->sole()->id,
    ]);

    expect($entries[1])->toBeInstanceOf(RecapEntry::class);

    $component->assertSee('Cerró el mes')->assertSee('Julio 2026');
});

it('carries the flame the mark had on its own day', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    foreach (['2026-08-08', '2026-08-09', '2026-08-10'] as $date) {
        Mark::factory()->for($goal)->on($date)->create();
    }

    $entries = Livewire::actingAs($user)->test('feed')->get('page')->entries;

    expect($entries->map(fn (MarkEntry $entry): int => $entry->streak)->all())->toBe([3, 2, 1])
        // Three ghosts in a row, so the newest card says it is the third.
        ->and($entries->map(fn (MarkEntry $entry): int => $entry->ghostRun)->all())->toBe([3, 2, 1]);
});

it('hands a photo mark its links and a ghost mark none', function (): void {
    Storage::fake('photos');
    feedMorning();

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    Mark::factory()->for($goal)->withPhoto()->on('2026-08-11')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    $entries = Livewire::actingAs($user)->test('feed')->get('page')->entries;

    expect($entries[0]->photo)->not->toBeNull()
        ->and($entries[0]->photo->srcset)->toContain('feed-720.webp 720w')
        ->and($entries[0]->photo->width)->toBe(1080)
        ->and($entries[1]->photo)->toBeNull();
});

it('adds a reaction when one is tapped', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $mark = Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();

    Livewire::actingAs($user)->test('feed')->call('react', $mark->id, 'fire');

    $reaction = Reaction::query()->sole();

    expect($reaction->mark_id)->toBe($mark->id)
        ->and($reaction->user_id)->toBe($user->id)
        ->and($reaction->emoji)->toBe(ReactionEmoji::Fire);
});

it('removes a reaction when the same one is tapped again', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $mark = Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();

    Livewire::actingAs($user)
        ->test('feed')
        ->call('react', $mark->id, 'fire')
        ->call('react', $mark->id, 'fire');

    expect(Reaction::query()->count())->toBe(0);
});

it('swaps a reaction when a different one is tapped', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $mark = Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();

    Livewire::actingAs($user)
        ->test('feed')
        ->call('react', $mark->id, 'fire')
        ->call('react', $mark->id, 'clap');

    $reaction = Reaction::query()->sole();

    expect($reaction->emoji)->toBe(ReactionEmoji::Clap);
});

it('keeps one reaction per member on the same card', function (): void {
    feedMorning();

    $ana = User::factory()->create(['name' => 'Ana']);
    $bruno = User::factory()->create(['name' => 'Bruno']);
    $mark = Mark::factory()->for(Goal::factory()->for($ana)->create())->on('2026-08-11')->create();

    Livewire::actingAs($ana)->test('feed')->call('react', $mark->id, 'fire');
    Livewire::actingAs($bruno)->test('feed')->call('react', $mark->id, 'fire');

    expect(Reaction::query()->count())->toBe(2);

    // The card counts them together and marks the reader's own as pressed.
    Livewire::actingAs($bruno)
        ->test('feed')
        ->assertSeeHtml('data-test="react-fire"')
        ->assertSeeHtml('aria-pressed="true"');
});

it('refuses to react to a mark that is not there', function (): void {
    feedMorning();

    Livewire::actingAs(User::factory()->create())
        ->test('feed')
        ->call('react', '01HZZNOTAMARK', 'fire');
})->throws(ModelNotFoundException::class);

it('reloads when a mark lands anywhere on the page', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    $component = Livewire::actingAs($user)->test('feed');

    expect($component->get('page')->entries)->toHaveCount(0);

    Mark::factory()->for($goal)->on('2026-08-11')->create();

    $component->dispatch('mark-updated');

    expect($component->get('page')->entries)->toHaveCount(1);
});
