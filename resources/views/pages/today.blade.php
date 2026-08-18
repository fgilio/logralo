<?php

declare(strict_types=1);

use App\Concerns\InteractsWithMember;
use App\Models\Goal;
use App\Queries\GroupPulse;
use App\Queries\MonthlyStandings;
use App\ValueObjects\PulseEntry;
use App\ValueObjects\Standing;
use App\ValueObjects\UserClock;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Hoy" — the only screen.
 *
 * The group's pulse, yesterday's grace window, today's goals, and everyone's
 * proof underneath. No tabs, no menus: goal management, timezone and logout
 * live behind the avatar.
 */
new #[Title('Hoy')] class extends Component
{
    use InteractsWithMember;

    #[Computed]
    public function clock(): UserClock
    {
        return $this->member()->clock();
    }

    /** @return EloquentCollection<int, Goal> */
    #[Computed]
    public function goals(): EloquentCollection
    {
        return $this->member()->activeGoals()->get();
    }

    /** @return EloquentCollection<int, Goal> */
    #[Computed]
    public function graceGoals(): EloquentCollection
    {
        if (! $this->clock->isGraceOpen()) {
            return new EloquentCollection;
        }

        return $this->goals;
    }

    /** @return Collection<int, PulseEntry> */
    #[Computed]
    public function pulse(): Collection
    {
        return resolve(GroupPulse::class)->today();
    }

    /** @return Collection<int, Standing> */
    #[Computed]
    public function standings(): Collection
    {
        return resolve(MonthlyStandings::class)->current();
    }

    #[On('mark-updated')]
    public function reload(): void
    {
        unset($this->goals, $this->graceGoals, $this->pulse, $this->standings);
    }
};

?>

@php
    $user = $this->member();
    $today = $this->clock->today();
    $yesterday = $this->clock->yesterday();
    $graceEndsAt = $this->clock->closesAt($yesterday)->format('H:i');
    $month = Str::ucfirst($today->translatedFormat('F'));

    // How today's goals are drawn. The threshold is the grid's column count,
    // not a taste setting: at two or fewer the tiles cannot fill even one row,
    // and since they are sized for the five-goal case the screen reads as a
    // couple of mostly-empty boxes. Rows carry the same tap target and the same
    // streak in a third of the height. Moving `grid-cols-2` below moves this.
    $layout = $this->goals->count() > 2 ? 'tile' : 'row';
@endphp

<div
    x-data="pullToRefresh()"
    class="mx-auto min-h-dvh w-full max-w-lg px-4 pb-safe-fab"
    data-test="pull-to-refresh"
>
    {{-- Pull-to-refresh indicator, riding above the header. --}}
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-30 grid place-items-center"
        :style="`transform: translateY(${distance}px); opacity: ${progress}`"
        x-show="distance > 0 || refreshing"
        x-cloak
        data-test="pull-indicator"
    >
        <div class="mt-2 rounded-full bg-white/90 p-2 shadow-lg dark:bg-zinc-800/90">
            <flux:icon name="arrow-path" class="size-5" ::class="refreshing && 'animate-spin'" />
        </div>
    </div>

    <header class="sticky top-0 z-20 -mx-4 bg-zinc-100/85 px-4 pt-safe-t backdrop-blur-md dark:bg-zinc-950/85">
        <div class="flex items-center justify-between py-3">
            <div class="flex items-center gap-2">
                <x-brand-mark class="size-6" />
                <span class="font-display text-xl tracking-wide">Logralo</span>
            </div>

            <a
                href="{{ route('profile') }}"
                wire:navigate
                class="rounded-full"
                aria-label="Tu perfil"
                data-test="profile-link"
            >
                <x-avatar :user="$user" size="sm" />
            </a>
        </div>

        {{-- The pulse strip: everyone's day, at a glance. Tapping it opens the
             month's table. --}}
        <div class="flex items-center gap-1 pb-3">
            <div class="flex flex-1 gap-1 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($this->pulse as $entry)
                    <button
                        type="button"
                        x-on:click="$flux.modal('standings').show()"
                        class="tap-target"
                        data-test="pulse-{{ $entry->user->id }}"
                    >
                        <x-avatar-ring
                            :user="$entry->user"
                            :completion="$entry->completion()"
                            :complete="$entry->isComplete()"
                            :label="$entry->activeGoals > 0 ? $entry->markedToday . '/' . $entry->activeGoals : null"
                        />
                    </button>
                @endforeach
            </div>

            <flux:button
                variant="subtle"
                size="sm"
                square
                icon="trophy"
                aria-label="Tabla del mes"
                x-on:click="$flux.modal('standings').show()"
                data-test="open-standings"
            />
        </div>
    </header>

    {{-- Whatever a full page POST wants to say on its way back — revoking a
         shared link is the only one so far. Toasts need a Livewire component
         to dispatch onto, and a plain controller has none. --}}
    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-3" data-test="status">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->graceGoals->isNotEmpty())
        <flux:callout variant="warning" icon="clock" class="mt-3" data-test="grace-banner">
            {{-- Everything goes in the default slot: passing an x-slot:content
                 to flux:callout replaces the default slot rather than adding
                 to it, and the heading would disappear. --}}
            <flux:callout.heading>Ayer sigue abierto hasta las {{ $graceEndsAt }}</flux:callout.heading>

            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($this->graceGoals as $goal)
                    <livewire:goal-card
                        :goal="$goal"
                        :date="$yesterday->toDateString()"
                        variant="chip"
                        :wire:key="'grace-' . $goal->id"
                    />
                @endforeach
            </div>
        </flux:callout>
    @endif

    <section class="mt-4" aria-label="Tus objetivos de hoy">
        @if ($this->goals->isEmpty())
            <x-empty-state>
                <span class="text-4xl">🎯</span>
                <flux:heading size="lg" class="mt-3">Creá tu primer objetivo</flux:heading>
                <flux:text class="mt-1">Uno solo alcanza para arrancar. Después sumás hasta cinco.</flux:text>
                <flux:button
                    href="{{ route('profile') }}"
                    wire:navigate
                    variant="primary"
                    class="mt-5"
                    data-test="create-first-goal"
                >
                    Crear objetivo
                </flux:button>
            </x-empty-state>
        @else
            <div class="{{ $layout === 'tile' ? 'grid grid-cols-2 gap-3' : 'flex flex-col gap-2' }}">
                @foreach ($this->goals as $goal)
                    <livewire:goal-card
                        :goal="$goal"
                        :date="$today->toDateString()"
                        :variant="$layout"
                        :wire:key="'today-' . $goal->id"
                    />
                @endforeach
            </div>

            {{-- A footnote rather than a card of its own: adding a goal happens
                 a handful of times ever, and as a tile it held a slot of the
                 grid open every day for something nobody was about to tap. --}}
            @if ($this->goals->count() < config('logralo.goals.max_active'))
                <flux:button
                    href="{{ route('profile') }}"
                    wire:navigate
                    variant="subtle"
                    size="sm"
                    icon="plus"
                    class="mt-3"
                    data-test="add-goal"
                >
                    Agregar objetivo
                </flux:button>
            @endif
        @endif
    </section>

    <section class="mt-8" aria-label="Lo que hizo el grupo">
        <livewire:feed />
    </section>

    {{-- One per page, not one per goal card: it fires a handful of times a
         month and every card carrying its own would be twenty idle modals. --}}
    <livewire:milestone />


    {{-- The month's table. The split bars are the scoring rule, explained. --}}
    <x-sheet name="standings">
        <flux:heading size="lg" class="font-display tracking-wide">Tabla de {{ $month }}</flux:heading>
        <flux:text size="sm" class="mt-1">
            Sobre lo que cada uno podía marcar. Con foto vale 1, sin foto vale ½.
        </flux:text>

        <div class="mt-5 flex flex-col gap-1" data-test="standings">
            @forelse ($this->standings as $standing)
                <x-standing-row :standing="$standing" :highlight="$standing->userId === $user->id" />
            @empty
                <flux:text>Todavía nadie tiene objetivos activos.</flux:text>
            @endforelse
        </div>

        <div class="mt-5 flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="flex items-center gap-1.5">
                <span class="h-2 w-5 rounded-full bg-accent"></span> con foto
            </span>
            <span class="flex items-center gap-1.5">
                <span class="hatched h-2 w-5 rounded-full"></span> sin foto
            </span>
        </div>
    </x-sheet>
</div>
