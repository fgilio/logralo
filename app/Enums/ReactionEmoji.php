<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fixed reaction set. One reaction per member per card, tap again to
 * remove. Stored by name so the emoji characters stay changeable.
 */
enum ReactionEmoji: string
{
    case Muscle = 'muscle';
    case Fire = 'fire';
    case Clap = 'clap';
    case Laugh = 'laugh';
    case Point = 'point';

    public function character(): string
    {
        return match ($this) {
            self::Muscle => '💪',
            self::Fire => '🔥',
            self::Clap => '👏',
            self::Laugh => '😂',
            self::Point => '🫵',
        };
    }

    /**
     * What a screen reader says. Left to the emoji, it would read the Unicode
     * name in the browser's own language, and 🫵 has no useful one at all.
     */
    public function label(): string
    {
        return match ($this) {
            self::Muscle => 'Fuerza',
            self::Fire => 'Fuego',
            self::Clap => 'Aplausos',
            self::Laugh => 'Risa',
            self::Point => 'Sos vos',
        };
    }
}
