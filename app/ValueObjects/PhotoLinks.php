<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * Everything a template needs to render one photo without doing any work:
 * a WebP srcset, a JPEG fallback that is also what the share sheet sends, a
 * square thumbnail, and the intrinsic size for the aspect-ratio box.
 */
final readonly class PhotoLinks
{
    public function __construct(
        public string $srcset,
        public string $fallbackUrl,
        public string $thumbnailUrl,
        public int $width,
        public int $height,
    ) {}

    public function aspectRatio(): string
    {
        return "{$this->width} / {$this->height}";
    }
}
