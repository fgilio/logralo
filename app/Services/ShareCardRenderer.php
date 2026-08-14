<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShareCardFormat;
use App\ValueObjects\ShareCard;

/**
 * Share cards, rendered once and kept.
 *
 * They live on the photo disk beside the derivatives they are cut from, under
 * the share token rather than the photo key: a card belongs to the link, so
 * revoking the link is a `deleteDirectory` and the rendered brag goes with it.
 *
 * Rendering is lazy, on the first request for the card rather than when the
 * mark is made. Most marks are never shared, and composing every one of them
 * would double the work the camera button does for nothing.
 */
final readonly class ShareCardRenderer
{
    public function __construct(
        private ShareCardComposer $composer,
        private PhotoProcessor $photos,
    ) {}

    /**
     * @param  string|null  $photoKey  the mark's photo directory, or null for a
     *                                 ghost mark or a month recap
     */
    public function render(string $directory, ShareCardFormat $format, ShareCard $card, ?string $photoKey = null): string
    {
        $path = "{$directory}/{$format->filename()}";
        $disk = $this->photos->disk();

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $jpeg = $this->composer->compose(
            $format,
            $card,
            $photoKey === null ? null : $this->photos->shareJpeg($photoKey),
        );

        $disk->put($path, $jpeg);

        return $jpeg;
    }

    public function forget(string $directory): void
    {
        $this->photos->disk()->deleteDirectory($directory);
    }
}
