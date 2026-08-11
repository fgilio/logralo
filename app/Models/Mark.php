<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\LocalDate;
use App\Enums\MarkKind;
use Carbon\CarbonImmutable;
use Database\Factories\MarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $goal_id
 * @property string $user_id
 * @property CarbonImmutable $marked_on
 * @property string|null $photo_key
 * @property int|null $photo_width
 * @property int|null $photo_height
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Goal $goal
 * @property-read User $user
 * @property-read Collection<int, Reaction> $reactions
 */
#[Fillable(['user_id', 'marked_on', 'photo_key', 'photo_width', 'photo_height', 'note'])]
final class Mark extends Model
{
    /** @use HasFactory<MarkFactory> */
    use HasFactory;

    use HasUlids;

    /** @return BelongsTo<Goal, $this> */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function kind(): MarkKind
    {
        return $this->photo_key === null ? MarkKind::Ghost : MarkKind::Full;
    }

    public function isFull(): bool
    {
        return $this->kind() === MarkKind::Full;
    }

    public function isGhost(): bool
    {
        return $this->kind() === MarkKind::Ghost;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'marked_on' => LocalDate::class,
            'photo_width' => 'integer',
            'photo_height' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
