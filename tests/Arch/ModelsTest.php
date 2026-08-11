<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

it('keeps every model final', function (): void {
    expect('App\Models')->toBeFinal();
});

it('roots every model in Eloquent', function (): void {
    // User extends Authenticatable, which is itself a Model, and `toExtend`
    // walks the whole ancestry.
    expect('App\Models')->toExtend(Model::class);
});

it('keys every model by ULID', function (): void {
    expect('App\Models')->toUseTrait(HasUlids::class);
});

it('makes every model declare its own casts', function (): void {
    // `toHaveMethod` would pass on the inherited Model::casts(); a model that
    // forgot to declare its own has to fail here.
    expect('App\Models')->toDeclareMethod('casts');
});

it('keeps Eloquent inside App\Models', function (): void {
    expect('App')->classes()->not->toExtend(Model::class)->ignoring('App\Models');
});
