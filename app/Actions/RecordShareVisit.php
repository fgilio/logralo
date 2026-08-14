<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Somebody opened a shared link.
 *
 * The count goes back on the card in the feed, so the member who shared can
 * see that it landed. That is the only half of the loop the app can close on
 * its own: WhatsApp will never tell us a message was read, but it will send
 * us everyone who tapped.
 *
 * Crawlers are not visitors. WhatsApp fetches a link once to build its
 * preview, and counting that would report a view for every message sent
 * whether or not a single person opened it.
 */
final class RecordShareVisit
{
    /**
     * Substrings of the User-Agent that mean a preview, not a person. Kept
     * broad on purpose — over-counting a person as a bot loses a number
     * nobody depends on, while under-counting makes the feature lie.
     */
    private const array CRAWLERS = [
        'facebookexternalhit', 'whatsapp', 'telegrambot', 'twitterbot',
        'discordbot', 'slackbot', 'linkedinbot', 'embedly', 'bot', 'crawler',
        'preview', 'spider',
    ];

    public function handle(Mark|MonthlyRecap $shared, ?string $userAgent): bool
    {
        Context::add('logralo.share_token', $shared->share_token);
        Context::add('logralo.shared_type', class_basename($shared));

        try {
            if ($this->isCrawler($userAgent)) {
                Context::add('logralo.outcome', 'crawler');

                return false;
            }

            // No model events and no updated_at: a visit is not a change to
            // the mark, and touching the timestamp would reorder the feed
            // every time somebody opened an old link.
            $shared::withoutTimestamps(fn () => $shared->forceFill([
                'share_views' => $shared->share_views + 1,
                'share_last_viewed_at' => now(),
            ])->saveQuietly());

            Context::add('logralo.outcome', 'counted');
            Context::add('logralo.share_views', $shared->share_views);

            return true;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('share.visit.handled');
        }
    }

    private function isCrawler(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return true;
        }

        return collect(self::CRAWLERS)->contains(
            fn (string $needle): bool => str_contains(mb_strtolower($userAgent), $needle)
        );
    }
}
