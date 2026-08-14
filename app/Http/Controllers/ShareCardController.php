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
 * chat client feels like it. A token URL never changes what it points at, so
 * the response is immutable and cached for a year.
 */
final class ShareCardController
{
    /** A year, which is what `immutable` is worth telling a cache. */
    private const int MAX_AGE = 31536000;

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
            'Cache-Control' => 'public, max-age='.self::MAX_AGE.', immutable',
        ]);
    }
}
