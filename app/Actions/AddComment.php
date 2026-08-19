<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\EmptyCommentException;
use App\Exceptions\UserFacingException;
use App\Models\Comment;
use App\Models\Mark;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A line under somebody's photo.
 *
 * No notification and no mention: this is five friends looking at the same
 * feed, and the comment is found the same way a reaction is — by opening the
 * photo. The unit of work is the row, so the cap and the emptiness check live
 * here rather than only in the field in front of it.
 */
final readonly class AddComment
{
    public function handle(Mark $mark, User $author, string $body): Comment
    {
        Context::add('logralo.user_id', $author->id);
        Context::add('logralo.mark_id', $mark->id);

        try {
            $clean = $this->clean($body);

            if ($clean === '') {
                throw EmptyCommentException::make();
            }

            $comment = $mark->comments()->create([
                'user_id' => $author->id,
                'body' => $clean,
            ]);

            // The thread draws the author's name and face on every line, and
            // `create()` on a relation does not set the inverse — without this
            // the render re-reads a row we are already holding.
            $comment->setRelation('user', $author);

            Context::add('logralo.comment_id', $comment->id);
            Context::add('logralo.outcome', 'completed');

            return $comment;
        } catch (UserFacingException $exception) {
            Context::add('logralo.outcome', 'rejected');
            Context::add('logralo.reject_reason', $exception->reason());

            throw $exception;
        } catch (Throwable $throwable) {
            Context::add('logralo.outcome', 'error');
            Context::add('logralo.error', $throwable->getMessage());
            Context::add('logralo.error_class', $throwable::class);

            throw $throwable;
        } finally {
            Log::info('comment.create.handled');
        }
    }

    /**
     * Squished rather than trimmed, the way a mark's note is: a comment is one
     * line drawn next to a face, and somebody's stray line breaks would push
     * the rest of the thread off the screen.
     */
    private function clean(string $body): string
    {
        return Str::of($body)
            ->squish()
            ->limit(config()->integer('logralo.comments.max_length'), end: '')
            ->toString();
    }
}
