<?php

declare(strict_types=1);

use App\ValueObjects\FeedEntry;

it('keeps every value object final', function (): void {
    expect('App\ValueObjects')->classes()->toBeFinal()->ignoring(FeedEntry::class);
});

it('keeps every value object readonly', function (): void {
    // A value object that can be mutated after construction is just an array
    // with extra steps. FeedEntry is the contract MarkEntry and RecapEntry
    // implement, not a value itself.
    expect('App\ValueObjects')->classes()->toBeReadonly()->ignoring(FeedEntry::class);
});
