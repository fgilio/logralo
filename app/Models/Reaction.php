<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReactionEmoji;
use Carbon\CarbonImmutable;
use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $mark_id
 * @property string $user_id
 * @property ReactionEmoji $emoji
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Mark $mark
 * @property-read User $user
 */
#[Fillable(['user_id', 'emoji'])]
final class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
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
            'emoji' => ReactionEmoji::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
