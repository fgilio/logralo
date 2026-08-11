<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Every screen in the app sits behind the auth middleware, so the member is
 * always there. This says so once, instead of every component pretending the
 * guest case is reachable.
 */
trait InteractsWithMember
{
    protected function member(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
