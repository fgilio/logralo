<?php

declare(strict_types=1);

use App\Actions\AddComment;
use App\Exceptions\EmptyCommentException;
use App\Models\Comment;
use App\Models\Mark;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * A line under somebody's photo. The field in front of this validates too, but
 * the Action is what always runs, so every rule that can refuse a comment is
 * checked here.
 */
it('stores a comment against the mark and its author', function (): void {
    $mark = Mark::factory()->create();
    $author = User::factory()->create();

    $comment = resolve(AddComment::class)->handle($mark, $author, 'qué viento había');

    expect($comment)->toBeInstanceOf(Comment::class)
        ->and($comment->mark_id)->toBe($mark->id)
        ->and($comment->user_id)->toBe($author->id)
        ->and($comment->body)->toBe('qué viento había')
        ->and($mark->comments()->count())->toBe(1);
});

it('hands the author back on the comment it returns', function (): void {
    // The thread draws a name and a face on every line, and re-reading a row
    // the caller is already holding is a query per comment posted.
    $mark = Mark::factory()->create();
    $author = User::factory()->create(['name' => 'Ana']);

    $comment = resolve(AddComment::class)->handle($mark, $author, 'vamos');

    expect($comment->relationLoaded('user'))->toBeTrue()
        ->and($comment->user->name)->toBe('Ana');
});

it('squishes the whitespace somebody pasted in', function (): void {
    $mark = Mark::factory()->create();

    $comment = resolve(AddComment::class)->handle($mark, User::factory()->create(), "  qué\n\n  viento  había  ");

    expect($comment->body)->toBe('qué viento había');
});

it('refuses a comment with nothing left in it', function (): void {
    $mark = Mark::factory()->create();

    expect(fn (): Comment => resolve(AddComment::class)->handle($mark, User::factory()->create(), "   \n  "))
        ->toThrow(EmptyCommentException::class);

    expect(Comment::query()->count())->toBe(0);
});

it('cuts a comment at the configured length', function (): void {
    // The column is the same length, so a comment past the cap would be a
    // database error rather than a trimmed line.
    $max = config()->integer('logralo.comments.max_length');

    $comment = resolve(AddComment::class)->handle(
        Mark::factory()->create(),
        User::factory()->create(),
        Str::repeat('a', $max + 50),
    );

    expect(Str::length($comment->body))->toBe($max);
});

it('lets several members pile onto the same photo', function (): void {
    $mark = Mark::factory()->create();

    resolve(AddComment::class)->handle($mark, User::factory()->create(), 'uno');
    resolve(AddComment::class)->handle($mark, User::factory()->create(), 'dos');
    resolve(AddComment::class)->handle($mark, User::factory()->create(), 'tres');

    expect($mark->comments()->count())->toBe(3);
});

it('takes the thread with the mark when a mark is undone', function (): void {
    // Unmarking deletes the row, and a comment on a mark nobody made is an
    // orphan the feed would never show and nothing would ever clean up.
    $mark = Mark::factory()->create();

    resolve(AddComment::class)->handle($mark, User::factory()->create(), 'me equivoqué de día');

    $mark->delete();

    expect(Comment::query()->count())->toBe(0);
});
