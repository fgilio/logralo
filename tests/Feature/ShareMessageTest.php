<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use App\ValueObjects\MarkEntry;

/**
 * The line typed into the chat above the link, and the words on the card
 * under it.
 *
 * "Guido marcó Gimnasio" was a caption written by software, sent by Guido,
 * over a card that already said Gimnasio. What the member typed when they
 * marked it is the one part of a share nobody else could have written, so that
 * is the part that gets sent.
 */
function markEntryWith(?string $note, int $streak = 1): MarkEntry
{
    $user = User::factory()->create(['name' => 'Guido']);
    $goal = Goal::factory()->for($user)->create(['name' => 'Gimnasio']);

    $mark = Mark::factory()->for($goal)->create([
        'user_id' => $user->id,
        'marked_on' => now()->toDateString(),
        'note' => $note,
    ]);

    return new MarkEntry(mark: $mark, streak: $streak, ghostRun: 0, photo: null);
}

it('sends what the member wrote', function (): void {
    expect(markEntryWith('6am y llovía')->shareText())->toBe('6am y llovía');
});

it('falls back to the goal when nothing was written', function (): void {
    // First person and no name: it is sent by the person who earned it, into a
    // chat of people who know them.
    expect(markEntryWith(null)->shareText())->toBe('Marqué Gimnasio');
});

it('treats a note of nothing but spaces as no note', function (): void {
    expect(markEntryWith('   ')->shareText())->toBe('Marqué Gimnasio');
});

it('keeps the streak out of the message and on the card', function (): void {
    $entry = markEntryWith(null, streak: 12);

    expect($entry->shareText())->toBe('Marqué Gimnasio')
        ->and($entry->shareCard()->highlight)->toBe('12 días seguidos');
});

it('draws the day and the goal, and no name at all', function (): void {
    $entry = markEntryWith('lluvia');
    $card = $entry->shareCard();

    // The badge is gone with the name: the streak it used to carry now sits
    // beside the date, and a badge saying the same thing twice reads as a bug.
    expect($card->title)->toBe('Gimnasio')
        ->and($card->badge)->toBeNull()
        ->and($card->highlight)->toBeNull()
        ->and($card->byline)->toBe($entry->mark->marked_on->translatedFormat('j \d\e F'))
        ->and($card->byline)->not->toContain('Guido');
});
