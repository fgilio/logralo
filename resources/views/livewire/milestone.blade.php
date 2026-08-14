<?php

declare(strict_types=1);

use App\Concerns\ResumesSharing;
use App\Models\Mark;
use App\Queries\GoalHistory;
use App\Services\PhotoProcessor;
use App\Services\StreakCalculator;
use App\Services\StreakMilestone;
use App\ValueObjects\MarkEntry;
use App\ValueObjects\MarkHistory;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The celebration after a mark that lands on a round number.
 *
 * One of these on the page rather than one per goal card: the sheet is
 * page-wide furniture, and twenty goal cards each carrying a hidden modal is
 * twenty modals in the DOM for an event that fires a handful of times a month.
 *
 * It holds a mark id and nothing else between firings, so a member who dismisses
 * it and marks something else does not get the last one back.
 */
new class extends Component
{
    use ResumesSharing;

    public ?string $markId = null;

    #[Computed]
    public function entry(): ?MarkEntry
    {
        if ($this->markId === null) {
            return null;
        }

        $mark = Mark::query()->with(['user', 'goal'])->find($this->markId);

        if ($mark === null) {
            return null;
        }

        $history = resolve(GoalHistory::class)->for($mark->goal);

        return new MarkEntry(
            mark: $mark,
            streak: resolve(StreakCalculator::class)->endingOn($history->dates(), $mark->marked_on),
            ghostRun: 0,
            photo: $mark->photo_key === null ? null : resolve(PhotoProcessor::class)->links(
                $mark->photo_key,
                $mark->photo_width ?? 4,
                $mark->photo_height ?? 3,
            ),
        );
    }

    #[Computed]
    public function headline(): string
    {
        return resolve(StreakMilestone::class)->headline($this->entry->streak ?? 0);
    }

    #[On('milestone-reached')]
    public function celebrate(string $markId): void
    {
        $this->markId = $markId;

        unset($this->entry, $this->headline);

        Flux::modal('milestone')->show();
    }

    public function dismiss(): void
    {
        $this->markId = null;

        unset($this->entry, $this->headline);

        Flux::modal('milestone')->close();
    }
};

?>

<div>
    <flux:modal
        name="milestone"
        class="w-full max-w-sm rounded-2xl p-0"
        @close="$wire.dismiss()"
    >
        @if ($this->entry !== null)
            @php $entry = $this->entry; @endphp

            <div class="relative overflow-hidden rounded-2xl bg-zinc-900 text-white">
                @if ($entry->photo !== null)
                    <x-photo :links="$entry->photo" :alt="$entry->mark->goal->name" eager />
                @endif

                <div
                    aria-hidden="true"
                    class="pointer-events-none absolute -top-20 -right-12 size-48 rounded-full bg-accent/30 blur-3xl"
                ></div>

                <div class="relative p-6 text-center">
                    <span class="ignite inline-block text-5xl">{{ $entry->mark->goal->emoji }}</span>

                    <p class="mt-4 font-display text-3xl tracking-wide">{{ $this->headline }}</p>
                    <p class="mt-1 text-sm text-white/60">{{ $entry->mark->goal->name }}</p>

                    <div class="mt-6 flex flex-col items-center gap-3">
                        {{-- The one moment anybody actually wants to tell the
                             group, so the share is the primary button and the
                             way out is the quiet one. --}}
                        <div class="flex justify-center">
                            <x-share-button :entry="$entry" tone="inverse" />
                        </div>

                        <button
                            type="button"
                            wire:click="dismiss"
                            class="text-xs tracking-[0.2em] text-white/40 uppercase transition hover:text-white/70"
                            data-test="milestone-dismiss"
                        >
                            Seguir
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
