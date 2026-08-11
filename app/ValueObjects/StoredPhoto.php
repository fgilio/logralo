<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * A processed photo. The key is the directory its derivatives live under; the
 * dimensions are the ones the feed reserves space with, so a card never jumps
 * once the image loads.
 */
final readonly class StoredPhoto
{
    public function __construct(
        public string $key,
        public int $width,
        public int $height,
    ) {}
}
