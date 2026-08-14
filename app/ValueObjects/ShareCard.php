<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * The words on a share card, already resolved and already shouting.
 *
 * The compositor draws these and nothing else, which is what keeps it a pure
 * service: the mark, the streak and the member are read on the query side and
 * arrive here as four strings.
 *
 * Nothing here may carry an emoji. The card is drawn by GD, which renders one
 * TTF at a time and has no colour-glyph support, so an emoji would come out as
 * a hollow box. The flame lives in the badge's colour instead of in a
 * character.
 */
final readonly class ShareCard
{
    /**
     * @param  string  $title  the loud line — a goal name, or a month
     * @param  string|null  $badge  the accent pill above it, usually the streak
     * @param  string  $byline  who and when, under the title
     * @param  array<string, string>  $stats  label to value, drawn as a row of
     *                                        small blocks; the recap's podium
     */
    public function __construct(
        public string $title,
        public ?string $badge,
        public string $byline,
        public array $stats = [],
    ) {}
}
