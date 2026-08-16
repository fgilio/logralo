<?php

declare(strict_types=1);

use App\Actions\ToggleReaction;
use App\Concerns\InteractsWithMember;
use App\Concerns\ResumesSharing;
use App\Enums\ReactionEmoji;
use App\Models\Mark;
use App\Queries\FeedPage;
use App\ValueObjects\FeedEntry;
use App\ValueObjects\FeedResult;
use App\ValueObjects\MarkEntry;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The group feed, and the only history the app has.
 *
 * It grows by raising a limit rather than by paging with a cursor: marks land
 * at the top all day, and a cursor would either repeat rows or skip them.
 * Re-reading is exact, and the numbers here are a group of friends, not a
 * public network.
 */
new class extends Component
{
    use InteractsWithMember;
    use ResumesSharing;

    // Locked because an unlocked public property is a client-writable input:
    // `limit` reaches a `->limit($n + 1)->get()` with three eager loads behind
    // it, and the whole marks table is exactly the thing that grows forever.
    #[Locked]
    public int $limit = 0;

    public function mount(): void
    {
        $this->limit = config()->integer('logralo.feed.page_size');
    }

    #[Computed]
    public function page(): FeedResult
    {
        return resolve(FeedPage::class)->load($this->limit);
    }

    /** @return Collection<string, Collection<int, FeedEntry>> */
    #[Computed]
    public function days(): Collection
    {
        return $this->page->entries->groupBy(fn (FeedEntry $entry): string => $entry->day()->toDateString());
    }

    /**
     * The rung each mark stands on, keyed by entry. Inside a day the newest
     * mark is the cover, the one behind it half of that, and everything older
     * a row. A recap card sits in the day without taking a rung.
     *
     * @return Collection<string, int>
     */
    #[Computed]
    public function rungs(): Collection
    {
        return $this->days->flatMap(fn (Collection $entries): Collection => $entries
            ->filter(fn (FeedEntry $entry): bool => $entry instanceof MarkEntry)
            ->values()
            ->mapWithKeys(fn (FeedEntry $entry, int $index): array => [$entry->key() => max(3 - $index, 1)]));
    }

    #[Computed]
    public function today(): string
    {
        return $this->member()->clock()->today()->toDateString();
    }

    #[Computed]
    public function yesterday(): string
    {
        return $this->member()->clock()->yesterday()->toDateString();
    }

    public function loadMore(): void
    {
        if (! $this->page->hasMore) {
            return;
        }

        $this->limit += config()->integer('logralo.feed.page_size');

        $this->refresh();
    }

    public function react(string $markId, string $emoji): void
    {
        $mark = Mark::query()->findOrFail($markId);

        resolve(ToggleReaction::class)->handle($mark, $this->member(), ReactionEmoji::from($emoji));

        $this->refresh();
    }

    /** A mark anywhere on the page changes what the feed should be showing. */
    #[On('mark-updated')]
    public function reload(): void
    {
        $this->refresh();
    }

    private function refresh(): void
    {
        unset($this->page, $this->days, $this->rungs);
    }
};

?>

@use('App\ValueObjects\RecapEntry')

<div class="flex flex-col gap-2">
    @forelse ($this->days as $entries)
        <x-day-divider :day="$entries->first()->day()" :today="$this->today" :yesterday="$this->yesterday" />

        @foreach ($entries as $entry)
            @if ($entry instanceof RecapEntry)
                <x-feed.recap-card :entry="$entry" wire:key="{{ $entry->key() }}" />
            @else
                @php
                    $rung = $this->rungs[$entry->key()];
                    $reacted = $entry->mark->reactions->firstWhere('user_id', auth()->id())?->emoji;
                @endphp

                {{-- Keyed by rung as well: as marks arrive a cover becomes a
                     split card, and that is a different card rather than the
                     same one patched in place. --}}
                <x-feed.mark-card
                    :entry="$entry"
                    :rung="$rung"
                    :eager="$this->rungs->keys()->first() === $entry->key()"
                    :reacted="$reacted"
                    wire:key="{{ $entry->key() }}-{{ $rung }}"
                />
            @endif
        @endforeach
    @empty
        <div class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-white/10">
            <x-brand-mark muted class="mx-auto size-8" />
            <flux:heading class="mt-3">Todavía no hay nada</flux:heading>
            <flux:text class="mt-1">Marcá tu primer objetivo y arrancá la racha.</flux:text>
        </div>
    @endforelse

    @if ($this->page->hasMore)
        {{-- The button is the only way in for anyone whose browser never fires
             the intersection, and the sentinel is what everyone else gets. --}}
        <button
            type="button"
            x-intersect.margin.400px="$wire.loadMore()"
            wire:click="loadMore"
            wire:target="loadMore"
            class="tap-target grid h-20 place-items-center text-xs tracking-[0.2em] text-zinc-400 uppercase dark:text-zinc-500"
            data-test="feed-more"
        >
            <span wire:loading.remove wire:target="loadMore">Ver más</span>
            <flux:icon name="loading" class="animate-spin text-zinc-400" wire:loading wire:target="loadMore" />
        </button>
    @elseif ($this->page->entries->isNotEmpty())
        <p class="py-8 text-center text-xs tracking-[0.2em] text-zinc-500 uppercase dark:text-zinc-400">
            Hasta acá llega la historia
        </p>
    @endif
</div>
