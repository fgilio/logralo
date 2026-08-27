<?php

declare(strict_types=1);

use App\Actions\ToggleReaction;
use App\Enums\ReactionEmoji;
use App\Models\Mark;
use App\Models\Reaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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

    /** @return Collection<string, int> */
    #[Computed]
    public function counts(): Collection
    {
        return $this->reactions->countBy(fn (Reaction $reaction): string => $reaction->emoji->value);
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

        unset($this->reactions, $this->counts, $this->mine);
    }
};

?>

<div>
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach (ReactionEmoji::cases() as $emoji)
            @php
                $count = $this->counts->get($emoji->value, 0);
                $mine = $this->mine === $emoji;

                // The name the enum gives, then the number — the same shape the
                // feed's tally reads out. Without it the button announced as
                // whatever the browser calls the character in its own language,
                // and 🫵 has no useful name at all.
                $label = $count > 0 ? "{$emoji->label()} {$count}" : $emoji->label();
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
                aria-label="{{ $label }}"
                aria-pressed="{{ $mine ? 'true' : 'false' }}"
                data-test="share-react-{{ $emoji->value }}"
            >
                <span aria-hidden="true" class="leading-none">{{ $emoji->character() }}</span>
                @if ($count > 0)
                    <span aria-hidden="true" class="text-xs tabular-nums">{{ $count }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @guest
        <p class="mt-3 text-xs text-zinc-500">Las reacciones son de los del grupo.</p>
    @endguest
</div>
