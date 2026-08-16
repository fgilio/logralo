<?php

declare(strict_types=1);

namespace App\ValueObjects;

use Illuminate\Support\Collection;

/**
 * One rendering of the feed: the entries loaded so far, newest first, and
 * whether scrolling further will find anything.
 */
final readonly class FeedResult
{
    /** @param  Collection<int, FeedEntry>  $entries */
    public function __construct(
        public Collection $entries,
        public bool $hasMore,
    ) {}
}
