<?php

declare(strict_types=1);

use App\Services\StreakMilestone;

/**
 * Which streaks earn an interruption. The rule has to be rare: a celebration
 * on every mark is a dialog to dismiss, and nobody shares out of a dialog they
 * are trying to close.
 */
it('celebrates the round numbers and nothing between them', function (int $streak, bool $expected): void {
    expect(resolve(StreakMilestone::class)->isMilestone($streak))->toBe($expected);
})->with([
    'day one is just a mark' => [1, false],
    'the first real run' => [3, true],
    'four is nothing' => [4, false],
    'a week' => [7, true],
    'a fortnight' => [14, true],
    'three weeks' => [21, true],
    'a month' => [30, true],
    'thirty one carries on quietly' => [31, false],
    'fifty' => [50, true],
    'seventy five' => [75, true],
    'a hundred' => [100, true],
]);

it('keeps celebrating every fifty days past the last threshold', function (): void {
    $milestones = resolve(StreakMilestone::class);

    expect($milestones->isMilestone(150))->toBeTrue()
        ->and($milestones->isMilestone(200))->toBeTrue()
        ->and($milestones->isMilestone(175))->toBeFalse();
});

it('never celebrates a streak that does not exist', function (): void {
    $milestones = resolve(StreakMilestone::class);

    expect($milestones->isMilestone(0))->toBeFalse()
        ->and($milestones->isMilestone(-3))->toBeFalse();
});

it('names the ones worth naming', function (): void {
    $milestones = resolve(StreakMilestone::class);

    expect($milestones->headline(7))->toBe('Una semana entera')
        ->and($milestones->headline(30))->toBe('Un mes entero')
        ->and($milestones->headline(100))->toBe('Cien días')
        ->and($milestones->headline(3))->toBe('3 días seguidos');
});
