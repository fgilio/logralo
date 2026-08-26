<?php

declare(strict_types=1);

use App\Actions\AttachPhotoToMark;
use App\Actions\MarkGoal;
use App\Actions\UnmarkGoal;
use App\Concerns\PhotoValidationRules;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use App\Models\Mark;
use App\Queries\GoalHistory;
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
 * Tap marks. Tap a marked card and the sheet opens instead — un-marking is a
 * button in there, never the tap itself, because the same one-finger gesture
 * that claims a day was also giving it back, photo and all, to a pocket touch.
 * Holding opens that same sheet, where the camera and the note live — a hold
 * cannot open the camera directly, because the browser's user activation does
 * not survive the press timer on iOS, and a camera button that silently does
 * nothing would be worse than one extra tap.
 */
new class extends Component
{
    use PhotoValidationRules;
    use WithFileUploads;

    #[Locked]
    public Goal $goal;

    /** The day this card marks: today, or yesterday inside the grace window. */
    #[Locked]
    public string $date;

    /**
     * How the card is drawn: a `tile` in the grid, a full-width `row` when
     * there are too few goals to fill one, a compact `chip`, or a row
     * inside yesterday's `reminder`. Presentation only. Every variant marks
     * the same way.
     */
    #[Locked]
    public string $variant = 'tile';

    /** @var TemporaryUploadedFile|null */
    public $photo;

    public string $note = '';

    public bool $dismissed = false;

    public bool $showDismissUndo = false;

    public bool $showMarkUndo = false;

    public function mount(Goal $goal, ?string $date = null, string $variant = 'tile'): void
    {
        abort_unless($goal->user_id === Auth::id(), 403);

        $this->goal = $goal;
        $this->variant = $variant;
        $this->date = $date ?? $goal->user->clock()->today()->toDateString();
        $this->dismissed = $variant === 'reminder'
            && (bool) session()->get($this->dismissalKey(), false);
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
            $this->history->pauses,
        );
    }

    #[Computed]
    public function requiresPhoto(): bool
    {
        return resolve(PhotoRule::class)->requiresPhoto(
            $this->history->recentFullnessBefore($this->date),
        );
    }

    #[Computed]
    public function photoLinks(): ?PhotoLinks
    {
        return $this->mark?->photoLinks();
    }

    /** Unique page-wide: the same goal can be on screen for two days at once. */
    #[Computed]
    public function sheetName(): string
    {
        return "sheet-{$this->goal->id}-{$this->date}";
    }

    /**
     * A single tap: mark the day, or open the sheet on one already marked.
     *
     * The tap answers the card the finger saw. Livewire queues a call made
     * while a request is in flight, so an impatient double tap arrives as two
     * presses, and the second one would land on a card that has already
     * changed under it.
     */
    public function press(bool $marked): void
    {
        if ($marked !== ($this->mark !== null)) {
            return;
        }

        // Both of the taps that do not mark end up in the same place. A day
        // already marked goes there because nothing destructive rides on a
        // tap — un-marking is a labelled button in the sheet, and a mistap
        // costs a second one rather than the day. Two ghost marks in a row
        // go there because the third tap owes a photo, and the camera is in
        // there too.
        if ($marked || $this->requiresPhoto) {
            $this->modal($this->sheetName)->show();

            return;
        }

        $this->store();
    }

    public function save(): void
    {
        $mark = $this->mark;

        $this->validate([
            'photo' => [$mark === null ? 'nullable' : 'required', ...$this->photoRules()],
            'note' => ['nullable', 'string', 'max:'.config('logralo.goals.note_max_length')],
        ]);

        if ($mark !== null) {
            $this->attachPhoto($mark);

            return;
        }

        $this->store();
    }

    public function dismissReminder(): void
    {
        abort_unless($this->variant === 'reminder', 404);

        session()->put($this->dismissalKey(), true);

        $this->dismissed = true;
        $this->showDismissUndo = true;
    }

    public function restoreReminder(): void
    {
        abort_unless($this->variant === 'reminder', 404);

        session()->forget($this->dismissalKey());

        $this->dismissed = false;
        $this->showDismissUndo = false;
    }

    public function finishDismissal(): void
    {
        abort_unless($this->variant === 'reminder', 404);

        $this->showDismissUndo = false;
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

        $this->showMarkUndo = false;
        $this->after();
    }

    private function attachPhoto(Mark $mark): void
    {
        $photo = $this->photo;

        if (! $photo instanceof TemporaryUploadedFile) {
            return;
        }

        try {
            resolve(AttachPhotoToMark::class)->handle($mark, $photo);
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

        $this->showMarkUndo = $this->variant === 'reminder';
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
        $history = resolve(GoalHistory::class)->for($this->goal);

        $streak = resolve(StreakCalculator::class)->endingOn(
            $history->dates(),
            $mark->marked_on,
            $history->pauses,
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
        if ($this->variant === 'reminder') {
            $this->dispatch('grace-mark-updated', goalId: $this->goal->id);

            return;
        }

        $this->dispatch('mark-updated');
    }

    private function dismissalKey(): string
    {
        return "grace-reminders.{$this->goal->user_id}.{$this->date}.{$this->goal->id}";
    }
};

?>

@php
    $mark = $this->mark;
    $isGhost = $mark?->isGhost() ?? false;
    $isFull = $mark?->isFull() ?? false;
    // Both the row and the tile say these two things about a goal, so they are
    // decided once here rather than written out twice below, where the two
    // copies could drift into disagreeing about the same goal.
    $dimFlame = $mark === null && ! $isFull;
    $owesPhoto = $this->requiresPhoto && $mark === null;
    // The two cards whose activation opens the sheet rather than marking. Said
    // out loud below, because `aria-pressed` alone promises a toggle and a
    // screen reader would meet the sheet with no warning it was coming.
    $opensSheet = $mark !== null || $owesPhoto;

    $shell = match ($variant) {
        'chip' => 'flex items-center gap-2 rounded-full border py-1.5 pr-3 pl-2.5 text-sm transition',
        'row' => 'relative flex items-center gap-3 overflow-hidden rounded-2xl border p-2.5 transition-transform duration-150',
        default => 'relative flex aspect-square w-full flex-col justify-between overflow-hidden rounded-2xl border p-3 text-left transition-transform duration-150',
    };

    // A grace chip is amber because the day it marks is running out. The cards
    // on today's own goals carry the mark's own state instead.
    $tone = match (true) {
        $variant === 'chip' && $mark === null => 'border-amber-500/40 bg-amber-500/10',
        $variant === 'chip' => 'border-transparent bg-accent/15 text-accent-content',
        $isFull => 'border-transparent bg-zinc-900 text-white',
        $isGhost => 'border-dashed border-ghost/60 bg-ghost/5',
        default => 'border-zinc-200 bg-white dark:border-white/10 dark:bg-white/5',
    };

    // The chip is small enough that it needs the deeper of the two nudges to
    // register as a press at all.
    $pressScale = $variant === 'chip' ? 'scale-95' : 'scale-[0.97]';
    $testId = match ($variant) {
        'chip' => 'grace-goal-'.$goal->id,
        'reminder' => 'grace-reminder-'.$goal->id,
        default => 'goal-card-'.$goal->id,
    };
@endphp

{{-- One interaction wrapper for the goal-card variants: tap marks, hold opens the
     sheet, and only what sits inside changes. Every variant is a div with
     `role="button"` rather than a `<button>`, because `short-press` rides on
     pointerup, which a keyboard never sends — the keydown handlers below are
     what makes Enter and Space work, and on a real button they would
     double-fire against the click the browser synthesises.

     Nothing here reaches `press()` without a pointer or a key, so the guarded
     click covers what neither sends: VoiceOver activates a role="button" by
     dispatching a click on its own, and `onClick` only ever suppresses the
     click a long press leaves behind. `detail` is 0 exactly when no pointer
     drew the click, so a tap — which already marked on pointerup — cannot
     come back through here a second time.

     `press()` is the card's own, not `$wire.press()`: it reads the
     `aria-pressed` below off this element and sends it along, which is what
     lets the second half of a double tap be dropped instead of acted on after
     the first has already changed the card. Every variant hangs it on the same
     root, so all three get that for free. --}}
<div wire:key="goal-card-{{ $goal->id }}-{{ $date }}">
    @if ($variant === 'reminder')
        @if ($dismissed)
            @if ($showDismissUndo)
                <div
                    x-init="setTimeout(() => $wire.finishDismissal(), 8000)"
                    class="flex items-center justify-between px-4 py-3 text-sm"
                    role="status"
                    aria-live="polite"
                    data-grace-row
                    data-test="grace-dismissed-{{ $goal->id }}"
                >
                    <span class="text-zinc-600 dark:text-zinc-300">Recordatorio ocultado</span>

                    <button
                        type="button"
                        wire:click="restoreReminder"
                        class="rounded-lg px-2 py-1 font-semibold text-accent-content transition hover:bg-accent/10 focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:outline-none dark:focus-visible:ring-offset-zinc-950"
                        data-test="grace-restore-{{ $goal->id }}"
                    >
                        Deshacer
                    </button>
                </div>
            @endif
        @else
            <article
                class="px-4 py-3"
                aria-live="polite"
                data-grace-row
                data-test="{{ $testId }}"
            >
                @if ($mark === null)
                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2 sm:grid-cols-[auto_minmax(0,1fr)_auto]">
                        <span class="row-span-2 grid size-11 shrink-0 place-items-center rounded-xl bg-zinc-100 text-2xl leading-none sm:row-span-1 dark:bg-white/5">
                            {{ $goal->emoji }}
                        </span>

                        <p class="min-w-0 flex-1 truncate text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ $goal->name }}
                        </p>

                        <div class="col-start-2 flex shrink-0 items-center gap-1 sm:col-start-3 sm:row-start-1 sm:ml-auto">
                            <flux:button
                                wire:click="press(false)"
                                wire:loading.attr="disabled"
                                wire:target="press"
                                variant="primary"
                                size="sm"
                                :icon="$owesPhoto ? 'camera' : 'check'"
                                data-test="grace-complete-{{ $goal->id }}"
                            >
                                {{ $owesPhoto ? 'Completar con foto' : 'Sí, lo hice' }}
                            </flux:button>

                            <flux:button
                                wire:click="dismissReminder"
                                variant="subtle"
                                size="sm"
                                data-test="grace-dismiss-{{ $goal->id }}"
                            >
                                Descartar
                            </flux:button>
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <flux:icon name="check" variant="solid" class="size-5" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                ¡Anotado! {{ $goal->name }}
                            </p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $isFull ? 'Quedó guardado con foto.' : 'Si querés, todavía podés sumar una foto.' }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-2 flex min-h-8 items-center gap-1 pl-14">
                        @if ($isGhost)
                            <flux:button
                                type="button"
                                x-on:click="$flux.modal('{{ $this->sheetName }}').show()"
                                variant="subtle"
                                size="sm"
                                icon="camera"
                                data-test="grace-add-photo-{{ $goal->id }}"
                            >
                                Agregar foto
                            </flux:button>
                        @endif

                        @if ($showMarkUndo)
                            <button
                                type="button"
                                x-data="{ visible: true }"
                                x-init="setTimeout(() => visible = false, 8000)"
                                x-show="visible"
                                x-transition.opacity.duration.150ms
                                wire:click="remove"
                                class="rounded-lg px-2 py-1.5 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-800 focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:outline-none dark:hover:bg-white/10 dark:hover:text-zinc-100 dark:focus-visible:ring-offset-zinc-950"
                                data-test="grace-undo-{{ $goal->id }}"
                            >
                                Deshacer
                            </button>
                        @endif

                        <flux:button
                            wire:click="dismissReminder"
                            variant="subtle"
                            size="sm"
                            class="ml-auto"
                        >
                            Descartar
                        </flux:button>
                    </div>
                @endif
            </article>
        @endif
    @else
        <div
            x-data="goalCard({ delay: 420 })"
            @pointerdown="start($event)"
            @pointermove="move($event)"
            @pointerup="end()"
            @pointercancel="cancel()"
            @lostpointercapture="cancel()"
            @contextmenu.prevent
            @click.capture="onClick($event)"
            @short-press="press()"
            @long-press="$flux.modal('{{ $this->sheetName }}').show()"
            @keydown.enter.prevent="press()"
            @keydown.space.prevent="press()"
            @click="$event.detail === 0 && press()"
            class="tap-target {{ $shell }} {{ $tone }}"
            :class="pressing && '{{ $pressScale }}'"
            role="button"
            tabindex="0"
            aria-pressed="{{ $mark !== null ? 'true' : 'false' }}"
            aria-haspopup="{{ $opensSheet ? 'dialog' : 'false' }}"
            aria-label="{{ $goal->name }}"
            data-test="{{ $testId }}"
        >
        @if ($variant === 'chip')
            <span class="text-base leading-none">{{ $goal->emoji }}</span>
            <span class="max-w-28 truncate font-medium">{{ $goal->name }}</span>

            @if ($mark !== null)
                <flux:icon name="check-circle" variant="micro" />
            @endif
        @elseif ($variant === 'row')
            {{-- The proof leads the row when there is one: at this size the
                 photo is the thing worth showing, and the emoji is only
                 standing in for it the rest of the time. --}}
            @if ($isFull && $this->photoLinks !== null)
                {{-- No `eager` here, unlike the tile: that one is the screen's
                     largest image and worth a synchronous decode, while this is
                     a 48px box that has no business blocking a paint. --}}
                <div class="relative size-12 shrink-0 overflow-hidden rounded-xl">
                    <x-photo :links="$this->photoLinks" :alt="$goal->name" fill sizes="64px" />
                </div>
            @else
                <span
                    @class([
                        'grid size-12 shrink-0 place-items-center rounded-xl text-2xl leading-none',
                        'bg-zinc-100 dark:bg-white/5' => $mark === null,
                        'bg-ghost/10 opacity-50 grayscale' => $isGhost,
                        'bg-white/10' => $isFull,
                    ])
                >{{ $goal->emoji }}</span>
            @endif

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ $goal->name }}</p>

                <div class="mt-1 flex items-center gap-2">
                    <x-flame :days="$this->streak" :dim="$dimFlame" size="sm" />

                    @if ($owesPhoto)
                        <span class="text-xs" title="Esta va con foto">📸</span>
                    @endif

                    @if ($goal->isPrivate())
                        <span class="text-xs opacity-60" title="Privado" role="img" aria-label="Privado">🔒</span>
                    @endif
                </div>
            </div>

            <div class="shrink-0 pr-1">
                <x-mark-status :ghost="$isGhost" :full="$isFull" size="lg" ring />
            </div>
        @else
            @if ($isFull && $this->photoLinks !== null)
                <x-photo :links="$this->photoLinks" :alt="$goal->name" fill eager />
                <div class="absolute inset-0 bg-linear-to-t from-black/80 via-black/20 to-black/10"></div>
            @else
                {{-- The streak, drawn as fuel: the ember rises off the card's
                     base as the run grows, so the tile's middle carries the
                     number the flame is already saying. It tops out at a month,
                     so week two still reads as progress rather than as an ember
                     that is full forever. --}}
                @php($fuel = number_format(min($this->streak / 30, 1), 3, '.', ''))

                <div
                    class="ember-gauge pointer-events-none absolute inset-0"
                    style="--fuel: {{ $fuel }}"
                    aria-hidden="true"
                ></div>
            @endif

            <div class="relative flex items-start justify-between">
                <span class="text-4xl leading-none {{ $isGhost ? 'opacity-50 grayscale' : '' }}">
                    {{ $goal->emoji }}
                </span>

                <x-mark-status :ghost="$isGhost" :full="$isFull" />
            </div>

            <div class="relative">
                <p class="line-clamp-2 text-sm leading-tight font-semibold">{{ $goal->name }}</p>

                <div class="mt-1.5 flex items-center justify-between">
                    <x-flame :days="$this->streak" :dim="$dimFlame" size="sm" />

                    <div class="flex items-center gap-1.5">
                        @if ($owesPhoto)
                            <span class="text-xs" title="Esta va con foto">📸</span>
                        @endif

                        @if ($goal->isPrivate())
                            <span class="text-xs opacity-60" title="Privado" role="img" aria-label="Privado">🔒</span>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
    @endif

    {{-- The sheet: camera, note, and the way out of a mistap. --}}
    <x-sheet :name="$this->sheetName">
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

        @if ($mark === null || $isGhost)
            {{-- The camera original is resized and re-encoded in the browser
                 before it uploads, so `wire:model` is out and `photoPicker`
                 does the upload by hand. --}}
            <div
                class="mt-5 flex flex-col gap-4"
                x-data="photoPicker()"
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

                {{-- Disabled while busy because the overlay below is inside
                     this button: without it a tap on the spinner reopens the
                     camera and races a second upload against the first. --}}
                <button
                    type="button"
                    @click="open()"
                    x-bind:disabled="busy"
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

                @if ($mark === null)
                    <flux:input
                        wire:model="note"
                        placeholder="Una línea, si querés"
                        maxlength="{{ config('logralo.goals.note_max_length') }}"
                        data-test="note"
                    />
                @endif

                {{-- Saving mid-upload would mark the day without the photo the
                     member is watching upload, so the button waits for it.

                     Both bindings are load-bearing — the Flux prop paints the
                     button before Alpine is up, and the `x-bind` governs it
                     afterwards — but the rule they share is written once, or
                     the two would disagree with nothing to notice. --}}
                @php($photoStillOwed = ($mark !== null || $this->requiresPhoto) && $photo === null)

                <flux:button
                    wire:click="save"
                    variant="primary"
                    class="w-full"
                    :disabled="$photoStillOwed"
                    x-bind:disabled="busy || @js($photoStillOwed)"
                    data-test="save"
                >
                    @if ($mark !== null)
                        Agregar foto
                    @else
                        {{ $photo !== null ? 'Marcar con foto' : 'Marcar' }}
                    @endif
                </flux:button>

                @if ($mark !== null)
                    <flux:button wire:click="remove" variant="danger" class="w-full" data-test="remove">
                        Quitar marca
                    </flux:button>

                    <flux:text size="sm" class="text-center">
                        Podés quitarla mientras el día siga abierto.
                    </flux:text>
                @endif
            </div>
        @else
            {{-- The only way to un-mark: a tap on the card opens this instead
                 of taking the day back where the finger landed. --}}
            <div class="mt-5 flex flex-col gap-4">
                @if ($mark->note !== null)
                    <flux:text>{{ $mark->note }}</flux:text>
                @endif

                <flux:button wire:click="remove" variant="danger" class="w-full" data-test="remove">
                    Quitar marca
                </flux:button>

                <flux:text size="sm" class="text-center">
                    @if ($isFull)
                        La foto se va con ella.
                    @endif
                    Podés quitarla mientras el día siga abierto.
                </flux:text>
            </div>
        @endif
    </x-sheet>
</div>
