<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Validation\Rules\Password;

/**
 * One definition of what a good password is, shared by every screen that sets
 * or changes one. The rule itself is configured in AppServiceProvider.
 */
trait PasswordValidationRules
{
    /** @return list<mixed> */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::defaults(), 'confirmed'];
    }

    /** @return list<string> */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
