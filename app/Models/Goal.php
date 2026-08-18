<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoalVisibility;
use Carbon\CarbonImmutable;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $user_id
 * @property string $emoji
 * @property string $name
 * @property int $position
 * @property GoalVisibility $visibility
 * @property CarbonImmutable|null $archived_at
 * @property list<array{from: string, archived_on: string, through: string}>|null $streak_pauses
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Mark> $marks
 */
#[Fillable(['emoji', 'name', 'position', 'visibility', 'archived_at', 'streak_pauses'])]
final class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    use HasUlids;

    // Mirrors the column default, so a freshly created instance answers
    // visibility questions without a refresh round trip.
    #[Override]
    protected $attributes = [
        'visibility' => GoalVisibility::Group->value,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Mark, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isPrivate(): bool
    {
        return $this->visibility === GoalVisibility::Private;
    }

    /** @return list<array{from: string, archived_on: string, through: string}> */
    public function streakPauses(): array
    {
        return $this->streak_pauses ?? [];
    }

    /** @param  Builder<$this>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * What this viewer may see: every group goal, plus their own private
     * ones. Every read that shows one member's goals to another goes
     * through here, so widening visibility later means widening
     * this scope rather than hunting call sites.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $viewer): void
    {
        $query->where(fn (Builder $goals) => $goals
            ->where('visibility', GoalVisibility::Group)
            ->orWhere('user_id', $viewer->id));
    }

    /**
     * The goals the group competes on. The pulse ring and the monthly score
     * count these for everyone, owner included, so a member's numbers
     * never hint at goals the group cannot see.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function sharedWithGroup(Builder $query): void
    {
        $query->where('visibility', GoalVisibility::Group);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'visibility' => GoalVisibility::class,
            'archived_at' => 'immutable_datetime',
            'streak_pauses' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
