<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use Carbon\CarbonImmutable;

/**
 * Anything the group feed can show. Marks are the bulk of it; a month-end
 * recap is the other kind.
 *
 * Everything the feed shows is also something a member can send to WhatsApp,
 * so the share side of the contract lives here too: the words the card is
 * drawn with, the line that goes in the message, and where the link points.
 */
interface FeedEntry
{
    /** The day divider this entry sits under. */
    public function day(): CarbonImmutable;

    /** Ordering within a day, newest first. */
    public function occurredAt(): CarbonImmutable;

    /** Stable wire:key for the feed loop. */
    public function key(): string;

    /** The words drawn on the shared image. Never carries an emoji. */
    public function shareCard(): ShareCard;

    /**
     * The line typed into the chat next to the link. This one may carry an
     * emoji: it is text in WhatsApp, not a glyph GD has to draw.
     */
    public function shareText(): string;

    /** Null once sharing has been revoked. */
    public function shareUrl(): ?string;

    /** The row the token belongs to, for counting visits and revoking. */
    public function shareable(): Mark|MonthlyRecap;

    /** "mark" or "recap" — which of the two a revoked card has to be put back as. */
    public function shareKind(): string;

    /** Where the rendered cards for this entry are cached. */
    public function shareCardDirectory(): string;

    /** The photo the card is cut from, if this entry has one. */
    public function sharePhotoKey(): ?string;
}
