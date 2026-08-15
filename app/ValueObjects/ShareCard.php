<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * The words on a share card, already resolved and already shouting.
 *
 * The compositor draws these and nothing else, which is what keeps it a pure
 * service: the mark, the streak and the member are read on the query side and
 * arrive here as plain values.
 *
 * Nothing here may carry an emoji. The card is drawn by GD, which renders one
 * TTF at a time and has no colour-glyph support, so an emoji would come out as
 * a hollow box — which is why the streak arrives as a bare number, and the
 * flame after it is a picture the compositor inserts rather than a character.
 */
final readonly class ShareCard
{
    /**
     * @param  string  $title  the loud line — a goal name, or a month
     * @param  string|null  $badge  the accent pill above it
     * @param  string  $byline  when, under the title
     * @param  int|null  $streak  drawn in ember at the end of the byline, with
     *                            the flame after it
     * @param  array<string, string>  $stats  label to value, drawn as a row of
     *                                        small blocks; the recap's podium
     */
    public function __construct(
        public string $title,
        public ?string $badge,
        public string $byline,
        public ?int $streak = null,
        public array $stats = [],
    ) {}

    /**
     * What a bare link to the app unfurls as: no mark behind it, so no photo
     * and nothing private — just the wordmark and the pitch.
     */
    public static function pitch(): self
    {
        return new self(
            title: 'Logralo',
            badge: 'Entre amigos',
            byline: 'Objetivos diarios, rachas y pruebas con foto.',
        );
    }
}
