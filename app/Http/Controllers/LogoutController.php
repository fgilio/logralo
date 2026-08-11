<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * A POST, never a link: logging out must not be something a prefetch or an
 * image tag can do to you. The token is regenerated as well as the session, so
 * nothing signed with the old one survives.
 */
final class LogoutController
{
    public function __invoke(): RedirectResponse
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        return to_route('login');
    }
}
