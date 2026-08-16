<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Everyone in the group, keyed by id.
 *
 * This exists for the avatars. Most of the app carries a `User` to the
 * template that draws one, but two do not: the standings row and the recap
 * card are built from `Standing`, which is frozen into the recap as JSON at
 * month close and therefore holds names and numbers rather than pictures — a
 * URL frozen there would be a signed one that expired the same hour, or a
 * storage key for a picture the member has since replaced.
 *
 * So the picture is looked up live, from a table this product caps at one
 * group of friends. The whole thing is one query per request: the container
 * holds this scoped, so the twenty avatars a feed can draw share the read.
 */
final class Members
{
    /** @var Collection<string, User>|null */
    private ?Collection $directory = null;

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
        return $this->directory ??= User::query()->get()->keyBy('id');
    }
}
