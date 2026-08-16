<?php

declare(strict_types=1);

use App\ValueObjects\MarkHistory;

/**
 * A week of marks on one goal: full, full, ghost, ghost, full, ghost, ghost.
 */
function weekOfMarks(): MarkHistory
{
    return new MarkHistory([
        ['date' => '2026-08-01', 'full' => true],
        ['date' => '2026-08-02', 'full' => true],
        ['date' => '2026-08-03', 'full' => false],
        ['date' => '2026-08-04', 'full' => false],
        ['date' => '2026-08-05', 'full' => true],
        ['date' => '2026-08-06', 'full' => false],
        ['date' => '2026-08-07', 'full' => false],
    ]);
}

it('lists the marked dates in the order it was given', function (): void {
    expect(weekOfMarks()->dates())->toBe([
        '2026-08-01',
        '2026-08-02',
        '2026-08-03',
        '2026-08-04',
        '2026-08-05',
        '2026-08-06',
        '2026-08-07',
    ]);
});

it('lists no dates for an empty history', function (): void {
    expect(MarkHistory::empty()->entries)->toBe([])
        ->and(MarkHistory::empty()->dates())->toBe([]);
});

it('counts the ghost run ending on a day, that day included', function (): void {
    $history = weekOfMarks();

    expect($history->ghostRunEndingOn('2026-08-03'))->toBe(1)
        ->and($history->ghostRunEndingOn('2026-08-04'))->toBe(2)
        ->and($history->ghostRunEndingOn('2026-08-06'))->toBe(1)
        ->and($history->ghostRunEndingOn('2026-08-07'))->toBe(2);
});

it('reads no ghost run on a day that carried a photo', function (): void {
    expect(weekOfMarks()->ghostRunEndingOn('2026-08-05'))->toBe(0)
        ->and(weekOfMarks()->ghostRunEndingOn('2026-08-01'))->toBe(0);
});

it('ignores marks made after the day it counts back from', function (): void {
    $history = new MarkHistory([
        ['date' => '2026-08-01', 'full' => true],
        ['date' => '2026-08-03', 'full' => false],
        ['date' => '2026-08-05', 'full' => false],
    ]);

    expect($history->ghostRunEndingOn('2026-08-04'))->toBe(1)
        ->and($history->ghostRunEndingOn('2026-08-05'))->toBe(2);
});

it('counts a history made only of ghost marks', function (): void {
    $history = new MarkHistory([
        ['date' => '2026-08-01', 'full' => false],
        ['date' => '2026-08-02', 'full' => false],
        ['date' => '2026-08-03', 'full' => false],
    ]);

    expect($history->ghostRunEndingOn('2026-08-03'))->toBe(3)
        ->and(MarkHistory::empty()->ghostRunEndingOn('2026-08-03'))->toBe(0)
        ->and($history->ghostRunEndingOn('2026-07-31'))->toBe(0);
});

it('reports the marks before a day newest first', function (): void {
    expect(weekOfMarks()->recentFullnessBefore('2026-08-06'))->toBe([true, false, false, true, true]);
});

it('leaves out the day itself and everything after it', function (): void {
    $history = weekOfMarks();

    expect($history->recentFullnessBefore('2026-08-03'))->toBe([true, true])
        ->and($history->recentFullnessBefore('2026-08-01'))->toBe([]);
});

it('reports nothing recent for an empty history', function (): void {
    expect(MarkHistory::empty()->recentFullnessBefore('2026-08-08'))->toBe([]);
});
