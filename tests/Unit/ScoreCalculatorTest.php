<?php

declare(strict_types=1);

use App\Services\ScoreCalculator;
use App\ValueObjects\Standing;
use Illuminate\Support\Collection;

function rankableStanding(string $name, int $fullMarks, int $ghostMarks, int $possibleMarks, int $rank = 0): Standing
{
    return new Standing(
        userId: mb_strtolower($name),
        name: $name,
        fullMarks: $fullMarks,
        ghostMarks: $ghostMarks,
        possibleMarks: $possibleMarks,
        rank: $rank,
    );
}

it('shares a rank on a tie and skips the one after it', function (): void {
    $ranked = new ScoreCalculator()->rank([
        rankableStanding('Ana', 10, 0, 20),
        rankableStanding('Bruno', 5, 10, 20),
        rankableStanding('Diego', 4, 0, 20),
    ]);

    expect($ranked->pluck('name')->all())->toBe(['Ana', 'Bruno', 'Diego'])
        ->and($ranked->pluck('rank')->all())->toBe([1, 1, 3]);
});

it('shares a rank across a three-way tie', function (): void {
    $ranked = new ScoreCalculator()->rank([
        rankableStanding('Ana', 8, 4, 20),
        rankableStanding('Bruno', 0, 20, 20),
        rankableStanding('Carla', 10, 0, 20),
        rankableStanding('Diego', 2, 0, 20),
    ]);

    expect($ranked->pluck('name')->all())->toBe(['Ana', 'Bruno', 'Carla', 'Diego'])
        ->and($ranked->pluck('rank')->all())->toBe([1, 1, 1, 4]);
});

it('ignores the ranks it was handed', function (): void {
    $ranked = new ScoreCalculator()->rank([
        rankableStanding('Bruno', 20, 0, 20, rank: 7),
        rankableStanding('Ana', 10, 0, 20, rank: 99),
    ]);

    expect($ranked->pluck('name')->all())->toBe(['Bruno', 'Ana'])
        ->and($ranked->pluck('rank')->all())->toBe([1, 2]);
});

it('keeps everything but the rank untouched', function (): void {
    $ranked = new ScoreCalculator()->rank([rankableStanding('Ana', 7, 3, 20)]);

    expect($ranked->first()?->toArray())->toBe([
        'user_id' => 'ana',
        'name' => 'Ana',
        'full_marks' => 7,
        'ghost_marks' => 3,
        'possible_marks' => 20,
        'rank' => 1,
    ]);
});

it('ranks members who scored nothing at all', function (): void {
    $ranked = new ScoreCalculator()->rank([
        rankableStanding('Ana', 0, 0, 20),
        rankableStanding('Bruno', 0, 0, 0),
    ]);

    expect($ranked->pluck('rank')->all())->toBe([1, 1]);
});

it('returns an empty table for no members', function (): void {
    $ranked = new ScoreCalculator()->rank([]);

    expect($ranked)->toBeInstanceOf(Collection::class)
        ->and($ranked->all())->toBe([]);
});

/*
|---------------------------------------------------------------------------
| Ordering
|---------------------------------------------------------------------------
|
| This banner used to say the three tests below were known-broken, from when
| rank() sorted with an array of one-argument closures that Collection read as
| two-argument comparators. It sorts with an explicit comparator now and these
| pass; the note outlived the bug and told every reader that green was a lie.
|
*/

it('orders the table by score, highest first', function (): void {
    $ranked = new ScoreCalculator()->rank([
        rankableStanding('Diego', 4, 0, 20),
        rankableStanding('Carla', 15, 0, 20),
        rankableStanding('Ana', 10, 0, 20),
    ]);

    expect($ranked->pluck('name')->all())->toBe(['Carla', 'Ana', 'Diego'])
        ->and($ranked->pluck('rank')->all())->toBe([1, 2, 3]);
});

it('compares members on their own possible marks', function (): void {
    // Two goals done every day beats five goals done half the time.
    $ranked = new ScoreCalculator()->rank([
        rankableStanding('Bruno', 25, 0, 50),
        rankableStanding('Ana', 20, 0, 20),
    ]);

    expect($ranked->pluck('name')->all())->toBe(['Ana', 'Bruno']);
});

it('orders a tie by name whatever order it arrives in', function (): void {
    $tied = [
        rankableStanding('Zoe', 10, 0, 20),
        rankableStanding('Ana', 10, 0, 20),
        rankableStanding('Mateo', 10, 0, 20),
    ];

    expect(new ScoreCalculator()->rank($tied)->pluck('name')->all())
        ->toBe(['Ana', 'Mateo', 'Zoe']);
});
