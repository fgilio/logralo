<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\LocalDate;
use App\Concerns\Shareable;
use App\ValueObjects\Standing;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyRecapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A closed month, frozen. Scores are snapshotted at close so later archiving
 * or new goals can never rewrite a past month.
 *
 * @property string $id
 * @property CarbonImmutable $month
 * @property CarbonImmutable $posted_on
 * @property string|null $winner_user_id
 * @property string|null $runner_up_user_id
 * @property string|null $best_streak_user_id
 * @property string|null $best_streak_goal_id
 * @property int $best_streak_days
 * @property array<int, array<string, mixed>> $standings
 * @property string|null $share_token
 * @property int $share_views
 * @property CarbonImmutable|null $share_last_viewed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $winner
 * @property-read User|null $runnerUp
 * @property-read User|null $bestStreakUser
 * @property-read Goal|null $bestStreakGoal
 */
#[Fillable([
    'month',
    'posted_on',
    'winner_user_id',
    'runner_up_user_id',
    'best_streak_user_id',
    'best_streak_goal_id',
    'best_streak_days',
    'standings',
])]
final class MonthlyRecap extends Model
{
    /** @use HasFactory<MonthlyRecapFactory> */
    use HasFactory;

    use HasUlids;
    use Shareable;

    /** @return BelongsTo<User, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function runnerUp(): BelongsTo
    {
        return $this->belongsTo(User::class, 'runner_up_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function bestStreakUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'best_streak_user_id');
    }

    /** @return BelongsTo<Goal, $this> */
    public function bestStreakGoal(): BelongsTo
    {
        return $this->belongsTo(Goal::class, 'best_streak_goal_id');
    }

    /** @return Collection<int, Standing> */
    public function standingEntries(): Collection
    {
        return collect($this->standings)->map(Standing::fromArray(...));
    }

    /**
     * The recap covering a day, which exists only once that day's month has
     * been closed.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function covering(Builder $query, CarbonImmutable $day): void
    {
        $query->where('month', $day->startOfMonth()->toDateString());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => LocalDate::class,
            'posted_on' => LocalDate::class,
            'best_streak_days' => 'integer',
            'standings' => 'array',
            'share_views' => 'integer',
            'share_last_viewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
