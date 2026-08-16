<?php

declare(strict_types=1);

use App\Actions\MarkGoal;
use App\Enums\MarkKind;
use App\Exceptions\DayClosedException;
use App\Exceptions\DuplicateMarkException;
use App\Exceptions\GoalArchivedException;
use App\Exceptions\MonthClosedException;
use App\Exceptions\PhotoRequiredException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\MonthlyRecap;
use App\Models\User;
use App\Services\PhotoProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');

    // Mid-morning Montevideo: today is open, and so is yesterday's grace.
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $this->user = User::factory()->inTimezone('America/Montevideo')->create();
    $this->goal = Goal::factory()->for($this->user)->create(['name' => 'Gimnasio']);
    $this->today = $this->user->clock()->today();
    $this->mark = resolve(MarkGoal::class);
});

it('marks today without a photo as a ghost mark', function (): void {
    $mark = $this->mark->handle($this->goal, $this->today);

    expect($mark->exists)->toBeTrue()
        ->and($mark->goal_id)->toBe($this->goal->id)
        ->and($mark->user_id)->toBe($this->user->id)
        ->and($mark->marked_on->toDateString())->toBe('2026-08-11')
        ->and($mark->photo_key)->toBeNull()
        ->and($mark->photo_width)->toBeNull()
        ->and($mark->photo_height)->toBeNull()
        ->and($mark->kind())->toBe(MarkKind::Ghost)
        ->and($mark->isGhost())->toBeTrue();

    expect(Mark::query()->count())->toBe(1);
    expect(Storage::disk('photos')->allFiles())->toBeEmpty();
});

it('stores every photo derivative and records the source dimensions', function (): void {
    $mark = $this->mark->handle(
        $this->goal,
        $this->today,
        UploadedFile::fake()->image('proof.jpg', 1200, 1500),
    );

    expect($mark->photo_key)->not->toBeNull()
        ->and($mark->photo_width)->toBe(1080)
        ->and($mark->photo_height)->toBe(1350)
        ->and($mark->kind())->toBe(MarkKind::Full)
        ->and($mark->isFull())->toBeTrue();

    $disk = Storage::disk('photos');

    foreach (PhotoProcessor::FEED_WIDTHS as $width) {
        $disk->assertExists("{$mark->photo_key}/feed-{$width}.webp");
    }

    $disk->assertExists($mark->photo_key.'/feed-'.PhotoProcessor::SHARE_WIDTH.'.jpg');
    $disk->assertExists("{$mark->photo_key}/thumb.webp");

    // The originals are never kept: exactly the derivatives, nothing else.
    expect($disk->allFiles())->toHaveCount(count(PhotoProcessor::FEED_WIDTHS) + 2);
});

it('squishes and trims the note', function (): void {
    $mark = $this->mark->handle($this->goal, $this->today, note: "  hola\n\n   mundo  ");

    expect($mark->note)->toBe('hola mundo');
});

it('caps the note at the configured length', function (): void {
    $mark = $this->mark->handle($this->goal, $this->today, note: str_repeat('a', 300));

    $max = config()->integer('logralo.goals.note_max_length');

    expect($mark->note)->toBe(str_repeat('a', $max))
        ->and(mb_strlen((string) $mark->note))->toBe($max);
});

it('stores a blank note as null', function (): void {
    $ghost = $this->mark->handle($this->goal, $this->today, note: "   \n  ");

    expect($ghost->note)->toBeNull();

    $other = Goal::factory()->for($this->user)->create();

    expect($this->mark->handle($other, $this->today)->note)->toBeNull();
});

it('refuses a second mark for the same goal and day', function (): void {
    $this->mark->handle($this->goal, $this->today);

    expect(fn (): Mark => $this->mark->handle($this->goal, $this->today))
        ->toThrow(DuplicateMarkException::class);

    expect(Mark::query()->count())->toBe(1);
});

it('lets a second goal take the same day', function (): void {
    $other = Goal::factory()->for($this->user)->create();

    $this->mark->handle($this->goal, $this->today);
    $this->mark->handle($other, $this->today);

    expect(Mark::query()->count())->toBe(2);
});

it('refuses to mark an archived goal', function (): void {
    $archived = Goal::factory()->for($this->user)->archived()->create();

    expect(fn (): Mark => $this->mark->handle($archived, $this->today))
        ->toThrow(GoalArchivedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('refuses to mark a day in the future', function (): void {
    expect(fn (): Mark => $this->mark->handle($this->goal, $this->today->addDay()))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('refuses to mark a day older than the grace window', function (): void {
    expect(fn (): Mark => $this->mark->handle($this->goal, $this->today->subDays(2)))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('refuses a grace mark once the month has been recapped', function (): void {
    // 09:00 on 1 September: 31 August is still open on this member's clock,
    // so nothing but the recap can refuse it.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00', 'America/Montevideo')->utc());

    MonthlyRecap::factory()->create(['month' => '2026-08-01']);

    $august = $this->user->clock()->yesterday();

    expect($this->user->clock()->isGraceOpen())->toBeTrue();

    expect(fn (): Mark => $this->mark->handle($this->goal, $august))
        ->toThrow(MonthClosedException::class);

    expect(Mark::query()->count())->toBe(0);
});

it('keeps today markable while last month is recapped', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-01 09:00', 'America/Montevideo')->utc());

    MonthlyRecap::factory()->create(['month' => '2026-08-01']);

    $mark = $this->mark->handle($this->goal, $this->user->clock()->today());

    expect($mark->marked_on->toDateString())->toBe('2026-09-01');
});

it('demands a photo after two ghost marks in a row, counting marks and not days', function (): void {
    // A ten-day gap between the two ghosts: skipping days does not launder the
    // run, so the third tap still needs proof.
    Mark::factory()->for($this->goal)->on('2026-07-30')->create();
    Mark::factory()->for($this->goal)->on('2026-08-09')->create();

    expect($this->mark->requiresPhoto($this->goal, $this->today))->toBeTrue();

    expect(fn (): Mark => $this->mark->handle($this->goal, $this->today))
        ->toThrow(PhotoRequiredException::class);

    expect(Mark::query()->count())->toBe(2);
});

it('accepts the third mark when it carries a photo and resets the run', function (): void {
    Mark::factory()->for($this->goal)->on('2026-07-30')->create();
    Mark::factory()->for($this->goal)->on('2026-08-09')->create();

    $full = $this->mark->handle(
        $this->goal,
        $this->today,
        UploadedFile::fake()->image('proof.jpg', 1200, 1500),
    );

    expect($full->kind())->toBe(MarkKind::Full);

    // The run is clean again, so tomorrow a ghost is fine.
    $this->travelTo(CarbonImmutable::parse('2026-08-12 09:00', 'America/Montevideo')->utc());

    $tomorrow = $this->user->clock()->today();

    expect($this->mark->requiresPhoto($this->goal, $tomorrow))->toBeFalse();
    expect($this->mark->handle($this->goal, $tomorrow)->kind())->toBe(MarkKind::Ghost);
});

it('lets a single ghost mark pass without a photo', function (): void {
    Mark::factory()->for($this->goal)->on('2026-08-09')->create();

    expect($this->mark->requiresPhoto($this->goal, $this->today))->toBeFalse();
    expect($this->mark->handle($this->goal, $this->today)->kind())->toBe(MarkKind::Ghost);
});

it('counts the run per goal rather than per member', function (): void {
    $other = Goal::factory()->for($this->user)->create();

    Mark::factory()->for($other)->on('2026-08-09')->create();
    Mark::factory()->for($other)->on('2026-08-10')->create();

    expect($this->mark->requiresPhoto($other, $this->today))->toBeTrue()
        ->and($this->mark->requiresPhoto($this->goal, $this->today))->toBeFalse();
});

it('ignores ghost marks that a full mark already ended', function (): void {
    Mark::factory()->for($this->goal)->on('2026-08-08')->create();
    Mark::factory()->for($this->goal)->on('2026-08-09')->create();
    Mark::factory()->for($this->goal)->withPhoto()->on('2026-08-10')->create();

    expect($this->mark->requiresPhoto($this->goal, $this->today))->toBeFalse();
    expect($this->mark->handle($this->goal, $this->today)->kind())->toBe(MarkKind::Ghost);
});

it('emits exactly one canonical event per mark', function (): void {
    Log::spy();

    $this->mark->handle($this->goal, $this->today);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => $message === 'mark.create.handled')
        ->once();
});

it('emits the canonical event even when the mark is refused', function (): void {
    $archived = Goal::factory()->for($this->user)->archived()->create();

    Log::spy();

    expect(fn (): Mark => $this->mark->handle($archived, $this->today))
        ->toThrow(GoalArchivedException::class);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => $message === 'mark.create.handled')
        ->once();
});
