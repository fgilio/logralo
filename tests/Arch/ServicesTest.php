<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

it('keeps the log out of both read layers', function (): void {
    // A read runs on every render and on every anonymous crawler fetch, so a
    // line here does not add signal, it buries the events that carry an
    // outcome. The rule is written down in CLAUDE.md and was the one thing in
    // that paragraph nothing enforced — which is why review bots keep asking
    // for a log in SharedEntry and ShareCardRenderer, and why the answer has
    // to be a failing test rather than an argument.
    expect(['App\Queries', 'App\Services'])->not->toUse([Log::class]);
});
