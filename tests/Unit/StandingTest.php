<?php

declare(strict_types=1);

use App\ValueObjects\Standing;

function standingWithMarks(int $fullMarks, int $ghostMarks, int $possibleMarks): Standing
{
    return new Standing(
        userId: '01J0MEMBER',
        name: 'Ana',
        fullMarks: $fullMarks,
        ghostMarks: $ghostMarks,
        possibleMarks: $possibleMarks,
        rank: 1,
    );
}

it('counts a full mark whole and a ghost mark half', function (): void {
    expect(standingWithMarks(7, 3, 20)->points())->toBe(8.5)
        ->and(standingWithMarks(0, 0, 20)->points())->toBe(0.0)
        ->and(standingWithMarks(0, 1, 20)->points())->toBe(0.5);
});

it('scores the points against the possible marks', function (): void {
    expect(standingWithMarks(7, 3, 20)->score())->toBe(0.425)
        ->and(standingWithMarks(20, 0, 20)->score())->toBe(1.0)
        ->and(standingWithMarks(0, 0, 20)->score())->toBe(0.0);
});

it('shows the score as a percentage with one decimal', function (): void {
    expect(standingWithMarks(7, 3, 20)->percentage())->toBe(42.5)
        ->and(standingWithMarks(1, 0, 3)->percentage())->toBe(33.3)
        ->and(standingWithMarks(2, 0, 3)->percentage())->toBe(66.7)
        ->and(standingWithMarks(20, 0, 20)->percentage())->toBe(100.0);
});

it('splits the bar into a solid and a hatched segment', function (): void {
    $standing = standingWithMarks(7, 3, 20);

    expect($standing->fullPercentage())->toBe(35.0)
        ->and($standing->ghostPercentage())->toBe(7.5)
        ->and($standing->fullPercentage() + $standing->ghostPercentage())->toBe($standing->percentage());
});

it('draws no bar for a member with nothing marked', function (): void {
    $standing = standingWithMarks(0, 0, 20);

    expect($standing->fullPercentage())->toBe(0.0)
        ->and($standing->ghostPercentage())->toBe(0.0);
});

it('scores zero when no marks were possible', function (): void {
    $standing = standingWithMarks(0, 0, 0);

    expect($standing->points())->toBe(0.0)
        ->and($standing->score())->toBe(0.0)
        ->and($standing->percentage())->toBe(0.0)
        ->and($standing->fullPercentage())->toBe(0.0)
        ->and($standing->ghostPercentage())->toBe(0.0);
});

it('never lets a marked-more-than-possible member overflow a segment', function (): void {
    // A member who changed goals mid-month can out-mark the denominator.
    expect(standingWithMarks(30, 0, 20)->fullPercentage())->toBe(100.0)
        ->and(standingWithMarks(0, 60, 20)->ghostPercentage())->toBe(100.0)
        ->and(standingWithMarks(25, 25, 20)->fullPercentage())->toBe(100.0)
        ->and(standingWithMarks(25, 25, 20)->ghostPercentage())->toBe(62.5)
        ->and(standingWithMarks(25, 25, 20)->percentage())->toBe(187.5);
});

it('leaves the raw score uncapped so ordering stays honest', function (): void {
    expect(standingWithMarks(30, 0, 20)->score())->toBe(1.5);
});

it('rebuilds itself from an array', function (): void {
    $standing = Standing::fromArray([
        'user_id' => '01J0MEMBER',
        'name' => 'Ana',
        'full_marks' => '7',
        'ghost_marks' => '3',
        'possible_marks' => '20',
        'rank' => '2',
    ]);

    expect($standing->fullMarks)->toBe(7)
        ->and($standing->ghostMarks)->toBe(3)
        ->and($standing->possibleMarks)->toBe(20)
        ->and($standing->rank)->toBe(2)
        ->and($standing->toArray())->toBe([
            'user_id' => '01J0MEMBER',
            'name' => 'Ana',
            'full_marks' => 7,
            'ghost_marks' => 3,
            'possible_marks' => 20,
            'rank' => 2,
        ]);
});

it('copies itself onto a new rank', function (): void {
    $standing = standingWithMarks(7, 3, 20)->withRank(4);

    expect($standing->rank)->toBe(4)
        ->and($standing->fullMarks)->toBe(7)
        ->and($standing->ghostMarks)->toBe(3)
        ->and($standing->possibleMarks)->toBe(20)
        ->and($standing->name)->toBe('Ana');
});
