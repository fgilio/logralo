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

    /**
     * Bumped whenever `ShareCardComposer` changes what it draws.
     *
     * A card is composed once and kept forever, and `ShareCardRenderer` serves
     * whatever is already on the disk without asking the composer whether it
     * would still draw that. So a redesign reaches new shares only: every link
     * anybody has already sent keeps unfurling the old picture, in the chats
     * where this feature actually lives. The version in the name is what makes
     * a redesign visible to them.
     *
     * It goes in the filename rather than the directory because revoking is a
     * `deleteDirectory` on the token, and that has to take every version of the
     * card with it — a stale copy left behind after a revoke is a private photo
     * still sitting in the bucket.
     */
    public const int DESIGN = 2;

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

    /** The name the rendered card is stored under. */
    public function filename(): string
    {
        return $this->value.'-'.self::DESIGN.'.jpg';
    }
}
