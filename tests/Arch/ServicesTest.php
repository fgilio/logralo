<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

it('keeps every service final', function (): void {
    expect('App\Services')->toBeFinal();
});

it('keeps services away from the database', function (): void {
    // Services are pure rules over values the caller already loaded: streaks,
    // scores, the photo rule, the photo pipeline. Reading the database from
    // one is how a rule quietly becomes an N+1.
    expect('App\Services')->not->toUse([
        Model::class,
        EloquentBuilder::class,
        QueryBuilder::class,
        DB::class,
    ]);
});

it('keeps every query final', function (): void {
    // App\Queries is the layer that IS allowed to query, so it gets no such
    // ban — only the same finality.
    expect('App\Queries')->toBeFinal();
});
