<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two shapes a share card is rendered in.
 *
 * The unfurl is what WhatsApp draws under a pasted link, and its 1.91:1 is the
 * ratio that gets the large preview rather than the thumbnail tile. The
 * portrait card is the file itself, for anyone who would rather send the image
 * than the link, and 4:5 is the tallest a chat renders without cropping.
 */
enum ShareCardFormat: string
{
    case Unfurl = 'og';
    case Portrait = 'portrait';

    public function width(): int
    {
        return match ($this) {
            self::Unfurl => 1200,
            self::Portrait => 1080,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Unfurl => 630,
            self::Portrait => 1350,
        };
    }
}
