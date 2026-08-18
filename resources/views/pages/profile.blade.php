<?php

declare(strict_types=1);

use App\Actions\ArchiveGoal;
use App\Actions\CreateGoal;
use App\Actions\RemoveAvatar;
use App\Actions\RenameGoal;
use App\Actions\RestoreGoal;
use App\Actions\UpdateAvatar;
use App\Concerns\InteractsWithMember;
use App\Concerns\PasswordValidationRules;
use App\Concerns\PhotoValidationRules;
use App\Enums\GoalVisibility;
use App\Exceptions\UserFacingException;
use App\Models\Goal;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Everything that is not "Hoy": goals, timezone, password, logout.
 */
new #[Title('Perfil')] class extends Component
{
    use InteractsWithMember;
    use PasswordValidationRules;
    use PhotoValidationRules;
    use WithFileUploads;

    /** A few starters, so nobody has to hunt for the emoji keyboard. */
    public const array EMOJI_SUGGESTIONS = ['🏋️', '🏃', '📚', '🧘', '💧', '🥗', '🛏️', '🎸', '🧠', '🚭'];

    /** @var TemporaryUploadedFile|null */
    public $avatar;

    public string $name = '';

    public string $timezone = '';

    public string $goalEmoji = '🎯';

    public string $goalName = '';

    public bool $goalPrivate = false;

    public ?string $editingGoalId = null;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = $this->member();

        $this->name = $user->name;
        $this->timezone = $user->timezone;
    }

    /** @return EloquentCollection<int, Goal> */
    #[Computed]
    public function activeGoals(): EloquentCollection
    {
        return $this->member()->activeGoals()->get();
    }

    /** @return EloquentCollection<int, Goal> */
    #[Computed]
    public function archivedGoals(): EloquentCollection
    {
        return $this->member()->goals()->whereNotNull('archived_at')->get();
    }

    /** @return list<string> */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * The picker uploads and this applies it, with no Guardar in between: a
     * profile picture has nothing else to fill in alongside it, and a photo
     * sitting in a temp file behind a button nobody pressed is a photo the
     * member believes they changed.
     */
    public function saveAvatar(): void
    {
        $photo = $this->avatar;

        // The picker calls this itself once the bytes are up, so an empty
        // property is a call that raced the upload rather than a member with
        // something to correct.
        if (! $photo instanceof UploadedFile) {
            return;
        }

        $this->validate(['avatar' => $this->photoRules()]);

        try {
            resolve(UpdateAvatar::class)->handle($this->member(), $photo);
        } catch (UserFacingException $userFacingException) {
            Flux::toast(text: $userFacingException->userMessage(), variant: 'warning');

            return;
        } finally {
            $this->reset('avatar');
        }

        Flux::toast(text: 'Foto actualizada', variant: 'success');
    }

    public function removeAvatar(): void
    {
        resolve(RemoveAvatar::class)->handle($this->member());

        $this->reset('avatar');
    }

    public function saveProfile(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'timezone' => ['required', 'string', Rule::in($this->timezones)],
        ]);

        $this->member()->update($validated);

        Flux::toast(text: 'Perfil actualizado', variant: 'success');
    }

    public function editGoal(string $goalId): void
    {
        $goal = $this->ownedGoal($goalId);

        $this->editingGoalId = $goal->id;
        $this->goalEmoji = $goal->emoji;
        $this->goalName = $goal->name;
        $this->goalPrivate = $goal->isPrivate();

        $this->modal('goal-form')->show();
    }

    public function newGoal(): void
    {
        $this->reset('editingGoalId', 'goalEmoji', 'goalName', 'goalPrivate');
        $this->resetValidation();

        $this->modal('goal-form')->show();
    }

    public function saveGoal(): void
    {
        $validated = $this->validate([
            'goalEmoji' => ['required', 'string', 'max:8'],
            'goalName' => ['required', 'string', 'max:'.config('logralo.goals.name_max_length')],
            'goalPrivate' => ['boolean'],
        ]);

        $visibility = $this->goalPrivate ? GoalVisibility::Private : GoalVisibility::Group;

        try {
            if ($this->editingGoalId !== null) {
                resolve(RenameGoal::class)->handle(
                    $this->ownedGoal($this->editingGoalId),
                    $validated['goalEmoji'],
                    $validated['goalName'],
                    $visibility,
                );
            } else {
                resolve(CreateGoal::class)->handle(
                    $this->member(),
                    $validated['goalEmoji'],
                    $validated['goalName'],
                    $visibility,
                );
            }
        } catch (UserFacingException $userFacingException) {
            Flux::toast(text: $userFacingException->userMessage(), variant: 'warning');

            return;
        }

        $this->reset('editingGoalId', 'goalEmoji', 'goalName', 'goalPrivate');
        unset($this->activeGoals, $this->archivedGoals);

        $this->modal('goal-form')->close();
    }

    public function archiveGoal(string $goalId): void
    {
        resolve(ArchiveGoal::class)->handle($this->ownedGoal($goalId));

        unset($this->activeGoals, $this->archivedGoals);

        Flux::toast(text: 'Objetivo archivado. La historia queda.', variant: 'success');
    }

    public function restoreGoal(string $goalId): void
    {
        try {
            resolve(RestoreGoal::class)->handle($this->ownedGoal($goalId));
        } catch (UserFacingException $userFacingException) {
            Flux::toast(text: $userFacingException->userMessage(), variant: 'warning');

            return;
        }

        unset($this->activeGoals, $this->archivedGoals);
    }

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $validationException) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $validationException;
        }

        $this->member()->forceFill([
            'password' => $validated['password'],
            'password_set_at' => CarbonImmutable::now(),
            'remember_token' => Str::random(60),
        ])->save();

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(text: 'Contraseña actualizada', variant: 'success');
    }

    private function ownedGoal(string $goalId): Goal
    {
        return $this->member()->goals()->findOrFail($goalId);
    }
};

?>

@php
    $user = $this->member();

    // "Do they have one of their own?" — asked once, so the buttons and the
    // helper text underneath them cannot disagree about the answer.
    $hasUpload = $user->avatar_key !== null;
@endphp

<div class="mx-auto min-h-dvh w-full max-w-lg px-4 pt-safe-t pb-safe-b">
    <header class="flex items-center gap-3 py-4">
        <flux:button
            href="{{ route('today') }}"
            wire:navigate
            variant="subtle"
            size="sm"
            square
            icon="chevron-left"
            aria-label="Volver"
            data-test="back"
        />
        <flux:heading size="xl" class="font-display tracking-wide">Perfil</flux:heading>
    </header>

    <div class="flex flex-col gap-8 pb-16">
        {{-- Goals --}}
        <section>
            <div class="flex items-center justify-between">
                <flux:heading>Objetivos</flux:heading>
                <flux:text size="sm">{{ $this->activeGoals->count() }}/{{ config('logralo.goals.max_active') }}</flux:text>
            </div>

            <div class="mt-3 flex flex-col gap-2">
                @foreach ($this->activeGoals as $goal)
                    <div
                        class="flex items-center gap-3 rounded-xl bg-white p-3 ring-1 ring-zinc-200/80 dark:bg-white/5 dark:ring-white/10"
                        wire:key="goal-{{ $goal->id }}"
                    >
                        <span class="text-2xl leading-none">{{ $goal->emoji }}</span>
                        <span class="min-w-0 flex-1 truncate font-medium">{{ $goal->name }}</span>

                        @if ($goal->isPrivate())
                            <flux:icon
                                name="lock-closed"
                                variant="micro"
                                class="shrink-0 text-zinc-400 dark:text-zinc-500"
                                aria-label="Privado"
                                data-test="private-goal-{{ $goal->id }}"
                            />
                        @endif

                        <flux:button
                            wire:click="editGoal('{{ $goal->id }}')"
                            variant="subtle"
                            size="sm"
                            square
                            icon="pencil"
                            aria-label="Renombrar"
                            data-test="edit-goal-{{ $goal->id }}"
                        />
                        <flux:button
                            wire:click="archiveGoal('{{ $goal->id }}')"
                            wire:confirm="¿Archivar {{ $goal->name }}? Deja de contar desde hoy."
                            variant="subtle"
                            size="sm"
                            square
                            icon="archive-box"
                            aria-label="Archivar"
                            data-test="archive-goal-{{ $goal->id }}"
                        />
                    </div>
                @endforeach

                @if ($this->activeGoals->count() < config('logralo.goals.max_active'))
                    <flux:button
                        wire:click="newGoal"
                        variant="filled"
                        icon="plus"
                        class="w-full"
                        data-test="new-goal"
                    >
                        Nuevo objetivo
                    </flux:button>
                @endif
            </div>

            @if ($this->archivedGoals->isNotEmpty())
                <flux:accordion class="mt-4">
                    <flux:accordion.item heading="Archivados ({{ $this->archivedGoals->count() }})">
                        <div class="flex flex-col gap-2">
                            @foreach ($this->archivedGoals as $goal)
                                <div class="flex items-center gap-3 py-1" wire:key="archived-{{ $goal->id }}">
                                    <span class="text-xl leading-none opacity-60">{{ $goal->emoji }}</span>
                                    <span class="min-w-0 flex-1 truncate text-sm opacity-70">{{ $goal->name }}</span>

                                    <flux:button
                                        wire:click="restoreGoal('{{ $goal->id }}')"
                                        variant="subtle"
                                        size="xs"
                                        data-test="restore-goal-{{ $goal->id }}"
                                    >
                                        Reactivar
                                    </flux:button>
                                </div>
                            @endforeach
                        </div>
                    </flux:accordion.item>
                </flux:accordion>
            @endif
        </section>

        {{-- Profile --}}
        <section>
            <flux:heading>Tus datos</flux:heading>

            {{-- The picture, outside the form below: it saves on its own the
                 moment the upload lands, and a Guardar that only covered half
                 the section would be worse than none. --}}
            <div
                class="mt-3 flex items-center gap-4"
                x-data="photoPicker({ property: 'avatar', then: 'saveAvatar' })"
            >
                {{-- No `capture`, unlike the goal card's camera: a profile
                     picture is usually one the phone already has. --}}
                <input
                    type="file"
                    accept="image/*"
                    @change="pick($event)"
                    x-ref="camera"
                    class="sr-only"
                    data-test="avatar-input"
                >

                <button
                    type="button"
                    @click="open()"
                    x-bind:disabled="busy"
                    class="tap-target relative rounded-full transition active:scale-95"
                    aria-label="Cambiar foto"
                    data-test="open-avatar-picker"
                >
                    <x-avatar :user="$user" size="xl" />

                    <div
                        x-show="busy"
                        x-cloak
                        class="absolute inset-0 grid place-items-center rounded-full bg-zinc-900/60 text-white"
                    >
                        <flux:icon name="loading" class="size-5 animate-spin" />
                    </div>
                </button>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap gap-2">
                        <flux:button
                            @click="open()"
                            x-bind:disabled="busy"
                            variant="filled"
                            size="sm"
                            icon="camera"
                            data-test="change-avatar"
                        >
                            {{ $hasUpload ? 'Cambiar' : 'Subir foto' }}
                        </flux:button>

                        @if ($hasUpload)
                            <flux:button
                                wire:click="removeAvatar"
                                variant="subtle"
                                size="sm"
                                data-test="remove-avatar"
                            >
                                Quitar
                            </flux:button>
                        @endif
                    </div>

                    <flux:text size="sm" class="mt-2">
                        @if ($hasUpload)
                            Si la quitás volvemos a tu Gravatar, y si no tenés, a tus iniciales.
                        @elseif ($user->gravatarUrl() !== null)
                            Mientras no subas ninguna usamos la de
                            <a href="https://gravatar.com" target="_blank" rel="noopener" class="underline">Gravatar</a>
                            de {{ $user->email }}.
                        @else
                            Mientras no subas ninguna van tus iniciales.
                        @endif
                    </flux:text>
                </div>
            </div>

            <flux:error name="avatar" class="mt-2" />

            <form wire:submit="saveProfile" class="mt-4 flex flex-col gap-4">
                <flux:input wire:model="name" label="Nombre" required data-test="name" />

                <flux:select wire:model="timezone" label="Zona horaria" data-test="timezone">
                    @foreach ($this->timezones as $timezone)
                        <flux:select.option value="{{ $timezone }}">{{ $timezone }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:text size="sm">
                    De acá salen tu día, la hora de gracia y el mes de la tabla.
                </flux:text>

                <flux:button type="submit" variant="primary" data-test="save-profile">Guardar</flux:button>
            </form>
        </section>

        {{-- Appearance: entirely client side, nothing stored. --}}
        <section>
            <flux:heading>Tema</flux:heading>

            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="mt-3">
                <flux:radio value="light" icon="sun">Claro</flux:radio>
                <flux:radio value="dark" icon="moon">Oscuro</flux:radio>
                <flux:radio value="system" icon="computer-desktop">Sistema</flux:radio>
            </flux:radio.group>
        </section>

        {{-- Password --}}
        <section>
            <flux:heading>Contraseña</flux:heading>

            <form wire:submit="updatePassword" class="mt-3 flex flex-col gap-4">
                <flux:input
                    wire:model="current_password"
                    label="Actual"
                    type="password"
                    autocomplete="current-password"
                    data-test="current-password"
                />
                <flux:input
                    wire:model="password"
                    label="Nueva"
                    type="password"
                    autocomplete="new-password"
                    passwordrules="{{ Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                    data-test="new-password"
                />
                <flux:input
                    wire:model="password_confirmation"
                    label="Repetila"
                    type="password"
                    autocomplete="new-password"
                    data-test="new-password-confirmation"
                />

                <flux:button type="submit" data-test="save-password">Cambiar contraseña</flux:button>
            </form>
        </section>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:button type="submit" variant="subtle" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout">
                Cerrar sesión
            </flux:button>
        </form>
    </div>

    {{-- Create / rename --}}
    <x-sheet name="goal-form">
        <flux:heading size="lg">
            {{ $editingGoalId !== null ? 'Editar objetivo' : 'Nuevo objetivo' }}
        </flux:heading>

        <form wire:submit="saveGoal" class="mt-5 flex flex-col gap-4">
            {{-- Flux copies `class` onto its own `w-full` wrapper, so the widths live on these divs instead. --}}
            <div class="flex gap-3">
                <div class="w-20 shrink-0">
                    <flux:input
                        wire:model="goalEmoji"
                        class:input="text-center text-2xl"
                        maxlength="8"
                        aria-label="Emoji"
                        data-test="goal-emoji"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:input
                        wire:model="goalName"
                        placeholder="Gimnasio"
                        maxlength="{{ config('logralo.goals.name_max_length') }}"
                        aria-label="Nombre"
                        data-test="goal-name"
                    />
                </div>
            </div>

            <div class="flex flex-wrap gap-1.5">
                @foreach ($this::EMOJI_SUGGESTIONS as $suggestion)
                    {{-- Client-side: it fills a field the member is about to
                         submit anyway, so a server round trip per tap buys
                         nothing. --}}
                    <button
                        type="button"
                        x-on:click="$wire.goalEmoji = '{{ $suggestion }}'"
                        class="tap-target grid size-10 place-items-center rounded-lg text-xl ring-1 ring-zinc-200 transition active:scale-95 dark:ring-white/10"
                    >
                        {{ $suggestion }}
                    </button>
                @endforeach
            </div>

            <flux:switch
                wire:model="goalPrivate"
                label="Privado"
                description="Solo lo ves vos. No aparece en el feed del grupo ni cuenta para el anillo o la tabla del mes."
                data-test="goal-private"
            />

            <flux:error name="goalName" />
            <flux:error name="goalEmoji" />

            <flux:button type="submit" variant="primary" class="w-full" data-test="save-goal">
                Guardar
            </flux:button>
        </form>
    </x-sheet>
</div>
