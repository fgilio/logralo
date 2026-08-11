<?php

declare(strict_types=1);

use App\Exceptions\PhotoUnreadableException;
use App\Services\PhotoProcessor;
use App\ValueObjects\PhotoLinks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The photo pipeline: what lands on the disk, what never grows, what the
 * templates get handed, and what happens to an upload nothing can decode.
 */

/** The intrinsic size of a derivative, straight off the faked disk. */
function derivativeSize(string $path): array
{
    $size = getimagesizefromstring((string) Storage::disk('photos')->get($path));

    expect($size)->toBeArray();

    return ['width' => $size[0], 'height' => $size[1]];
}

it('writes every derivative under one key', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 1200, 1500));

    $disk = Storage::disk('photos');

    expect($disk->allFiles())->toBe([
        "{$stored->key}/feed-1080.jpg",
        "{$stored->key}/feed-1080.webp",
        "{$stored->key}/feed-720.webp",
        "{$stored->key}/thumb.webp",
    ]);

    // Every feed width in the srcset has a WebP behind it, the JPEG fallback
    // is there for the share sheet, and the card gets its square thumbnail.
    foreach (PhotoProcessor::FEED_WIDTHS as $width) {
        expect(derivativeSize("{$stored->key}/feed-{$width}.webp")['width'])->toBe($width);
    }

    expect(derivativeSize("{$stored->key}/feed-".PhotoProcessor::SHARE_WIDTH.'.jpg')['width'])
        ->toBe(PhotoProcessor::SHARE_WIDTH)
        ->and(derivativeSize("{$stored->key}/thumb.webp"))
        ->toBe(['width' => PhotoProcessor::THUMBNAIL_SIZE, 'height' => PhotoProcessor::THUMBNAIL_SIZE]);
});

it('keeps the key clear of anything else on the disk', function (): void {
    Storage::fake('photos');

    $first = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('one.jpg', 400, 500));
    $second = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('two.jpg', 400, 500));

    expect($first->key)->not->toBe($second->key)
        ->and(Storage::disk('photos')->directories())->toHaveCount(2)
        ->and(Storage::disk('photos')->files("{$first->key}"))->toHaveCount(4);
});

it('records the dimensions the feed reserves space with', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 1200, 1500));

    // Nothing wider than the share width is ever stored, so the size the
    // templates lay out with should be the size of the largest derivative.
    expect($stored->width)->toBe(1080)
        ->and($stored->height)->toBe(1350)
        ->and(derivativeSize("{$stored->key}/feed-1080.webp"))
        ->toBe(['width' => $stored->width, 'height' => $stored->height]);
});

it('records a size the derivatives keep the aspect ratio of', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 1200, 1500));

    $feed = derivativeSize("{$stored->key}/feed-1080.webp");

    expect($stored->width / $stored->height)->toBe($feed['width'] / $feed['height']);
});

it('never enlarges a small image', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('tiny.jpg', 400, 500));

    // Both feed widths are above the source, so both stay at the source size:
    // upscaling would only cost bytes to show the same pixels.
    foreach (PhotoProcessor::FEED_WIDTHS as $width) {
        expect(derivativeSize("{$stored->key}/feed-{$width}.webp"))->toBe(['width' => 400, 'height' => 500]);
    }

    expect(derivativeSize("{$stored->key}/feed-1080.jpg"))->toBe(['width' => 400, 'height' => 500])
        ->and($stored->width)->toBe(400)
        ->and($stored->height)->toBe(500);
});

it('crops the thumbnail square even from a portrait source', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('portrait.jpg', 400, 900));

    expect(derivativeSize("{$stored->key}/thumb.webp"))->toBe([
        'width' => PhotoProcessor::THUMBNAIL_SIZE,
        'height' => PhotoProcessor::THUMBNAIL_SIZE,
    ]);
});

it('deletes the whole key', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 400, 500));
    $kept = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('kept.jpg', 400, 500));

    resolve(PhotoProcessor::class)->delete($stored->key);

    expect(Storage::disk('photos')->exists($stored->key))->toBeFalse()
        ->and(Storage::disk('photos')->files($stored->key))->toBe([])
        ->and(Storage::disk('photos')->files($kept->key))->toHaveCount(4);
});

it('shrugs off a delete with no key', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 400, 500));

    resolve(PhotoProcessor::class)->delete(null);

    expect(Storage::disk('photos')->files($stored->key))->toHaveCount(4);
});

it('builds links whose srcset lists every feed width', function (): void {
    Storage::fake('photos');

    $links = resolve(PhotoProcessor::class)->links('01HZZKEY', 1080, 1350);

    expect($links)->toBeInstanceOf(PhotoLinks::class);

    $descriptors = collect(explode(', ', $links->srcset));

    expect($descriptors)->toHaveCount(count(PhotoProcessor::FEED_WIDTHS));

    foreach (PhotoProcessor::FEED_WIDTHS as $index => $width) {
        expect($descriptors[$index])
            ->toContain("01HZZKEY/feed-{$width}.webp")
            ->toEndWith(" {$width}w");
    }

    expect($links->fallbackUrl)->toContain('01HZZKEY/feed-'.PhotoProcessor::SHARE_WIDTH.'.jpg')
        ->and($links->thumbnailUrl)->toContain('01HZZKEY/thumb.webp')
        ->and($links->width)->toBe(1080)
        ->and($links->height)->toBe(1350)
        ->and($links->aspectRatio())->toBe('1080 / 1350');
});

it('points every link at a photo it actually stored', function (): void {
    Storage::fake('photos');

    $stored = resolve(PhotoProcessor::class)->store(UploadedFile::fake()->image('proof.jpg', 400, 500));
    $links = resolve(PhotoProcessor::class)->links($stored->key, $stored->width, $stored->height);

    $paths = collect(explode(', ', $links->srcset))
        ->map(fn (string $candidate): string => Str::before($candidate, ' '))
        ->push($links->fallbackUrl, $links->thumbnailUrl)
        ->map(fn (string $url): string => Str::after($url, $stored->key.'/'));

    foreach ($paths as $path) {
        expect(Storage::disk('photos')->exists("{$stored->key}/{$path}"))->toBeTrue();
    }
});

/**
 * Fakes a private bucket: a disk that signs its URLs, with a random component
 * so a second signature is never mistaken for a reused one.
 */
function signingDisk(): void
{
    config()->set('logralo.photos.signed_urls', true);

    Storage::fake('photos')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expiresAt): string => 'https://bucket.test/'.$path
            .'?expires='.$expiresAt->getTimestamp()
            .'&signature='.Str::random(16)
    );
}

it('signs every photo URL when the bucket is private', function (): void {
    signingDisk();

    $links = resolve(PhotoProcessor::class)->links('01HZZKEY', 1080, 1350);

    expect($links->thumbnailUrl)->toStartWith('https://bucket.test/01HZZKEY/thumb.webp?expires=')
        ->and($links->thumbnailUrl)->toContain('&signature=')
        ->and($links->fallbackUrl)->toContain('expires='.now()->addHour()->getTimestamp());
});

it('hands out the same signature until it is close to expiring', function (): void {
    signingDisk();

    $first = resolve(PhotoProcessor::class)->url('01HZZKEY/feed-1080.webp');

    // The feed polls, so an <img src> that changed here would re-download the
    // whole feed over mobile data.
    $this->travel(44)->minutes();
    expect(resolve(PhotoProcessor::class)->url('01HZZKEY/feed-1080.webp'))->toBe($first);

    $this->travel(2)->minutes();
    expect(resolve(PhotoProcessor::class)->url('01HZZKEY/feed-1080.webp'))->not->toBe($first);
});

it('keeps one photo signature out of another', function (): void {
    signingDisk();

    expect(resolve(PhotoProcessor::class)->url('01HZZKEY/thumb.webp'))
        ->not->toBe(resolve(PhotoProcessor::class)->url('01HZZKEY/feed-720.webp'));
});

it('leaves URLs unsigned when the bucket is public', function (): void {
    Storage::fake('photos');

    expect(resolve(PhotoProcessor::class)->url('01HZZKEY/thumb.webp'))
        ->not->toContain('signature=');
});

it('refuses an upload it cannot decode', function (): void {
    Storage::fake('photos');

    resolve(PhotoProcessor::class)->store(UploadedFile::fake()->create('holiday.heic', 8, 'image/heic'));
})->throws(PhotoUnreadableException::class, 'Could not decode uploaded image [holiday.heic].');

it('leaves the disk clean when an upload cannot be decoded', function (): void {
    Storage::fake('photos');

    try {
        resolve(PhotoProcessor::class)->store(UploadedFile::fake()->create('holiday.heic', 8, 'image/heic'));
    } catch (PhotoUnreadableException $photoUnreadableException) {
        expect($photoUnreadableException->userMessage())->toBe('No pudimos leer esa foto. Probá sacarla de nuevo.');
    }

    expect(Storage::disk('photos')->allFiles())->toBe([]);
});
