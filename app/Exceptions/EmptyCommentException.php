<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when there is nothing left of a comment once it has been squished.
 * The field asks for one already, but the Action is what always runs, and a
 * blank line under a photo is noise with somebody's name on it.
 */
final class EmptyCommentException extends UserFacingException
{
    public static function make(): self
    {
        return new self('Comment body is empty.');
    }

    public function userMessage(): string
    {
        return 'Escribí algo antes de enviarlo.';
    }
}
