<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RevokeSharing;
use App\Queries\SharedEntry;
use App\ValueObjects\FeedEntry;
use App\ValueObjects\MarkEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Taking a shared link back, from the page it points at.
 *
 * This is the right home for it: the member is looking at exactly what
 * everyone they sent it to can see, which is the only moment the decision is
 * an informed one. A POST rather than a link, so no prefetch or image tag can
 * revoke somebody's share for them.
 */
final class ShareRevokeController
{
    public function __invoke(string $token, SharedEntry $shared, RevokeSharing $revoke): RedirectResponse
    {
        $entry = $shared->find($token);

        throw_if(! $entry instanceof FeedEntry, NotFoundHttpException::class);

        // Only whoever made the mark can un-share it. A recap belongs to the
        // whole group, so anyone in the group may pull that one.
        $allowed = $entry instanceof MarkEntry
            ? $entry->mark->user_id === Auth::id()
            : Auth::check();

        abort_unless($allowed, 403);

        $revoke->handle($entry->shareable());

        // A session flash, not Flux::toast(): a toast is dispatched onto a
        // Livewire component, and there is none in a plain controller — the
        // call blows up with "dispatch() on false" rather than showing
        // anything. The feed reads this on the way back, the same way the auth
        // screens do.
        return to_route('today')->with('status', 'Listo. Ese link dejó de funcionar.');
    }
}
