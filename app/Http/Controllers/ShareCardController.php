<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShareCardFormat;
use App\Queries\SharedEntry;
use App\Services\ShareCardRenderer;
use App\ValueObjects\FeedEntry;
use App\ValueObjects\ShareCard;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The image under a shared link, and the one the share sheet sends.
 *
 * Served from this origin rather than redirected to the bucket: production
 * signs its photo URLs and those expire, while an unfurl is fetched whenever a
 * chat client feels like it.
 *
 * Never cached without revalidation, and never by a shared cache. The content
 * behind a token really is immutable, so `public, immutable` was tempting — but
 * a card can carry a private photo, and a CDN or proxy holding a year-long copy
 * would keep serving it after the token was revoked, from in front of the
 * lookup that is supposed to stop it. Revocation has to mean something, so
 * every request revalidates and the ETag makes that cost 304 rather than the
 * bytes.
 */
final class ShareCardController
{
    public function __invoke(string $token, string $format, SharedEntry $shared, ShareCardRenderer $cards): Response
    {
        // Two guards rather than one: the format is checked first so an
        // unknown one never reaches the database, and checking both in a
        // single condition made the second half unreachable — an entry only
        // exists once the format has parsed.
        $shape = ShareCardFormat::tryFrom($format);

        throw_if(! $shape instanceof ShareCardFormat, NotFoundHttpException::class);

        $entry = $shared->find($token);

        throw_if(! $entry instanceof FeedEntry, NotFoundHttpException::class);

        return $this->jpeg($cards->render(
            $entry->shareCardDirectory(),
            $shape,
            $entry->shareCard(),
            $entry->sharePhotoKey(),
        ));
    }

    /**
     * The card a bare link to the app unfurls as — no mark behind it, so no
     * photo and nothing private, just the wordmark and the pitch.
     */
    public function default(ShareCardRenderer $cards): Response
    {
        return $this->jpeg($cards->render('shares/default', ShareCardFormat::Unfurl, new ShareCard(
            title: 'Logralo',
            badge: 'Entre amigos',
            byline: 'Objetivos diarios, rachas y pruebas con foto.',
        )));
    }

    private function jpeg(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'ETag' => '"'.hash('xxh128', $body).'"',
        ]);
    }
}
