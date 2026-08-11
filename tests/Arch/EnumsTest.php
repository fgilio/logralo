<?php

declare(strict_types=1);

it('backs every enum with a string', function (): void {
    // Enum values land in database columns and in log context, so they have to
    // be readable there — 'ghost', not 1.
    expect('App\Enums')->toBeStringBackedEnums();
});
