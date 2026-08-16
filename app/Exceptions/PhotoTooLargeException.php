<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when an upload would cost more memory to decode than the container
 * has to give — a camera original that reached the server without passing
 * through the browser-side resize, in practice.
 */
final class PhotoTooLargeException extends UserFacingException
{
    public function __construct(string $filename, float $megapixels, int $ceiling)
    {
        parent::__construct(sprintf(
            'Uploaded image [%s] is %.1f MP, past the %d MP this server will decode.',
            $filename,
            $megapixels,
            $ceiling,
        ));
    }

    public function userMessage(): string
    {
        return 'Esa foto es enorme. Sacala desde el teléfono, o achicala antes de subirla.';
    }
}
