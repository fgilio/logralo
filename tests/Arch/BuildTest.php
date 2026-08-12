<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The asset build has to be reproducible from a clean checkout.
 *
 * `laravel-vite-plugin`'s `google()` and `bunny()` font providers download the
 * fonts while the build runs, and the URLs they resolve are versioned upstream.
 * One of those URLs went 404 mid-deploy and took the whole build with it, over
 * a change to `.env.example`. `fontsource()` reads the same fonts out of
 * node_modules, pinned by package-lock.json.
 */
it('resolves fonts without reaching the network during a build', function (): void {
    // The providers this reads are the ones imported, not the ones named in
    // the comment right above them in vite.config.js.
    $imported = Str::of(File::get(base_path('vite.config.js')))
        ->matchAll('/import\s*\{([^}]*)\}\s*from\s*"laravel-vite-plugin\/fonts"/')
        ->flatMap(fn (string $bindings): array => Str::of($bindings)->explode(',')->all())
        ->map(fn (string $binding): string => Str::squish($binding))
        ->filter()
        ->values();

    expect($imported->all())->toBe(['fontsource']);
});
