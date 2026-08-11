<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PhotoUnreadableException;
use App\ValueObjects\PhotoLinks;
use App\ValueObjects\StoredPhoto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Everything that happens to a goal photo between the phone and the feed.
 *
 * Phone cameras hand us 8–12 MB originals and the feed shows them full-bleed
 * over mobile data, so originals are never stored. Each upload becomes a small
 * set of derivatives under one key: WebP at two widths for the srcset, a JPEG
 * that doubles as the share-sheet payload (WhatsApp will not take WebP), and a
 * square thumbnail for the goal card.
 *
 * Every derivative is written with the EXIF stripped. A photo feed has no
 * business shipping everyone's GPS coordinates, and stripping is the reason
 * this uses Intervention's encoders directly rather than Laravel 13's
 * first-party Image facade, which exposes no strip option.
 */
final readonly class PhotoProcessor
{
    /** Widths rendered into the feed srcset. 1080 covers phones at DPR 2–3. */
    public const array FEED_WIDTHS = [720, 1080];

    /** The width the JPEG fallback and the share sheet use. */
    public const int SHARE_WIDTH = 1080;

    public const int THUMBNAIL_SIZE = 320;

    public function __construct(private ImageManager $images) {}

    /**
     * @throws PhotoUnreadableException when the upload is not an image this
     *                                  build of PHP can decode — a HEIC on a
     *                                  GD-only host, in practice
     */
    public function store(UploadedFile $file): StoredPhoto
    {
        $path = (string) $file->getRealPath();

        // The key is a directory on the photo disk; the disk itself is what
        // decides where that lives.
        $key = (string) Str::ulid();
        $webpQuality = (int) config('logralo.photos.webp_quality');
        $jpegQuality = (int) config('logralo.photos.jpeg_quality');

        // The dimensions recorded are the ones actually stored, so the feed's
        // width and height attributes describe the file the browser fetches.
        $stored = null;

        foreach (self::FEED_WIDTHS as $width) {
            // Intervention modifiers mutate in place, so every derivative
            // starts from its own decode rather than resizing a resize.
            $variant = $this->decode($path, $file->getClientOriginalName())->scaleDown(width: $width);
            $stored = $variant;

            $this->disk()->put(
                "{$key}/feed-{$width}.webp",
                (string) $variant->encode(new WebpEncoder(quality: $webpQuality, strip: true)),
            );

            if ($width === self::SHARE_WIDTH) {
                $this->disk()->put(
                    "{$key}/feed-{$width}.jpg",
                    (string) $variant->encode(new JpegEncoder(quality: $jpegQuality, progressive: true, strip: true)),
                );
            }
        }

        $thumbnail = $this->decode($path, $file->getClientOriginalName())
            ->cover(self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE);

        $this->disk()->put(
            "{$key}/thumb.webp",
            (string) $thumbnail->encode(new WebpEncoder(quality: $webpQuality, strip: true)),
        );

        return new StoredPhoto(
            key: $key,
            width: $stored->width(),
            height: $stored->height(),
        );
    }

    public function delete(?string $key): void
    {
        if ($key === null) {
            return;
        }

        $this->disk()->deleteDirectory($key);
    }

    public function links(string $key, int $width, int $height): PhotoLinks
    {
        $srcset = collect(self::FEED_WIDTHS)
            ->map(fn (int $feedWidth): string => $this->url("{$key}/feed-{$feedWidth}.webp")." {$feedWidth}w")
            ->implode(', ');

        return new PhotoLinks(
            srcset: $srcset,
            fallbackUrl: $this->url($key.'/feed-'.self::SHARE_WIDTH.'.jpg'),
            thumbnailUrl: $this->url("{$key}/thumb.webp"),
            width: $width,
            height: $height,
        );
    }

    /**
     * Public buckets keep the feed cacheable; private ones get a signed URL.
     */
    public function url(string $path): string
    {
        $disk = $this->disk();

        if (config('logralo.photos.signed_urls') === true && $disk instanceof FilesystemAdapter && $disk->providesTemporaryUrls()) {
            $minutes = (int) config('logralo.photos.url_ttl_minutes');

            // Signing on every render would hand the browser a new `src` each
            // time the feed polls, so every photo would download again. The
            // signature is minted once and then reused for three quarters of
            // its life: long enough for the cache to work, short enough that
            // nobody receives a URL about to expire under them.
            return Cache::remember(
                'logralo.photo-url:'.$path,
                now()->addMinutes(max(1, intdiv($minutes * 3, 4))),
                fn (): string => $disk->temporaryUrl($path, now()->addMinutes($minutes)),
            );
        }

        return $disk->url($path);
    }

    private function decode(string $path, string $originalName): ImageInterface
    {
        try {
            // Auto-orientation is on by default, so the decode already applies
            // the EXIF rotation an iPhone photo arrives with.
            return $this->images->decodePath($path);
        } catch (Throwable $throwable) {
            throw new PhotoUnreadableException($originalName, $throwable);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('logralo.photos.disk'));
    }
}
