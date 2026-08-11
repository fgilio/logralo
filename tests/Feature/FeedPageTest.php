<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\User;
use App\Queries\FeedPage;
use App\ValueObjects\FeedResult;
use App\ValueObjects\MarkEntry;
use App\ValueObjects\PhotoLinks;
use App\ValueObjects\RecapEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Every entry key, newest first, so ordering reads as one line. */
function feedKeys(FeedResult $page): array
{
    return $page->entries->map(fn ($entry): string => $entry->key())->all();
}

/** The entry for one mark, whichever position it landed in. */
function feedEntryFor(FeedResult $page, Mark $mark): MarkEntry
{
    /** @var MarkEntry */
    return $page->entries->first(fn ($entry): bool => $entry->key() === "mark-{$mark->id}");
}

function feedAt(string $moment): void
{
    test()->travelTo(CarbonImmutable::parse($moment, 'America/Montevideo')->utc());
}

/*
|--------------------------------------------------------------------------
| Ordering
|--------------------------------------------------------------------------
*/

it('puts the newest day first and the newest mark first inside a day', function (): void {
    $user = User::factory()->create();
    $gym = Goal::factory()->for($user)->create();
    $run = Goal::factory()->for($user)->create();
    $read = Goal::factory()->for($user)->create();

    feedAt('2026-08-10 20:00');
    $yesterday = Mark::factory()->for($gym)->on('2026-08-10')->create();

    feedAt('2026-08-11 08:00');
    $early = Mark::factory()->for($run)->on('2026-08-11')->create();

    feedAt('2026-08-11 19:00');
    $late = Mark::factory()->for($read)->on('2026-08-11')->create();

    $page = resolve(FeedPage::class)->load(10);

    expect(feedKeys($page))->toBe(["mark-{$late->id}", "mark-{$early->id}", "mark-{$yesterday->id}"]);
});

it('sorts by the marked day, not by when the mark was written', function (): void {
    $user = User::factory()->create();
    $gym = Goal::factory()->for($user)->create();
    $run = Goal::factory()->for($user)->create();

    // Yesterday's mark is written during grace, so it is the newer row but the
    // older day.
    feedAt('2026-08-11 09:00');
    $today = Mark::factory()->for($gym)->on('2026-08-11')->create();

    feedAt('2026-08-11 11:00');
    $duringGrace = Mark::factory()->for($run)->on('2026-08-10')->create();

    expect(feedKeys(resolve(FeedPage::class)->load(10)))
        ->toBe(["mark-{$today->id}", "mark-{$duringGrace->id}"]);
});

it('shows the whole group, not just one member', function (): void {
    feedAt('2026-08-11 09:00');

    $mine = Mark::factory()->create();
    $theirs = Mark::factory()->create();

    expect(feedKeys(resolve(FeedPage::class)->load(10)))
        ->toHaveCount(2)
        ->toContain("mark-{$mine->id}", "mark-{$theirs->id}");
});

/*
|--------------------------------------------------------------------------
| The flame each card carries
|--------------------------------------------------------------------------
*/

it('gives every entry the streak its own day had, not today one', function (): void {
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    $first = Mark::factory()->for($goal)->on('2026-08-09')->create();
    $second = Mark::factory()->for($goal)->on('2026-08-10')->create();
    $third = Mark::factory()->for($goal)->on('2026-08-11')->create();

    $page = resolve(FeedPage::class)->load(10);

    expect(feedEntryFor($page, $third)->streak)->toBe(3)
        ->and(feedEntryFor($page, $second)->streak)->toBe(2)
        ->and(feedEntryFor($page, $first)->streak)->toBe(1);
});

it('restarts the flame after a gap in the goal history', function (): void {
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    $before = Mark::factory()->for($goal)->on('2026-08-08')->create();
    $after = Mark::factory()->for($goal)->on('2026-08-10')->create();

    $page = resolve(FeedPage::class)->load(10);

    expect(feedEntryFor($page, $before)->streak)->toBe(1)
        ->and(feedEntryFor($page, $after)->streak)->toBe(1);
});

it('counts only the goal own days towards its flame', function (): void {
    feedAt('2026-08-11 09:00');

    $user = User::factory()->create();
    $gym = Goal::factory()->for($user)->create();
    $run = Goal::factory()->for($user)->create();

    Mark::factory()->for($run)->on('2026-08-09')->create();
    Mark::factory()->for($run)->on('2026-08-10')->create();
    $lonely = Mark::factory()->for($gym)->on('2026-08-10')->create();

    expect(feedEntryFor(resolve(FeedPage::class)->load(10), $lonely)->streak)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Ghost runs
|--------------------------------------------------------------------------
*/

it('carries the ghost run on a ghost entry', function (): void {
    Storage::fake('photos');
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    $proof = Mark::factory()->for($goal)->on('2026-08-09')->withPhoto()->create();
    $firstGhost = Mark::factory()->for($goal)->on('2026-08-10')->create();
    $secondGhost = Mark::factory()->for($goal)->on('2026-08-11')->create();

    $page = resolve(FeedPage::class)->load(10);

    expect(feedEntryFor($page, $firstGhost)->ghostRun)->toBe(1)
        ->and(feedEntryFor($page, $secondGhost)->ghostRun)->toBe(2)
        ->and(feedEntryFor($page, $proof)->ghostRun)->toBe(0);
});

it('leaves the ghost run at zero on a mark with a photo', function (): void {
    Storage::fake('photos');
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    Mark::factory()->for($goal)->on('2026-08-10')->create();
    $proof = Mark::factory()->for($goal)->on('2026-08-11')->withPhoto()->create();

    expect(feedEntryFor(resolve(FeedPage::class)->load(10), $proof)->ghostRun)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Paging
|--------------------------------------------------------------------------
*/

it('reports no more when the marks fill the page exactly', function (): void {
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    foreach (['2026-08-09', '2026-08-10', '2026-08-11'] as $date) {
        Mark::factory()->for($goal)->on($date)->create();
    }

    $page = resolve(FeedPage::class)->load(3);

    expect($page->hasMore)->toBeFalse()
        ->and($page->entries)->toHaveCount(3);
});

it('reports more as soon as one mark falls past the page', function (): void {
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    foreach (['2026-08-08', '2026-08-09', '2026-08-10', '2026-08-11'] as $date) {
        Mark::factory()->for($goal)->on($date)->create();
    }

    $page = resolve(FeedPage::class)->load(3);

    expect($page->hasMore)->toBeTrue()
        ->and($page->entries)->toHaveCount(3)
        ->and(feedKeys($page))->not->toContain('mark-'.Mark::query()->where('marked_on', '2026-08-08')->sole()->id);
});

it('reports an empty feed as empty', function (): void {
    $page = resolve(FeedPage::class)->load(10);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMore)->toBeFalse();
});

it('grows the feed when the member scrolls', function (): void {
    feedAt('2026-08-11 09:00');
    config()->set('logralo.feed.page_size', 2);

    $viewer = User::factory()->create();
    $goal = Goal::factory()->for($viewer)->create();

    foreach (['2026-08-08', '2026-08-09', '2026-08-10', '2026-08-11'] as $date) {
        Mark::factory()->for($goal)->on($date)->create();
    }

    Livewire::actingAs($viewer)
        ->test('feed')
        ->assertSet('limit', 2)
        ->call('loadMore')
        ->assertSet('limit', 4)
        ->call('loadMore')
        ->assertSet('limit', 4);
});

/*
|--------------------------------------------------------------------------
| Recap cards
|--------------------------------------------------------------------------
*/

it('drops a recap into the feed at the day it was posted on', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    feedAt('2026-07-30 20:00');
    $before = Mark::factory()->for($goal)->on('2026-07-30')->create();

    feedAt('2026-07-31 09:00');
    $lastDay = Mark::factory()->for($goal)->on('2026-07-31')->create();

    // A month closes once its last day has, which is the next day at the grace
    // hour.
    feedAt('2026-08-01 12:30');
    $recap = MonthlyRecap::factory()->create(['month' => '2026-07-01', 'posted_on' => '2026-07-31']);

    feedAt('2026-08-01 20:00');
    $after = Mark::factory()->for($goal)->on('2026-08-01')->create();

    $page = resolve(FeedPage::class)->load(10);

    expect(feedKeys($page))->toBe([
        "mark-{$after->id}",
        "recap-{$recap->id}",
        "mark-{$lastDay->id}",
        "mark-{$before->id}",
    ]);

    expect($page->entries[1])->toBeInstanceOf(RecapEntry::class)
        ->and($page->entries[1]->day()->toDateString())->toBe('2026-07-31');
});

it('posts the recap above the marks of the day it closes', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    // An evening mark on the last day of the month, then the close the next
    // day at the grace hour.
    feedAt('2026-07-31 20:00');
    $lastDay = Mark::factory()->for($goal)->on('2026-07-31')->create();

    feedAt('2026-08-01 12:30');
    $recap = MonthlyRecap::factory()->create(['month' => '2026-07-01', 'posted_on' => '2026-07-31']);

    expect(feedKeys(resolve(FeedPage::class)->load(10)))
        ->toBe(["recap-{$recap->id}", "mark-{$lastDay->id}"]);
});

it('holds a recap back until the feed has scrolled that far', function (): void {
    $user = User::factory()->create();
    $goal = Goal::factory()->for($user)->create();

    feedAt('2026-07-31 20:00');
    MonthlyRecap::factory()->create(['month' => '2026-07-01', 'posted_on' => '2026-07-31']);

    feedAt('2026-08-09 20:00');
    Mark::factory()->for($goal)->on('2026-08-09')->create();
    feedAt('2026-08-10 20:00');
    Mark::factory()->for($goal)->on('2026-08-10')->create();
    feedAt('2026-08-11 20:00');
    Mark::factory()->for($goal)->on('2026-08-11')->create();

    $firstPage = resolve(FeedPage::class)->load(2);
    $wholeFeed = resolve(FeedPage::class)->load(10);

    expect($firstPage->hasMore)->toBeTrue()
        ->and($firstPage->entries)->toHaveCount(2)
        ->and($firstPage->entries->contains(fn ($entry): bool => $entry instanceof RecapEntry))->toBeFalse()
        ->and($wholeFeed->entries->contains(fn ($entry): bool => $entry instanceof RecapEntry))->toBeTrue();
});

it('shows a recap even when there are no marks at all', function (): void {
    feedAt('2026-08-01 12:30');
    $recap = MonthlyRecap::factory()->create(['month' => '2026-07-01', 'posted_on' => '2026-07-31']);

    expect(feedKeys(resolve(FeedPage::class)->load(10)))->toBe(["recap-{$recap->id}"]);
});

/*
|--------------------------------------------------------------------------
| Photos
|--------------------------------------------------------------------------
*/

it('builds photo links only for the marks that carry a photo', function (): void {
    Storage::fake('photos');
    feedAt('2026-08-11 09:00');

    $goal = Goal::factory()->create();
    $ghost = Mark::factory()->for($goal)->on('2026-08-10')->create();
    $proof = Mark::factory()->for($goal)->on('2026-08-11')->withPhoto()->create();

    $page = resolve(FeedPage::class)->load(10);

    expect(feedEntryFor($page, $ghost)->photo)->toBeNull()
        ->and(feedEntryFor($page, $proof)->photo)->toBeInstanceOf(PhotoLinks::class);
});

it('hands the template a srcset, a fallback, a thumbnail and the real size', function (): void {
    Storage::fake('photos');
    feedAt('2026-08-11 09:00');

    $mark = Mark::factory()->on('2026-08-11')->withPhoto()->create();

    $photo = feedEntryFor(resolve(FeedPage::class)->load(10), $mark)->photo;

    expect($photo)->not->toBeNull()
        ->and($photo?->srcset)->toContain("{$mark->photo_key}/feed-720.webp 720w")
        ->and($photo?->srcset)->toContain("{$mark->photo_key}/feed-1080.webp 1080w")
        ->and($photo?->fallbackUrl)->toEndWith("{$mark->photo_key}/feed-1080.jpg")
        ->and($photo?->thumbnailUrl)->toEndWith("{$mark->photo_key}/thumb.webp")
        ->and($photo?->width)->toBe(1080)
        ->and($photo?->height)->toBe(1350)
        ->and($photo?->aspectRatio())->toBe('1080 / 1350');
});

it('falls back to a 4 by 3 box when a photo has no stored size', function (): void {
    Storage::fake('photos');
    feedAt('2026-08-11 09:00');

    $mark = Mark::factory()->on('2026-08-11')->withPhoto()->create([
        'photo_width' => null,
        'photo_height' => null,
    ]);

    expect(feedEntryFor(resolve(FeedPage::class)->load(10), $mark)->photo?->aspectRatio())->toBe('4 / 3');
});
