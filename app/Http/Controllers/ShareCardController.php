<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ShareCardFormat;
use App\Queries\SharedEntry;
use App\Services\ShareCardRenderer;
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
        $shape = ShareCardFormat::tryFrom($format);
        $entry = $shape === null ? null : $shared->find($token);

        if ($shape === null || $entry === null) {
            throw new NotFoundHttpException;
        }

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
