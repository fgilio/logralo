<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Thrown when an upload arrives in a format this build of PHP cannot decode —
 * in practice, a HEIC straight off an iPhone on a GD-only server.
 */
final class PhotoUnreadableException extends UserFacingException
{
    public function __construct(string $filename, ?Throwable $previous = null)
    {
        parent::__construct("Could not decode uploaded image [{$filename}].", previous: $previous);
    }

    public function userMessage(): string
    {
        return 'No pudimos leer esa foto. Probá sacarla de nuevo.';
    }
}
