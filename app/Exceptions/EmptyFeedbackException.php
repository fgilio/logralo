<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when there is nothing left of a message once it has been trimmed.
 * The sheet asks for one already, but the Action is what always runs, and a
 * blank row is a notification about nothing.
 */
final class EmptyFeedbackException extends UserFacingException
{
    public static function make(): self
    {
        return new self('Feedback body is empty.');
    }

    public function userMessage(): string
    {
        return 'Escribí algo antes de enviarlo.';
    }
}
