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
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
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

    public int $limit = 0;

    public function mount(): void
    {
        $this->limit = (int) config('logralo.feed.page_size');
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

    public function loadMore(): void
    {
        if (! $this->page->hasMore) {
            return;
        }

        $this->limit += (int) config('logralo.feed.page_size');

        unset($this->page, $this->days);
    }

    public function react(string $markId, string $emoji): void
    {
        $mark = Mark::query()->findOrFail($markId);

        resolve(ToggleReaction::class)->handle($mark, $this->member(), ReactionEmoji::from($emoji));

        unset($this->page, $this->days);
    }

    /** A mark anywhere on the page changes what the feed should be showing. */
    #[On('mark-updated')]
    public function reload(): void
    {
        unset($this->page, $this->days);
    }
};

?>

@php
    $clock = $this->member()->clock();
    $today = $clock->today()->toDateString();
    $yesterday = $clock->yesterday()->toDateString();
    $first = true;

    // The ladder: inside a day the newest mark is the cover, the one behind it
    // is half of that, and everything older is a row. It restarts at every
    // divider, so each day gets a cover of its own.
    $ladder = fn (int $rank): int => match (true) {
        $rank === 1 => 3,
        $rank === 2 => 2,
        default => 1,
    };
@endphp

<div class="flex flex-col gap-2">
    @forelse ($this->days as $date => $entries)
        <x-day-divider :day="$entries->first()->day()" :today="$today" :yesterday="$yesterday" />

        {{-- A recap card sits in the day without taking a rung, so the ladder
             counts marks and nothing else. --}}
        @php $rank = 0; @endphp

        @foreach ($entries as $entry)
            @if ($entry instanceof App\ValueObjects\RecapEntry)
                <x-feed.recap-card :entry="$entry" wire:key="{{ $entry->key() }}" />
            @else
                {{-- Counted here rather than inside the attribute: Blade emits
                     an anonymous component's attribute expressions twice, once
                     for the data array and once for the attribute bag, so
                     anything with a side effect runs twice and the second value
                     is the one that lands. --}}
                @php $rank++; @endphp

                <x-feed.mark-card
                    :entry="$entry"
                    :height="$ladder($rank)"
                    :eager="$first"
                    :reacted="$entry->mark->reactions->firstWhere('user_id', auth()->id())?->emoji"
                    wire:key="{{ $entry->key() }}"
                />
                @php $first = false; @endphp
            @endif
        @endforeach
    @empty
        <div class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-white/10">
            <flux:icon name="fire" class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-3">Todavía no hay nada</flux:heading>
            <flux:text class="mt-1">Marcá tu primer objetivo y arrancá la racha.</flux:text>
        </div>
    @endforelse

    @if ($this->page->hasMore)
        <div
            x-intersect.margin.400px="$wire.loadMore()"
            wire:loading.class="opacity-100"
            wire:target="loadMore"
            class="grid h-20 place-items-center"
        >
            <flux:icon name="loading" class="animate-spin text-zinc-400" />
        </div>
    @elseif ($this->page->entries->isNotEmpty())
        <p class="py-8 text-center text-xs tracking-[0.2em] text-zinc-400 uppercase dark:text-zinc-600">
            Hasta acá llega la historia
        </p>
    @endif
</div>
