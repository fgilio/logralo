<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A member who arrived through a magic link is logged in but has no password
 * yet. They finish that before anything else, so a link that leaks later cannot
 * be the only thing standing between a stranger and the account.
 */
final class EnsurePasswordIsSet
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->hasPassword()) {
            return to_route('password.create');
        }

        return $next($request);
    }
}
