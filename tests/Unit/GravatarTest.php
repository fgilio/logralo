<?php

declare(strict_types=1);

use App\Services\Gravatar;

/*
 * The URL the browser is handed for a member with no uploaded picture. Nothing
 * here is allowed to reach Gravatar — the suite forbids stray requests, and so
 * does every render.
 */

// `phpunit.xml` switches Gravatar off for the suite, so that no test drags a
// real Chromium onto gravatar.com. This file is the one that is about it.
beforeEach(fn () => config()->set('logralo.avatars.gravatar', true));

it('hashes the address the way Gravatar reads it', function (): void {
    // Trimmed and lowercased before hashing, so the same person typed three
    // ways is one picture rather than three misses.
    $canonical = hash('sha256', 'franco@example.com');

    expect(resolve(Gravatar::class)->url('  Franco@Example.COM  '))
        ->toContain($canonical);
});

it('asks for the one size every avatar on screen is drawn at', function (): void {
    config()->set('logralo.avatars.size', 192);

    expect(resolve(Gravatar::class)->url('franco@example.com'))->toContain('s=192');
});

it('asks for a 404 rather than a silhouette', function (): void {
    // The whole fallback hangs off this: a stranger's outline would look like
    // a member with a picture, and the initials would never show.
    expect(resolve(Gravatar::class)->url('franco@example.com'))->toContain('d=404');
});

it('says nothing at all when Gravatar is switched off', function (): void {
    config()->set('logralo.avatars.gravatar', false);

    expect(resolve(Gravatar::class)->enabled())->toBeFalse()
        ->and(resolve(Gravatar::class)->url('franco@example.com'))->toBeNull();
});

it('takes the host from config without doubling the slash', function (): void {
    config()->set('logralo.avatars.gravatar_host', 'https://gravatar.example/');

    expect(resolve(Gravatar::class)->url('franco@example.com'))
        ->toStartWith('https://gravatar.example/avatar/');
});
