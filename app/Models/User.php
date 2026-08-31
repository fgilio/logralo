<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Gravatar;
use App\Services\PhotoProcessor;
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
use NotificationChannels\WebPush\HasPushSubscriptions;
use NotificationChannels\WebPush\PushSubscription;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $avatar_key
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
 * @property-read Collection<int, Feedback> $feedback
 * @property-read Collection<int, PushSubscription> $pushSubscriptions
 */
#[Fillable(['name', 'email', 'avatar_key', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token', 'magic_link_token'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPushSubscriptions;
    use HasUlids;
    use Notifiable;

    /**
     * The same two letters for somebody the roster no longer has — a recap
     * remembers a name long after the member behind it could be looked up.
     */
    public static function initialsFor(string $name): string
    {
        $firstTwoWords = Str::of($name)->squish()->explode(' ')->take(2)->implode(' ');

        return Str::initials($firstTwoWords, capitalize: true);
    }

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

    /** The position a goal entering the grid takes: after every existing one. */
    public function nextGoalPosition(): int
    {
        return (int) $this->goals()->max('position') + 1;
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

    /** @return HasMany<Feedback, $this> */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
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

    /**
     * The picture this member uploaded, or null if they never did.
     *
     * Kept apart from `gravatarUrl()` because the two are not the same kind of
     * answer: this one points at a file the app stored and can be shown on
     * sight, and that one is a guess the browser has to try.
     */
    public function uploadedAvatarUrl(): ?string
    {
        return $this->avatar_key === null
            ? null
            : resolve(PhotoProcessor::class)->avatarUrl($this->avatar_key);
    }

    /**
     * Where the browser should look for a Gravatar, or null when the feature
     * is switched off.
     *
     * Whether it should look at all is not asked here: an upload outranks a
     * Gravatar, and that ordering lives in `x-avatar`, which is the one place
     * allowed to know it. Answering null for a member with a picture would be
     * the same rule written a second time, in the layer underneath.
     */
    public function gravatarUrl(): ?string
    {
        return resolve(Gravatar::class)->url($this->email);
    }

    public function initials(): string
    {
        return self::initialsFor($this->name);
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
