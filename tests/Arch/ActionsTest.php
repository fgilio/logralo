<?php

declare(strict_types=1);

it('keeps every action final', function (): void {
    expect('App\Actions')->toBeFinal();
});

it('gives every action a handle method', function (): void {
    expect('App\Actions')->toHaveMethod('handle');
});
