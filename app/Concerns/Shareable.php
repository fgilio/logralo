<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\ShareCardFormat;
use Illuminate\Support\Str;

/**
 * Anything the feed can send to WhatsApp: a mark, or a month-end recap.
 *
 * The token is minted when the row is created, not when somebody first taps
 * share. That is not eagerness for its own sake — `navigator.share()` has to
 * be called inside the gesture that opened it, and on iOS an `await` in
 * between spends the transient activation and the sheet never appears. A token
 * that already exists at render time is a URL the template can print, so the
 * tap has nothing to wait for.
 *
 * A token is unguessable, and that is the whole access control: the group is
 * private, but a WhatsApp preview is fetched by an anonymous crawler, so
 * anything it can draw has to be reachable without a session. Revoking clears
 * the token and drops the rendered cards; sharing again mints a new one, which
 * is also how a link already sent is killed.
 */
trait Shareable
{
    /** 24 alphanumeric characters, about 143 bits. */
    public const int SHARE_TOKEN_LENGTH = 24;

    public static function bootShareable(): void
    {
        // Typed `self` rather than `Model`: inside a trait that resolves to the
        // using class, which is what carries the `share_token` annotation.
        static::creating(function (self $model): void {
            $model->share_token ??= self::freshShareToken();
        });
    }

    public static function freshShareToken(): string
    {
        return Str::random(self::SHARE_TOKEN_LENGTH);
    }

    public function isShareable(): bool
    {
        return $this->share_token !== null;
    }

    public function shareUrl(): ?string
    {
        return $this->isShareable() ? route('share.show', $this->share_token) : null;
    }

    public function shareCardUrl(ShareCardFormat $format = ShareCardFormat::Unfurl): ?string
    {
        return $this->isShareable()
            ? route('share.card', ['token' => $this->share_token, 'format' => $format->value])
            : null;
    }

    /** Where the rendered cards for this row live on the photo disk. */
    public function shareCardDirectory(): string
    {
        return 'shares/'.$this->share_token;
    }
}
