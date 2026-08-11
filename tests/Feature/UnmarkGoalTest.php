<?php

declare(strict_types=1);

use App\Actions\MarkGoal;
use App\Actions\UnmarkGoal;
use App\Exceptions\DayClosedException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\Services\PhotoProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');

    $this->travelTo(CarbonImmutable::parse('2026-08-11 05:00', 'America/Montevideo')->utc());

    $this->user = User::factory()->inTimezone('America/Montevideo')->create();
    $this->goal = Goal::factory()->for($this->user)->create();
    $this->today = $this->user->clock()->today();
    $this->mark = resolve(MarkGoal::class);
    $this->unmark = resolve(UnmarkGoal::class);
});

it('removes a ghost mark while the day is open', function (): void {
    $mark = $this->mark->handle($this->goal, $this->today);

    $this->unmark->handle($mark);

    expect(Mark::query()->count())->toBe(0);
});

it('removes the photo derivatives along with the mark', function (): void {
    $mark = $this->mark->handle(
        $this->goal,
        $this->today,
        UploadedFile::fake()->image('proof.jpg', 1200, 1500),
    );

    $key = (string) $mark->photo_key;
    $disk = Storage::disk('photos');

    expect($disk->allFiles())->not->toBeEmpty();

    $this->unmark->handle($mark);

    expect(Mark::query()->count())->toBe(0);

    foreach (PhotoProcessor::FEED_WIDTHS as $width) {
        $disk->assertMissing("{$key}/feed-{$width}.webp");
    }

    $disk->assertMissing($key.'/feed-'.PhotoProcessor::SHARE_WIDTH.'.jpg');
    $disk->assertMissing("{$key}/thumb.webp");

    expect($disk->allFiles())->toBeEmpty();
});

it('removes yesterday while the grace window still runs', function (): void {
    $mark = $this->mark->handle($this->goal, $this->user->clock()->yesterday());

    $this->unmark->handle($mark);

    expect(Mark::query()->count())->toBe(0);
});

it('refuses to remove a mark once its day has closed', function (): void {
    $mark = Mark::factory()->for($this->goal)->on('2026-08-09')->create();

    expect(fn (): null => $this->unmark->handle($mark))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->whereKey($mark->id)->exists())->toBeTrue();
});

it('keeps the photo of a mark it refused to remove', function (): void {
    $mark = $this->mark->handle(
        $this->goal,
        $this->today,
        UploadedFile::fake()->image('proof.jpg', 1200, 1500),
    );

    $key = (string) $mark->photo_key;

    // The clock rolls past the grace hour of the day after the mark.
    $this->travelTo(CarbonImmutable::parse('2026-08-12 12:00', 'America/Montevideo')->utc());

    expect(fn (): null => $this->unmark->handle($mark))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->whereKey($mark->id)->exists())->toBeTrue();
    Storage::disk('photos')->assertExists($key.'/thumb.webp');
});

it('frees the day so the goal can be marked again', function (): void {
    $mark = $this->mark->handle($this->goal, $this->today);

    $this->unmark->handle($mark);

    expect($this->mark->handle($this->goal, $this->today)->exists)->toBeTrue();
    expect(Mark::query()->count())->toBe(1);
});

it('emits exactly one canonical event per removal', function (): void {
    $mark = $this->mark->handle($this->goal, $this->today);

    Log::spy();

    $this->unmark->handle($mark);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => $message === 'mark.remove.handled')
        ->once();
});

it('closes the removal window on the member own clock', function (): void {
    // Montevideo, 11:00 local on the day after the mark: the grace hour is
    // 12:00 local, so the mark is still the member's to undo.
    $montevideo = User::factory()->inTimezone('America/Montevideo')->create();
    $montevideoGoal = Goal::factory()->for($montevideo)->create();
    $stillOpen = Mark::factory()->for($montevideoGoal)->on('2026-08-10')->create();

    $this->travelTo(CarbonImmutable::parse('2026-08-11 11:00', 'America/Montevideo')->utc());

    $this->unmark->handle($stillOpen);

    expect(Mark::query()->whereKey($stillOpen->id)->exists())->toBeFalse();

    // Tokyo, 13:00 local on the day after the mark: the grace hour has passed
    // there, even though the same instant is still morning in UTC.
    $tokyo = User::factory()->inTimezone('Asia/Tokyo')->create();
    $tokyoGoal = Goal::factory()->for($tokyo)->create();
    $closed = Mark::factory()->for($tokyoGoal)->on('2026-08-10')->create();

    $this->travelTo(CarbonImmutable::parse('2026-08-11 13:00', 'Asia/Tokyo')->utc());

    expect(fn (): null => $this->unmark->handle($closed))
        ->toThrow(DayClosedException::class);

    expect(Mark::query()->whereKey($closed->id)->exists())->toBeTrue();
});
