<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Comment;
use App\Models\Mark;
use Illuminate\Support\Collection;

/**
 * The thread under one photo, read whole.
 *
 * Whole rather than windowed, for the reason `GoalHistory` gives: this is five
 * friends looking at one day's photo, so a thread is a handful of rows and a
 * window would only add a "cargar más" nobody would ever need to tap. The
 * viewer shows as many as its box fits and scrolls to the rest.
 *
 * Newest first, because the list is drawn bottom-up: the box is anchored to
 * the end of the conversation, which is the part somebody opening a photo
 * actually wants.
 */
final readonly class MarkComments
{
    /** @return Collection<int, Comment> */
    public function for(Mark $mark): Collection
    {
        return $mark->comments()
            // The name and the face on every line. Without this the thread is
            // one select per comment.
            ->with('user')
            ->latest()
            ->orderByDesc('id')
            ->get();
    }
}
