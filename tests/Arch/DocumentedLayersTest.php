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
it('lists every class of a documented layer on the list that layer keeps', function (string $layer): void {
    // The section that documents this layer rather than the whole file:
    // `ShareCardRenderer` is named under Observability as well, and a name that
    // survives over there is not a name still on the list.
    $section = Str::of(File::get(base_path('CLAUDE.md')))
        ->after("### `app/{$layer}/`")
        ->before("\n### ");

    $unlisted = collect(File::files(app_path($layer)))
        ->map(fn (SplFileInfo $file): string => $file->getBasename('.php'))
        // An item of a list, not a word in a sentence. The prose under each
        // list names classes too — `MarkGoal` explained two bullets below the
        // list is not `MarkGoal` on it — so a name only counts where a list
        // puts one: after the bullet, after a comma, or after the slash that
        // pairs the share card's two halves.
        ->reject(fn (string $class): bool => $section
            ->match('/(?:^- |, | \/ )`'.preg_quote($class, '/').'`/m')
            ->isNotEmpty())
        ->values()
        ->all();

    expect($unlisted)->toBe([]);
})->with(['Actions', 'Queries', 'Services']);
