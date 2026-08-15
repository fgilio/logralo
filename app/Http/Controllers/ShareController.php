<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RecordShareVisit;
use App\Queries\SharedEntry;
use App\ValueObjects\FeedEntry;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The page a shared link opens.
 *
 * Public, and it has to be: WhatsApp builds its preview with an anonymous
 * fetch, so a page behind `auth` unfurls as the login screen — which is
 * exactly what every share in the group used to look like. The token in the
 * URL is the credential.
 *
 * A member arriving here gets the card and a way back into the app; anyone
 * else gets the card and an explanation. Neither gets a login wall.
 */
final class ShareController
{
    public function __invoke(Request $request, string $token, SharedEntry $shared, RecordShareVisit $visits): View
    {
        $entry = $shared->find($token);

        // Revoked and never-existed are the same answer on purpose: a 410 would
        // confirm that a token was once real.
        throw_if(! $entry instanceof FeedEntry, NotFoundHttpException::class);

        // The counter is the only write on this page, and it is decoration:
        // it tells the sender their message landed. Letting it fail the
        // request would mean a database hiccup turns every link the group has
        // ever pasted into a 500, in the chats where they live. The Action
        // logs its own outcome, so the failure is not silent.
        rescue(fn () => $visits->handle($entry->shareable(), $request->userAgent()), report: false);

        return view('share.show', ['entry' => $entry]);
    }
}
