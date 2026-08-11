<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule the member broke, not a fault. Every one of these carries copy the UI
 * can show as-is, so no caller has to translate an exception class into words.
 */
abstract class UserFacingException extends RuntimeException
{
    abstract public function userMessage(): string;
}
