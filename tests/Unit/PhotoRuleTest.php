<?php

declare(strict_types=1);

use App\Services\PhotoRule;

dataset('recent marks', [
    'no marks at all' => [[], false],
    'one full mark' => [[true], false],
    'one ghost mark' => [[false], false],
    'two full marks' => [[true, true], false],
    'a full mark over a ghost' => [[true, false], false],
    'a ghost over a full mark' => [[false, true], false],
    'two ghost marks in a row' => [[false, false], true],
    'three ghost marks in a row' => [[false, false, false], true],
    'a full mark resetting the run' => [[true, false, false], false],
    'two ghosts reached before an older full mark' => [[false, false, true], true],
]);

it('asks for a photo once the ghost run reaches the limit', function (array $recentMarksAreFull, bool $expected): void {
    expect(new PhotoRule()->requiresPhoto($recentMarksAreFull))->toBe($expected);
})->with('recent marks');

it('lets the newest mark decide, whatever came before it', function (): void {
    // The list is newest first, so a full mark at the head always clears it.
    $rule = new PhotoRule();

    expect($rule->requiresPhoto([true, false, false, false, false]))->toBeFalse()
        ->and($rule->requiresPhoto([false, false, false, false, true]))->toBeTrue();
});

it('counts marks and not days, so a skipped day launders nothing', function (): void {
    // Two ghost marks a week apart are still two ghost marks in a row.
    expect(new PhotoRule()->requiresPhoto([false, false]))->toBeTrue();
});

it('follows the configured ghost limit', function (): void {
    config()->set('logralo.goals.ghosts_before_camera', 3);

    $rule = new PhotoRule();

    expect($rule->requiresPhoto([false, false]))->toBeFalse()
        ->and($rule->requiresPhoto([false, false, false]))->toBeTrue();
});

it('asks for a photo on the very first mark when the limit is one', function (): void {
    config()->set('logralo.goals.ghosts_before_camera', 1);

    $rule = new PhotoRule();

    expect($rule->requiresPhoto([]))->toBeFalse()
        ->and($rule->requiresPhoto([false]))->toBeTrue()
        ->and($rule->requiresPhoto([true]))->toBeFalse();
});
