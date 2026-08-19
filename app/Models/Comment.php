<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something somebody said under a photo.
 *
 * Reactions are the cheap answer and this is the one that costs a sentence.
 * They live side by side rather than one replacing the other: a reaction is
 * found by looking, and a comment is the part somebody had to write.
 *
 * Only the open photo shows these. The feed stays a wall of pictures.
 *
 * @property string $id
 * @property string $mark_id
 * @property string $user_id
 * @property string $body
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Mark $mark
 * @property-read User $user
 */
#[Fillable(['user_id', 'body'])]
final class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use HasUlids;

    /** @return BelongsTo<Mark, $this> */
    public function mark(): BelongsTo
    {
        return $this->belongsTo(Mark::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
