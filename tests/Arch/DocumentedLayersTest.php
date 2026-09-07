<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The three inventories in CLAUDE.md are lists, not examples.
 *
 * They are the map anybody reads before touching this app, and a class that
 * lands without being added to one is invisible from it. `AttachPhotoToMark`
 * shipped with the gentler grace reminders and was never listed, so the Action
 * behind "Agregar foto" — the second half of every ghost mark — did not exist
 * as far as the map was concerned.
 *
 * `app/ValueObjects/` is deliberately not policed here: that section calls out
 * `UserClock` as the one that matters and never claims to name the rest.
 */
it('names every class of a listed layer somewhere in CLAUDE.md', function (string $layer): void {
    $map = File::get(base_path('CLAUDE.md'));

    $unlisted = collect(File::files(app_path($layer)))
        ->map(fn (SplFileInfo $file): string => $file->getBasename('.php'))
        ->reject(fn (string $class): bool => Str::contains($map, "`{$class}`"))
        ->values()
        ->all();

    expect($unlisted)->toBe([]);
})->with(['Actions', 'Queries', 'Services']);
