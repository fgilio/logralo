<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Mark;

/** Thrown when a mark already has the proof an upload tried to add. */
final class PhotoAlreadyAddedException extends UserFacingException
{
    public static function forMark(Mark $mark): self
    {
        return new self("Mark [{$mark->id}] already has a photo.");
    }

    public function userMessage(): string
    {
        return 'Ese logro ya tiene una foto.';
    }
}
