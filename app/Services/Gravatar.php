<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Uri;

/**
 * The picture WordPress already has for an email address.
 *
 * Everybody in this group has been leaving comments on somebody's blog since
 * 2009, so most of them have a face on Gravatar already — which is a set of
 * profile pictures nobody has to be asked to upload.
 *
 * Nothing here talks to Gravatar. The URL is a hash of the email and the
 * browser is what fetches it, which keeps a render off the network and keeps
 * the address itself out of the page. `d=404` is the load-bearing parameter:
 * an email with no Gravatar answers 404 rather than with a silhouette, and
 * that is the failure the template needs in order to fall back to the member's
 * initials instead of showing a stranger's outline.
 */
final readonly class Gravatar
{
    /**
     * Not a config knob: Gravatar is one service at one address, and nobody
     * self-hosts it. A setting here would only be a way to typo it.
     */
    private const string HOST = 'https://gravatar.com';

    /**
     * Null when Gravatar is switched off, so a caller can pass the answer
     * straight to a template without asking twice.
     */
    public function url(string $email): ?string
    {
        if (! config()->boolean('logralo.avatars.gravatar')) {
            return null;
        }

        // SHA-256 of the trimmed, lowercased address — the modern spelling of
        // the hash. Gravatar still answers to MD5, but `arch()->preset()
        // ->security()` bans it outright and it has nothing to offer here.
        $hash = hash('sha256', Str::of($email)->trim()->lower()->toString());

        return Uri::of(self::HOST)
            ->withPath("/avatar/{$hash}")
            ->withQuery(['s' => config()->integer('logralo.avatars.size'), 'd' => '404'])
            ->value();
    }
}
