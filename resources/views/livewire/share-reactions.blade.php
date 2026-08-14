<?php

declare(strict_types=1);

use App\Actions\ToggleReaction;
use App\Enums\ReactionEmoji;
use App\Models\Mark;
use App\Models\Reaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The reaction bar on a shared link.
 *
 * The same five emoji as the feed, on a page that anyone with the link can
 * open. Reacting is for members only — a reaction is attributed to a person,
 * and there is nobody to attribute a stranger's tap to — but everyone sees the
 * counts, which is what makes a shared card look alive rather than static.
 */
new class extends Component
{
    #[Locked]
    public Mark $mark;

    /** @return EloquentCollection<int, Reaction> */
    #[Computed]
    public function reactions(): EloquentCollection
    {
        return $this->mark->reactions()->get();
    }

    public function countFor(ReactionEmoji $emoji): int
    {
        return $this->reactions->where('emoji', $emoji)->count();
    }

    #[Computed]
    public function mine(): ?ReactionEmoji
    {
        return auth()->check()
            ? $this->reactions->firstWhere('user_id', auth()->id())?->emoji
            : null;
    }

    public function react(string $emoji): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        resolve(ToggleReaction::class)->handle($this->mark, $user, ReactionEmoji::from($emoji));

        unset($this->reactions, $this->mine);
    }
};

?>

<div>
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach (ReactionEmoji::cases() as $emoji)
            @php
                $count = $this->countFor($emoji);
                $mine = $this->mine === $emoji;
            @endphp

            <button
                type="button"
                @if (auth()->check()) wire:click="react('{{ $emoji->value }}')" @else disabled @endif
                @class([
                    'flex items-center gap-1 rounded-full border px-2.5 py-1 text-sm transition',
                    'active:scale-95' => auth()->check(),
                    'border-accent/40 bg-accent/15' => $mine,
                    'border-white/10' => ! $mine,
                    'opacity-45' => $count === 0 && ! $mine,
                ])
                aria-pressed="{{ $mine ? 'true' : 'false' }}"
                data-test="share-react-{{ $emoji->value }}"
            >
                <span class="leading-none">{{ $emoji->character() }}</span>
                @if ($count > 0)
                    <span class="text-xs tabular-nums">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @guest
        <p class="mt-3 text-xs text-zinc-500">Las reacciones son de los del grupo.</p>
    @endguest
</div>
