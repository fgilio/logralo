<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Everyone in the group, read once and shared by whoever asks.
 *
 * The group is capped at a handful of friends, so the whole roster is one
 * cheap read — and every screen wants all of it. The pulse strip walks it, the
 * monthly table walks it, and the avatars pick members out of it one at a
 * time. Before this there were three identical selects on one render; the
 * container holds this scoped, so now there is one.
 *
 * The avatars are why the third caller exists at all. Most of the app carries
 * a `User` to the template that draws one, but the standings row and the recap
 * card are built from `Standing`, which is frozen into the recap as JSON at
 * month close and therefore holds names and numbers rather than pictures — a
 * URL frozen there would be a signed one that expired the same hour, or a
 * storage key for a picture the member has since replaced. So those two look
 * the face up live, by id.
 */
final class Members
{
    /** @var Collection<int, User>|null */
    private ?Collection $roster = null;

    /** @var Collection<string, User>|null */
    private ?Collection $directory = null;

    /**
     * Everyone, in the order every screen lists them.
     *
     * Ordered by the database rather than by the collection, so the sequence is
     * the one the two callers were already getting.
     *
     * @return Collection<int, User>
     */
    public function roster(): Collection
    {
        return $this->roster ??= User::query()->orderBy('name')->get();
    }

    public function find(?string $id): ?User
    {
        if ($id === null) {
            return null;
        }

        return $this->directory()->get($id);
    }

    /** @return Collection<string, User> */
    public function directory(): Collection
    {
        return $this->directory ??= $this->roster()->keyBy('id');
    }
}
