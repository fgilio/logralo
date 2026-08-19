<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Goal;
use App\Models\Mark;
use App\Models\User;
use Livewire\Livewire;

/**
 * The thread under the open photo: one component for the whole feed, filled
 * when a card hands it a mark. It shows the conversation whole — the box is
 * what decides how much of it is on screen — and nothing of it reaches the
 * feed.
 */
it('draws nothing until a photo is opened', function (): void {
    $member = User::factory()->create();

    Comment::factory()->create(['body' => 'qué viento había']);

    Livewire::actingAs($member)
        ->test('photo-comments')
        ->assertDontSee('qué viento había');
});

it('loads the thread of the photo that was opened', function (): void {
    $member = User::factory()->create();
    $mark = Mark::factory()->create();

    Comment::factory()->for($mark)->for(User::factory()->create(['name' => 'Ana']))->create(['body' => 'qué viento había']);
    Comment::factory()->for($mark)->for(User::factory()->create(['name' => 'Bruno']))->create(['body' => 'mañana voy con vos']);

    Livewire::actingAs($member)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->assertSee('qué viento había')
        ->assertSee('mañana voy con vos')
        ->assertSee('Ana')
        ->assertSee('Bruno');
});

it('shows the whole thread rather than a fixed handful', function (): void {
    // How much of it is on screen is the box's business. Cutting the list here
    // would put a "ver más" in front of a conversation between five friends.
    $member = User::factory()->create();
    $mark = Mark::factory()->create();

    foreach (range(1, 12) as $number) {
        Comment::factory()->for($mark)->create(['body' => "comentario numero {$number}"]);
    }

    $component = Livewire::actingAs($member)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id);

    foreach (range(1, 12) as $number) {
        $component->assertSee("comentario numero {$number}");
    }
});

it('puts the newest first, because the box is drawn bottom-up', function (): void {
    $member = User::factory()->create();
    $mark = Mark::factory()->create();

    $oldest = Comment::factory()->for($mark)->create(['body' => 'el primero']);
    $newest = Comment::factory()->for($mark)->create(['body' => 'el ultimo']);

    Livewire::actingAs($member)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->assertSeeInOrder(['el ultimo', 'el primero'])
        ->assertSeeHtml('comment-'.$newest->id)
        ->assertSeeHtml('comment-'.$oldest->id);
});

it('says so when nobody has commented yet', function (): void {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: Mark::factory()->create()->id)
        ->assertSee('Todavía nadie dijo nada.');
});

it('posts a comment and clears the field', function (): void {
    $member = User::factory()->create();
    $mark = Mark::factory()->create();

    Livewire::actingAs($member)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->set('body', 'grande')
        ->call('send')
        ->assertSet('body', '')
        ->assertSee('grande');

    expect($mark->comments()->count())->toBe(1)
        ->and($mark->comments()->first()?->user_id)->toBe($member->id);
});

it('refuses an empty comment without reaching the action', function (): void {
    $member = User::factory()->create();
    $mark = Mark::factory()->create();

    Livewire::actingAs($member)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->set('body', '   ')
        ->call('send')
        ->assertHasErrors(['body']);

    expect(Comment::query()->count())->toBe(0);
});

it('keeps a private goal thread away from everybody else', function (): void {
    // The mark id arrives from the browser, so the scope is what stands
    // between a lifted id and somebody else's private goal.
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $mark = Mark::factory()->for(Goal::factory()->for($owner)->private())->create();

    Comment::factory()->for($mark)->create(['body' => 'esto es privado']);

    Livewire::actingAs($stranger)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->assertDontSee('esto es privado');

    Livewire::actingAs($owner)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->assertSee('esto es privado');
});

it('refuses to write into a thread the member cannot see', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $mark = Mark::factory()->for(Goal::factory()->for($owner)->private())->create();

    Livewire::actingAs($stranger)
        ->test('photo-comments')
        ->dispatch('photo-comments-open', markId: $mark->id)
        ->set('body', 'me colé')
        ->call('send');

    expect(Comment::query()->count())->toBe(0);
});
