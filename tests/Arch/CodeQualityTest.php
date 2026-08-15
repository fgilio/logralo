<?php

declare(strict_types=1);

// Debug helpers, `dd`, `dump`, `var_dump`, `echo` and friends never reach a
// commit, and neither do the weak-crypto / arbitrary-execution functions.
// `hash('sha256', ...)` is not on the security list and is the one hash this
// app relies on, for magic-link tokens.
arch()->preset()->php();
arch()->preset()->security();

it('declares strict types in every first-party file', function (): void {
    expect(['App', 'Database\Factories', 'Database\Seeders'])->toUseStrictTypes();
});
