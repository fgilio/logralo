<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Models\Mark;
use Carbon\CarbonImmutable;

/**
 * A mark as it appears in the feed, carrying the flame count it had on its own
 * day rather than the goal's flame today, and the ghost run behind the
 * "2ᵃ vez seguida" line.
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

    public function shareCard(): ShareCard
    {
        return new ShareCard(
            title: $this->mark->goal->name,
            badge: $this->streak > 1 ? "{$this->streak} días seguidos" : null,
            byline: $this->mark->user->name.' · '.$this->mark->marked_on->translatedFormat('j \d\e F'),
        );
    }

    /**
     * Names the person. "🔥 Gimnasio — Logralo" told the chat nothing about
     * who had been to the gym, which is the whole brag.
     */
    public function shareText(): string
    {
        $name = $this->mark->user->name;
        $goal = $this->mark->goal->name;

        return $this->streak > 1
            ? "🔥 {$name} lleva {$this->streak} días de {$goal}"
            : "🔥 {$name} marcó {$goal}";
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

    /** The headline the share page and the unfurl's `og:title` both use. */
    public function shareTitle(): string
    {
        $goal = $this->mark->goal->name;

        return $this->streak > 1
            ? "{$this->mark->user->name} · {$this->streak} días de {$goal}"
            : "{$this->mark->user->name} · {$goal}";
    }
}
