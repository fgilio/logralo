<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Mark;
use App\Models\MonthlyRecap;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     *
     * These are substrings, so `bot` already covers telegrambot, twitterbot,
     * discordbot, slackbot and every one after them. Only the crawlers that
     * do not say "bot" need naming.
     */
    private const array CRAWLERS = [
        'bot', 'crawler', 'spider', 'preview',
        'facebookexternalhit', 'whatsapp', 'embedly',
    ];

    public function handle(Mark|MonthlyRecap $shared, ?string $userAgent): void
    {
        Context::add('logralo.share_token', $shared->share_token);
        Context::add('logralo.shared_type', class_basename($shared));

        try {
            if ($this->isCrawler($userAgent)) {
                Context::add('logralo.outcome', 'crawler');

                return;
            }

            // Nor is the sender. The count exists to tell them the message
            // landed somewhere, and checking their own link is not that.
            if ($shared instanceof Mark && $shared->user_id === Auth::id()) {
                Context::add('logralo.outcome', 'author');

                return;
            }

            // Incremented in the database rather than from the value this
            // request happened to load: a forwarded link arrives as a burst,
            // and read-add-write drops every visit but the last.
            //
            // No updated_at either — a visit is not a change to the mark, and
            // touching the timestamp would reorder the feed every time somebody
            // opened an old link.
            $shared::withoutTimestamps(
                fn () => $shared->increment('share_views', 1, ['share_last_viewed_at' => now()])
            );

            Context::add('logralo.outcome', 'counted');
            Context::add('logralo.share_views', $shared->share_views);
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
        // A missing User-Agent is a bot too: every browser sends one.
        return $userAgent === null
            || $userAgent === ''
            || Str::contains($userAgent, self::CRAWLERS, ignoreCase: true);
    }
}
