<?php

declare(strict_types=1);

use App\Models\MonthlyRecap;
use App\Models\User;
use App\ValueObjects\RecapEntry;
use Illuminate\Support\Str;

/**
 * The words on the month-end card, and on the page a tap behind it.
 *
 * The recap is the one card that counts somebody else's days, so its best
 * streak line is built here rather than in the two templates that show it —
 * they used to disagree about it.
 */
function recapEntryWith(int $bestStreak, ?User $owner = null): RecapEntry
{
    $recap = MonthlyRecap::factory()->create([
        'best_streak_days' => $bestStreak,
        'best_streak_user_id' => $owner?->id,
    ]);

    return new RecapEntry($recap->load('bestStreakUser'));
}

/**
 * A closed month whose podium is just names and ranks — the two columns the
 * runner-up line reads. Everything else on a Standing is the score behind the
 * rank, which is already frozen by the time a recap exists.
 *
 * @param  array<int, array{0: string, 1: int}>  $podium  name and rank
 */
function recapEntryRanking(array $podium): RecapEntry
{
    $recap = MonthlyRecap::factory()->create([
        'standings' => collect($podium)
            ->map(fn (array $line): array => [
                'user_id' => (string) Str::ulid(),
                'name' => $line[0],
                'full_marks' => 0,
                'ghost_marks' => 0,
                'possible_marks' => 0,
                'rank' => $line[1],
            ])
            ->all(),
    ]);

    return new RecapEntry($recap);
}

it('counts a one-day best streak in the singular', function (): void {
    // "Mejor racha · 1 días" is the same shape as the "7 7 vezs" that reached
    // the group chat: Spanish inflected by a rule written for English, or in
    // this case by no rule at all.
    $guido = User::factory()->create(['name' => 'Guido']);

    expect(recapEntryWith(1, $guido)->bestStreakLabel())->toBe('1 día · Guido');
});

it('counts anything longer in the plural', function (): void {
    $guido = User::factory()->create(['name' => 'Guido']);

    expect(recapEntryWith(23, $guido)->bestStreakLabel())->toBe('23 días · Guido');
});

it('says nothing about a month nobody strung two days together in', function (): void {
    expect(recapEntryWith(0)->bestStreakLabel())->toBeNull();
});

it('puts the same line on the card as on the page', function (): void {
    // The card is composed from this and the page reads it directly, so the
    // one thing worth pinning is that both come from here.
    $entry = recapEntryWith(1, User::factory()->create(['name' => 'Guido']));

    expect($entry->shareCard()->stats)->toHaveKey('Mejor racha')
        ->and($entry->shareCard()->stats['Mejor racha'])->toBe($entry->bestStreakLabel());
});

it('keeps the best streak off a month that has none', function (): void {
    expect(recapEntryWith(0)->shareCard()->stats)->not->toHaveKey('Mejor racha');
});

it('calls one runner-up an escolta', function (): void {
    $entry = recapEntryRanking([['Ana', 1], ['Guido', 2]]);

    expect($entry->runnerUpLabel())->toBe('Escolta')
        ->and($entry->runnerUpNames())->toBe('Guido');
});

it('calls a tied second place escoltas', function (): void {
    // The podium is shared, so rank 2 is a step rather than a seat: "Escolta ·
    // Franco y Guido" is the recap counting two people in the singular.
    $entry = recapEntryRanking([['Ana', 1], ['Franco', 2], ['Guido', 2]]);

    expect($entry->runnerUpLabel())->toBe('Escoltas')
        ->and($entry->runnerUpNames())->toBe('Franco y Guido');
});

it('keeps the singular for a month nobody came second in', function (): void {
    $entry = recapEntryRanking([['Ana', 1]]);

    expect($entry->runnerUpLabel())->toBe('Escolta')
        ->and($entry->runnerUpNames())->toBe('');
});
