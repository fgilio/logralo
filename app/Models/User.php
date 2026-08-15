<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\UserClock;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string $timezone
 * @property string|null $magic_link_token
 * @property CarbonImmutable|null $magic_link_sent_at
 * @property CarbonImmutable|null $magic_link_used_at
 * @property CarbonImmutable|null $password_set_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Goal> $goals
 * @property-read Collection<int, Goal> $activeGoals
 * @property-read Collection<int, Mark> $marks
 * @property-read Collection<int, Reaction> $reactions
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token', 'magic_link_token'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUlids;
    use Notifiable;

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class)->orderBy('position');
    }

    /** @return HasMany<Goal, $this> */
    public function activeGoals(): HasMany
    {
        return $this->goals()->whereNull('archived_at');
    }

    /** @return HasMany<Mark, $this> */
    public function marks(): HasMany
    {
        return $this->hasMany(Mark::class);
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    /**
     * The member's own clock. Every day boundary, grace window and month edge
     * in the app is resolved through it.
     */
    public function clock(): UserClock
    {
        return UserClock::for($this);
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    public function initials(): string
    {
        $firstTwoWords = Str::of($this->name)->squish()->explode(' ')->take(2)->implode(' ');

        return Str::initials($firstTwoWords, capitalize: true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'magic_link_sent_at' => 'immutable_datetime',
            'magic_link_used_at' => 'immutable_datetime',
            'password_set_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
