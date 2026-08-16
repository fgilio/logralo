<?php

declare(strict_types=1);

use App\Actions\MarkGoal;
use App\Actions\UnmarkGoal;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use App\Models\Mark;
use App\Queries\GoalHistory;
use App\Services\PhotoProcessor;
use App\Services\PhotoRule;
use App\Services\StreakCalculator;
use App\Services\StreakMilestone;
use App\ValueObjects\MarkHistory;
use App\ValueObjects\PhotoLinks;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One goal, on one day.
 *
 * Tap marks. Tap again un-marks, while the day is still open. Holding opens the
 * sheet, where the camera and the note live — a hold cannot open the camera
 * directly, because the browser's user activation does not survive the press
 * timer on iOS, and a camera button that silently does nothing would be worse
 * than one extra tap.
 */
new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public Goal $goal;

    /** The day this card marks: today, or yesterday inside the grace window. */
    #[Locked]
    public string $date;

    #[Locked]
    public bool $compact = false;

    /** @var TemporaryUploadedFile|null */
    public $photo;

    public string $note = '';

    public function mount(Goal $goal, ?string $date = null, bool $compact = false): void
    {
        abort_unless($goal->user_id === Auth::id(), 403);

        $this->goal = $goal;
        $this->compact = $compact;
        $this->date = $date ?? $goal->user->clock()->today()->toDateString();
    }

    #[Computed]
    public function mark(): ?Mark
    {
        return $this->goal->marks()->where('marked_on', $this->date)->first();
    }

    /**
     * This goal's whole mark history, read once.
     *
     * Both the streak and the photo rule need it, and both are always read on
     * every render — so asking for it separately was two identical unbounded
     * queries per card, times up to ten cards on the page. The computed cache
     * is per component, so this is the one place they can share it.
     */
    #[Computed]
    public function history(): MarkHistory
    {
        return resolve(GoalHistory::class)->for($this->goal);
    }

    #[Computed]
    public function streak(): int
    {
        return resolve(StreakCalculator::class)->current(
            $this->history->dates(),
            $this->goal->user->clock(),
        );
    }

    #[Computed]
    public function requiresPhoto(): bool
    {
        return resolve(PhotoRule::class)->requiresPhoto(
            $this->history->recentFullnessBefore(
                $this->date,
                (int) config('logralo.goals.ghosts_before_camera'),
            ),
        );
    }

    #[Computed]
    public function photoLinks(): ?PhotoLinks
    {
        $mark = $this->mark;

        if ($mark === null || $mark->photo_key === null) {
            return null;
        }

        return resolve(PhotoProcessor::class)->links(
            $mark->photo_key,
            // 4:3 like MarkEntries, not 1:1. Same missing data, and two
            // different reserved boxes for it is how the pair drifts.
            $mark->photo_width ?? 4,
            $mark->photo_height ?? 3,
        );
    }

    /** Unique page-wide: the same goal can be on screen for two days at once. */
    #[Computed]
    public function sheetName(): string
    {
        return "sheet-{$this->goal->id}-{$this->date}";
    }

    /** A single tap: mark, or un-mark what a mistap marked. */
    public function press(): void
    {
        if ($this->mark !== null) {
            $this->remove();

            return;
        }

        // Two ghost marks in a row and the third tap owes a photo, so the tap
        // opens the sheet instead of marking.
        if ($this->requiresPhoto) {
            $this->modal($this->sheetName)->show();

            return;
        }

        $this->store();
    }

    public function save(): void
    {
        $this->validate([
            'photo' => [
                'nullable',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
                'max:'.config('logralo.photos.max_upload_kilobytes'),
            ],
            'note' => ['nullable', 'string', 'max:'.config('logralo.goals.note_max_length')],
        ]);

        $this->store();
    }

    public function remove(): void
    {
        $mark = $this->mark;

        if ($mark === null) {
            return;
        }

        try {
            resolve(UnmarkGoal::class)->handle($mark);
        } catch (UserFacingException $userFacingException) {
            Flux::toast(text: $userFacingException->userMessage(), variant: 'warning');

            return;
        }

        $this->after();
    }

    private function store(): void
    {
        try {
            $mark = resolve(MarkGoal::class)->handle(
                goal: $this->goal,
                day: CarbonImmutable::parse($this->date),
                photo: $this->photo,
                note: $this->note === '' ? null : $this->note,
            );
        } catch (UserFacingException $userFacingException) {
            Flux::toast(text: $userFacingException->userMessage(), variant: 'warning');
            $this->modal($this->sheetName)->show();

            return;
        }

        $this->after();
        $this->celebrate($mark);
    }

    /**
     * A streak that lands on a round number is the one moment somebody wants
     * to tell the group, so the app offers there and nowhere else.
     *
     * The streak counted is the one ending on the day that was just marked,
     * not `$this->streak`, which counts back from today. Inside the grace
     * window those are different numbers: marking yesterday at 11am while
     * today is still unmarked would otherwise test today's run and celebrate
     * the wrong day, or miss it.
     *
     * Only the streak is checked, deliberately: taking the lead in the month
     * would be worth celebrating too, but it costs a standings query on the
     * hottest tap in the app.
     */
    private function celebrate(Mark $mark): void
    {
        $streak = resolve(StreakCalculator::class)->endingOn(
            resolve(GoalHistory::class)->for($this->goal)->dates(),
            $mark->marked_on,
        );

        if (! resolve(StreakMilestone::class)->isMilestone($streak)) {
            return;
        }

        $this->dispatch('milestone-reached', markId: $mark->id);
    }

    private function after(): void
    {
        $this->reset('photo', 'note');
        // `history` belongs in here with the rest: `press()` reads it through
        // requiresPhoto BEFORE store() writes the mark, so leaving it cached
        // rerenders the flame off the history from a moment ago and the
        // streak stays a day behind until some later request clears it.
        unset($this->mark, $this->history, $this->streak, $this->requiresPhoto, $this->photoLinks);

        $this->modal($this->sheetName)->close();
        $this->dispatch('mark-updated');
    }
};

?>

@php
    $mark = $this->mark;
    $isGhost = $mark?->isGhost() ?? false;
    $isFull = $mark?->isFull() ?? false;
    // The fuel gauge tops out at a month, so week two still reads as progress
    // rather than a bar that is full forever.
    $fuel = min($this->streak / 30, 1);
@endphp

<div wire:key="goal-card-{{ $goal->id }}-{{ $date }}">
    @if ($compact)
        <button
            type="button"
            x-data="longPress({ delay: 420 })"
            @pointerdown="start($event)"
            @pointermove="move($event)"
            @pointerup="end()"
            @pointercancel="cancel()"
            @lostpointercapture="cancel()"
            @contextmenu.prevent
            @click.capture="onClick($event)"
            @short-press="$wire.press()"
            @long-press="$flux.modal('{{ $this->sheetName }}').show()"
            {{-- `short-press` rides on pointerup, which a keyboard never
                 sends, so without this Enter and Space did nothing at all on
                 the grace chip. detail is 0 only for keyboard-activated
                 clicks, so a tap cannot double-fire through here. The full
                 card next door has always had its own keydown handlers. --}}
            @click="$event.detail === 0 && $wire.press()"
            aria-pressed="{{ $mark !== null ? 'true' : 'false' }}"
            @class([
                'tap-target flex items-center gap-2 rounded-full border py-1.5 pr-3 pl-2.5 text-sm transition',
                'border-amber-500/40 bg-amber-500/10' => $mark === null,
                'border-transparent bg-accent/15 text-accent-content' => $mark !== null,
            ])
            :class="pressing && 'scale-95'"
            data-test="grace-goal-{{ $goal->id }}"
        >
            <span class="text-base leading-none">{{ $goal->emoji }}</span>
            <span class="max-w-28 truncate font-medium">{{ $goal->name }}</span>

            @if ($mark !== null)
                <flux:icon name="check-circle" variant="micro" />
            @endif
        </button>
    @else
        <div
            x-data="longPress({ delay: 420 })"
            @pointerdown="start($event)"
            @pointermove="move($event)"
            @pointerup="end()"
            @pointercancel="cancel()"
            @lostpointercapture="cancel()"
            @contextmenu.prevent
            @click.capture="onClick($event)"
            @short-press="$wire.press()"
            @long-press="$flux.modal('{{ $this->sheetName }}').show()"
            @keydown.enter.prevent="$wire.press()"
            @keydown.space.prevent="$wire.press()"
            @class([
                'tap-target relative flex aspect-4/5 w-full flex-col justify-between overflow-hidden rounded-2xl p-3 text-left transition-transform duration-150',
                'border border-zinc-200 bg-white dark:border-white/10 dark:bg-white/5' => $mark === null,
                'border border-dashed border-ghost/60 bg-ghost/5' => $isGhost,
                'border border-transparent bg-zinc-900 text-white' => $isFull,
            ])
            :class="pressing && 'scale-[0.97]'"
            role="button"
            tabindex="0"
            aria-pressed="{{ $mark !== null ? 'true' : 'false' }}"
            aria-label="{{ $goal->name }}"
            data-test="goal-card-{{ $goal->id }}"
        >
            @if ($isFull && $this->photoLinks !== null)
                <x-photo :links="$this->photoLinks" :alt="$goal->name" fill eager />
                <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/20 to-black/10"></div>
            @else
                {{-- The streak, drawn as fuel climbing the left edge. --}}
                <div
                    class="ember-gauge absolute inset-y-0 left-0 w-1"
                    style="--fuel: {{ number_format($fuel, 3, '.', '') }}"
                    aria-hidden="true"
                ></div>
            @endif

            <div class="relative flex items-start justify-between">
                <span class="text-3xl leading-none {{ $isGhost ? 'opacity-50 grayscale' : '' }}">
                    {{ $goal->emoji }}
                </span>

                <div wire:loading wire:target="press, save, remove">
                    <flux:icon name="loading" variant="micro" class="animate-spin opacity-60" />
                </div>

                <div wire:loading.remove wire:target="press, save, remove">
                    @if ($isGhost)
                        <span class="text-lg" title="Marcado sin foto">🌫️</span>
                    @elseif ($isFull)
                        <flux:icon name="check-circle" variant="solid" class="size-6 text-accent" />
                    @endif
                </div>
            </div>

            <div class="relative">
                <p class="line-clamp-2 text-sm leading-tight font-semibold">{{ $goal->name }}</p>

                <div class="mt-1.5 flex items-center justify-between">
                    <x-flame :days="$this->streak" :dim="$mark === null && ! $isFull" size="sm" />

                    @if ($this->requiresPhoto && $mark === null)
                        <span class="text-xs" title="Esta va con foto">📸</span>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- The sheet: camera, note, and the way out of a mistap. --}}
    <flux:modal
        :name="$this->sheetName"
        flyout
        position="bottom"
        class="max-h-[85dvh] overscroll-contain rounded-t-2xl p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]"
    >
        <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-zinc-300 dark:bg-white/20"></div>

        <flux:heading size="lg" class="flex items-center gap-2">
            <span>{{ $goal->emoji }}</span>
            <span>{{ $goal->name }}</span>
        </flux:heading>

        @if ($this->requiresPhoto && $mark === null)
            <flux:callout variant="warning" icon="camera" class="mt-4">
                <flux:callout.heading>Pics or it didn't happen 📸</flux:callout.heading>
                <flux:callout.text>Van dos veces seguidas sin foto. Esta va con prueba.</flux:callout.text>
            </flux:callout>
        @endif

        @if ($mark === null)
            {{-- The camera original is resized and re-encoded in the browser
                 before it uploads, so `wire:model` is out and `photoPicker`
                 does the upload by hand. --}}
            <div
                class="mt-5 flex flex-col gap-4"
                x-data="photoPicker({
                    maxPixels: {{ (int) (config('logralo.photos.client_max_megapixels') * 1_000_000) }},
                    quality: {{ (int) config('logralo.photos.client_jpeg_quality') / 100 }},
                })"
            >
                <input
                    type="file"
                    accept="image/*"
                    capture="environment"
                    @change="pick($event)"
                    x-ref="camera"
                    class="sr-only"
                    data-test="camera-input"
                >

                <button
                    type="button"
                    @click="open()"
                    class="relative grid aspect-video w-full place-items-center overflow-hidden rounded-xl border-2 border-dashed border-zinc-300 bg-zinc-50 transition active:scale-[0.99] dark:border-white/15 dark:bg-white/5"
                    data-test="open-camera"
                >
                    @if ($photo !== null && $photo->isPreviewable())
                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="absolute inset-0 size-full object-cover">
                        <span class="relative rounded-full bg-black/60 px-3 py-1 text-xs text-white">Cambiar foto</span>
                    @else
                        <span class="flex flex-col items-center gap-2 text-zinc-500 dark:text-zinc-400">
                            <flux:icon name="camera" variant="solid" class="size-8" />
                            <span class="text-sm font-medium">Sacar foto</span>
                        </span>
                    @endif

                    {{-- Covers the resize as well as the upload: on an older
                         phone the resize is the longer half, and it happens
                         before Livewire knows a file exists. The percentage
                         only appears once bytes are moving, so the quiet
                         stretch is the resize and the counting one is the
                         upload. --}}
                    <div
                        x-show="busy"
                        x-cloak
                        class="absolute inset-0 grid place-items-center gap-1 bg-zinc-900/60 text-white"
                    >
                        <flux:icon name="loading" class="animate-spin" />
                        <span x-show="progress > 0" x-text="`${progress}%`" class="text-xs"></span>
                    </div>
                </button>

                <flux:error name="photo" />

                <flux:input
                    wire:model="note"
                    placeholder="Una línea, si querés"
                    maxlength="{{ config('logralo.goals.note_max_length') }}"
                    data-test="note"
                />

                {{-- Saving mid-upload would mark the day without the photo the
                     member is watching upload, so the button waits for it.

                     Both bindings are load-bearing — the Flux prop paints the
                     button before Alpine is up, and the `x-bind` governs it
                     afterwards — but the rule they share is written once, or
                     the two would disagree with nothing to notice. --}}
                @php($photoStillOwed = $this->requiresPhoto && $photo === null)

                <flux:button
                    wire:click="save"
                    variant="primary"
                    class="w-full"
                    :disabled="$photoStillOwed"
                    x-bind:disabled="busy || @js($photoStillOwed)"
                    data-test="save"
                >
                    {{ $photo !== null ? 'Marcar con foto' : 'Marcar' }}
                </flux:button>
            </div>
        @else
            <div class="mt-5 flex flex-col gap-4">
                @if ($mark->note !== null)
                    <flux:text>{{ $mark->note }}</flux:text>
                @endif

                <flux:button wire:click="remove" variant="danger" class="w-full" data-test="remove">
                    Quitar marca
                </flux:button>

                <flux:text size="sm" class="text-center">
                    Podés quitarla mientras el día siga abierto.
                </flux:text>
            </div>
        @endif
    </flux:modal>
</div>
