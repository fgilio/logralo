<?php

declare(strict_types=1);

use App\Actions\AttachPhotoToMark;
use App\Exceptions\DayClosedException;
use App\Exceptions\PhotoAlreadyAddedException;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
    $this->travelTo(CarbonImmutable::parse('2026-08-11 09:00', 'America/Montevideo')->utc());

    $this->user = User::factory()->create();
    $this->goal = Goal::factory()->for($this->user)->create();
    $this->attach = resolve(AttachPhotoToMark::class);
});

it('adds proof to an open ghost mark', function (): void {
    $mark = Mark::factory()->for($this->goal)->on('2026-08-10')->create();

    $updated = $this->attach->handle(
        $mark,
        UploadedFile::fake()->image('proof.jpg', 1200, 1500),
    );

    expect($updated->isFull())->toBeTrue()
        ->and($updated->photo_width)->toBe(1080)
        ->and($updated->photo_height)->toBe(1350)
        ->and(Storage::disk('photos')->files((string) $updated->photo_key))->toHaveCount(4);
});

it('does not replace proof that is already present', function (): void {
    $mark = Mark::factory()->for($this->goal)->withPhoto()->on('2026-08-10')->create();
    $originalKey = $mark->photo_key;

    expect(fn (): Mark => $this->attach->handle(
        $mark,
        UploadedFile::fake()->image('replacement.jpg', 400, 500),
    ))->toThrow(PhotoAlreadyAddedException::class);

    expect($mark->refresh()->photo_key)->toBe($originalKey)
        ->and(Storage::disk('photos')->allFiles())->toBe([]);
});

it('does not add proof after the day closes', function (): void {
    $mark = Mark::factory()->for($this->goal)->on('2026-08-10')->create();

    $this->travelTo(CarbonImmutable::parse('2026-08-11 12:00', 'America/Montevideo')->utc());

    expect(fn (): Mark => $this->attach->handle(
        $mark,
        UploadedFile::fake()->image('proof.jpg', 400, 500),
    ))->toThrow(DayClosedException::class);

    expect($mark->refresh()->isGhost())->toBeTrue()
        ->and(Storage::disk('photos')->allFiles())->toBe([]);
});
