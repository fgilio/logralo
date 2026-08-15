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

/** @return list<int> */
function feedLadder(string $html): array
{
    preg_match_all('/data-rung="(\d)"/', $html, $matches);

    return array_map(intval(...), $matches[1]);
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
        ->assertSeeHtml('data-test="react-'.$mark->id.'-fire"')
        ->assertSeeHtml('aria-pressed="true"');
});

it('refuses to react to a mark that is not there', function (): void {
    feedMorning();

    Livewire::actingAs(User::factory()->create())
        ->test('feed')
        ->call('react', '01HZZNOTAMARK', 'fire');
})->throws(ModelNotFoundException::class);

it('gives the newest mark of a day the cover, the next one half of it, and the rest rows', function (): void {
    feedMorning();

    $user = User::factory()->create();

    // One goal per mark: a goal can only be marked once on a given day.
    foreach (range(1, 4) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->addMinutes($minute)->utc());

        Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();
    }

    // Yesterday gets a cover of its own: the ladder restarts at every divider.
    Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-10')->create();

    $html = Livewire::actingAs($user)->test('feed')->html();

    expect(feedLadder($html))->toBe([3, 2, 1, 1, 3]);
});

it('lets a recap card sit in a day without taking a rung', function (): void {
    feedMorning();

    $user = User::factory()->create(['name' => 'Ana']);

    Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-07-31')->create();
    Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-07-31')->create();

    // Posted after both marks, so it sorts above them and the cover it does not
    // take is the one below it.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:05', 'America/Montevideo')->utc());

    MonthlyRecap::factory()->create([
        'month' => '2026-07-01',
        'posted_on' => '2026-07-31',
        'best_streak_days' => 0,
        'standings' => [],
    ]);

    $html = Livewire::actingAs($user)->test('feed')->html();

    expect(feedLadder($html))->toBe([3, 2])
        ->and(mb_strpos($html, 'Cerró el mes'))->toBeLessThan(mb_strpos($html, 'data-rung="3"'));
});

it('stands the goal emoji in for a mark that has no photo', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create(['name' => 'Natacion', 'emoji' => '🏊']);

    Mark::factory()->for($goal)->on('2026-08-11')->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();

    Livewire::actingAs($user)
        ->test('feed')
        ->assertSee('🏊')
        ->assertSeeHtml('aria-label="Natacion, sin foto · 2ᵃ vez seguida"');
});

it('drops the ghost count on a row, where there is no line for it', function (): void {
    feedMorning();

    $user = User::factory()->create();

    foreach (range(1, 3) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->addMinutes($minute)->utc());

        Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();
    }

    $html = Livewire::actingAs($user)->test('feed')->html();

    expect(feedLadder($html))->toBe([3, 2, 1])
        ->and(mb_substr_count($html, 'data-test="ghost-caption"'))->toBe(2);
});

it('keys every card, and gives it the id a shared link lands on', function (): void {
    feedMorning();

    $user = User::factory()->create();
    $mark = Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create();

    Livewire::actingAs($user)
        ->test('feed')
        ->assertSeeHtml('wire:key="mark-'.$mark->id.'-3"')
        ->assertSeeHtml('id="mark-'.$mark->id.'"');
});

it('shows the view count to the owner on a cover, and to nobody on a split card', function (): void {
    feedMorning();

    $ana = User::factory()->create(['name' => 'Ana']);

    foreach (range(1, 2) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->addMinutes($minute)->utc());

        Mark::factory()->for(Goal::factory()->for($ana)->create())->on('2026-08-11')->create(['share_views' => 3]);
    }

    $html = Livewire::actingAs($ana)->test('feed')->html();

    expect(mb_substr_count($html, 'data-test="share-views"'))->toBe(1);

    Livewire::actingAs(User::factory()->create())
        ->test('feed')
        ->assertDontSeeHtml('data-test="share-views"');
});

it('offers the cover the whole page, and the split card the box it really is', function (): void {
    feedMorning();

    $user = User::factory()->create();

    foreach (range(1, 3) as $minute) {
        $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->addMinutes($minute)->utc());

        Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->withPhoto()->create();
    }

    $html = Livewire::actingAs($user)->test('feed')->html();

    expect($html)->toContain('sizes="100vw"')
        ->and($html)->toContain('sizes="(max-width: 359px) 112px, 140px"')
        ->and($html)->toContain('sizes="52px"');
});

it('carries the note at every height', function (): void {
    feedMorning();

    $user = User::factory()->create();

    foreach (['la banda', 'la columna', 'la segunda linea'] as $index => $note) {
        $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->addMinutes($index)->utc());

        Mark::factory()->for(Goal::factory()->for($user)->create())->on('2026-08-11')->create(['note' => $note]);
    }

    // Newest first, so the last note written is the one on the cover, and the
    // ladder proves the other two are the split card and the row.
    $html = Livewire::actingAs($user)->test('feed')->html();

    expect(feedLadder($html))->toBe([3, 2, 1])
        ->and($html)->toContain('la segunda linea')
        ->and($html)->toContain('la columna')
        ->and($html)->toContain('la banda');
});

it('summarises the reactions a mark already has', function (): void {
    feedMorning();

    $ana = User::factory()->create(['name' => 'Ana']);
    $mark = Mark::factory()->for(Goal::factory()->for($ana)->create())->on('2026-08-11')->create();

    Livewire::actingAs($ana)
        ->test('feed')
        ->assertDontSeeHtml('data-test="reactions-'.$mark->id.'"')
        ->call('react', $mark->id, 'clap')
        ->assertSeeHtml('data-test="reactions-'.$mark->id.'"');
});

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
