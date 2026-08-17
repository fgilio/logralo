<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Joining the group from the link sent over WhatsApp.
 *
 * Two steps on purpose. WhatsApp fetches a link to build its preview card, and
 * a one-time token that burned on GET would be dead before the member ever
 * tapped it. So GET only proves the link is good and renders "Entrar como
 * {name}"; the POST behind that button is what burns the token and signs them
 * in.
 *
 * Every rejection looks the same — wrong token, already used, never had one —
 * so the page never tells anyone which of those it was.
 */
final class MagicLinkController
{
    /**
     * A read, not a unit of work: this only proves the link is good. So it
     * warns on a bad one and emits no handled event — the POST below is what
     * owns the outcome.
     */
    public function show(User $user, string $token): View|RedirectResponse
    {
        if (! $this->tokenMatches($user, $token)) {
            Context::add('logralo.user_id', $user->id);
            Context::add('logralo.reject_reason', 'token_invalid_or_used');
            Context::add('logralo.step', 'show');

            Log::warning('magic_link.rejected');

            return $this->deadLink();
        }

        return view('auth.magic-link', ['user' => $user, 'token' => $token]);
    }

    public function consume(Request $request, User $user, string $token): RedirectResponse
    {
        Context::add('logralo.user_id', $user->id);

        try {
            if (! $this->tokenMatches($user, $token)) {
                Context::add('logralo.outcome', 'rejected');
                Context::add('logralo.reject_reason', 'token_invalid_or_used');

                return $this->deadLink();
            }

            // Burn first: a crash on any later line must not leave a live link.
            $user->forceFill([
                'magic_link_token' => null,
                'magic_link_used_at' => CarbonImmutable::now(),
            ])->save();

            // Deliberately without "remember me": a link out of a chat backup
            // has no business minting a long-lived cookie.
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            Context::add('logralo.outcome', 'consumed');

            return to_route($user->hasPassword() ? 'today' : 'password.create');
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            // The save can burn the token and then throw, and that path must
            // still emit the event — it is the one anybody would go looking
            // for.
            Log::info('magic_link.consume.handled');
        }
    }

    private function tokenMatches(User $user, string $token): bool
    {
        if ($user->magic_link_token === null || $user->magic_link_used_at !== null) {
            return false;
        }

        return hash_equals($user->magic_link_token, hash('sha256', $token));
    }

    /** The one answer every rejection gets, whichever kind it was. */
    private function deadLink(): RedirectResponse
    {
        return to_route('login')
            ->with('status', 'Ese enlace ya no sirve. Entrá con tu email y contraseña.');
    }
}
