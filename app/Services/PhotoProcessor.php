<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PhotoTooLargeException;
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
     * @throws PhotoTooLargeException when decoding it would not fit in the
     *                                container
     */
    public function store(UploadedFile $file): StoredPhoto
    {
        $path = (string) $file->getRealPath();

        $this->guardPixelCount($path, $file->getClientOriginalName());

        // The key is a directory on the photo disk; the disk itself is what
        // decides where that lives.
        $key = (string) Str::ulid();
        $webpQuality = (int) config('logralo.photos.webp_quality');
        $jpegQuality = (int) config('logralo.photos.jpeg_quality');

        // The dimensions recorded are the ones actually stored, so the feed's
        // width and height attributes describe the file the browser fetches.
        // Taken from the share-width variant by name rather than from whichever
        // one the loop happened to end on: reordering FEED_WIDTHS would
        // otherwise silently change the aspect box every feed card reserves.
        $stored = null;

        foreach (self::FEED_WIDTHS as $width) {
            // Intervention modifiers mutate in place, so every derivative
            // starts from its own decode rather than resizing a resize.
            $variant = $this->decode($path, $file->getClientOriginalName())->scaleDown(width: $width);

            $this->disk()->put(
                "{$key}/feed-{$width}.webp",
                (string) $variant->encode(new WebpEncoder(quality: $webpQuality, strip: true)),
            );

            if ($width === self::SHARE_WIDTH) {
                $stored = $variant;

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

        // SHARE_WIDTH is one of FEED_WIDTHS, so the loop always sets this —
        // PHPStan proves it from the two constants and rejects a guard here as
        // dead. Take one of them out of step and it says so at that point.
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
     * The share-width JPEG's bytes, for whoever needs the picture rather than
     * a link to it — the share card compositor, in practice.
     *
     * Read off the disk rather than fetched over `url()`: on production that
     * URL is signed and short-lived, and the app is already standing next to
     * the bucket. Null when the derivative is not there, which is a card
     * drawn on the plain ground rather than an error.
     */
    public function shareJpeg(string $key): ?string
    {
        $path = $key.'/feed-'.self::SHARE_WIDTH.'.jpg';
        $disk = $this->disk();

        return $disk->exists($path) ? (string) $disk->get($path) : null;
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

    /**
     * Where every derivative lives — and, beside them, the rendered share
     * cards. Public so the card renderer has one place to ask rather than a
     * second copy of this line.
     */
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('logralo.photos.disk'));
    }

    /**
     * What decoding this would cost, asked before anything decodes it.
     *
     * `getimagesize()` reads the header and stops, so this is free; the decode
     * it stands in front of is four bytes a pixel, which puts a 48 MP camera
     * original at 190 MB inside a 512 MiB container. Past the ceiling the
     * member gets a sentence, rather than every request in flight getting an
     * OOM kill.
     *
     * Phones do not arrive here: `resources/js/compress-photo.js` resizes the
     * picture before it uploads. This is the net under the upload that skipped
     * that — a desktop drag-and-drop, or a browser where the resize failed and
     * handed the original back on purpose.
     */
    private function guardPixelCount(string $path, string $originalName): void
    {
        // False for anything the header parser does not know, which includes
        // HEIC. That one is decode()'s to refuse, and its 12 MP is not the
        // problem this guard exists for.
        $size = rescue(fn (): array|false => getimagesize($path), false, report: false);

        if ($size === false) {
            return;
        }

        $ceiling = (int) config('logralo.photos.max_megapixels');
        $megapixels = $size[0] * $size[1] / 1_000_000;

        throw_if($megapixels > $ceiling, PhotoTooLargeException::class, $originalName, $megapixels, $ceiling);
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
}
