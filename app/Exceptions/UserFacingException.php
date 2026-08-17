<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * A rule the member broke, not a fault. Every one of these carries copy the UI
 * can show as-is, so no caller has to translate an exception class into words.
 */
abstract class UserFacingException extends RuntimeException
{
    abstract public function userMessage(): string;

    /**
     * What this rejection logs as `logralo.reject_reason`: the class name in
     * the snake_case dialect every other context value speaks.
     */
    final public function reason(): string
    {
        return Str::snake(Str::chopEnd(class_basename($this), 'Exception'));
    }
}
