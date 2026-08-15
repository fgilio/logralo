<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\Mark;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A mark as it appears in the feed, carrying the flame count it had on its own
 * day rather than the goal's flame today, and the ghost run behind the
 * "2ᵃ seguida" line.
 */
final readonly class MarkEntry implements FeedEntry
{
    public function __construct(
        public Mark $mark,
        public int $streak,
        public int $ghostRun,
        public ?PhotoLinks $photo,
    ) {}

    public function day(): CarbonImmutable
    {
        return $this->mark->marked_on;
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->mark->created_at ?? $this->mark->marked_on;
    }

    public function key(): string
    {
        return "mark-{$this->mark->id}";
    }

    /**
     * The goal, the day, and the streak beside it.
     *
     * No name on the card: it is sent by the person who earned it, into a
     * chat of five people who know each other, and the photo is of them.
     */
    public function shareCard(): ShareCard
    {
        return new ShareCard(
            title: $this->mark->goal->name,
            badge: null,
            byline: $this->mark->marked_on->translatedFormat('j \d\e F'),
            // Every mark carries its count, a first day included. Hiding the 1
            // read as the flame being broken rather than as a comment on the
            // streak's length, and the feed has always shown it: the header of
            // the very card the share button sits on says "1 🔥".
            streak: $this->streak,
        );
    }

    /**
     * Whatever the member typed when they marked it, and only if they typed
     * nothing, the app's own line.
     *
     * "Franco marcó Gimnasio" was a caption written by software, sitting under
     * a card that already said Gimnasio, sent by Franco. The note is the one
     * part of a share nobody else could have written.
     */
    public function shareText(): string
    {
        $note = Str::of($this->mark->note ?? '')->trim();

        return $note->isNotEmpty()
            ? $note->toString()
            : "Marqué {$this->mark->goal->name}";
    }

    public function shareUrl(): ?string
    {
        return $this->mark->shareUrl();
    }

    public function shareable(): Mark
    {
        return $this->mark;
    }

    public function shareKind(): string
    {
        return 'mark';
    }

    public function shareCardDirectory(): string
    {
        return $this->mark->shareCardDirectory();
    }

    public function sharePhotoKey(): ?string
    {
        return $this->mark->photo_key;
    }

    /** The browser tab, for whoever opens the link. */
    public function shareTitle(): string
    {
        $goal = $this->mark->goal->name;

        return $this->streak > 1
            ? "{$this->mark->user->name} · {$this->streak} días de {$goal}"
            : "{$this->mark->user->name} · {$goal}";
    }

    /**
     * The `id` the feed card carries, so "Abrir en Logralo" lands on it.
     *
     * Narrower than the interface's `?string` on purpose: a mark always has a
     * row to scroll to, and only the month recap has nothing.
     */
    public function shareAnchor(): string
    {
        return "mark-{$this->mark->id}";
    }
}
