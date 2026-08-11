<?php

declare(strict_types=1);

use App\Concerns\InteractsWithMember;
use App\Concerns\PasswordValidationRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Elegí tu contraseña')] class extends Component
{
    use InteractsWithMember;
    use PasswordValidationRules;
    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        if ($this->member()->hasPassword()) {
            $this->redirectRoute('today', navigate: true);
        }
    }

    public function createPassword(): void
    {
        try {
            $validated = $this->validate(['password' => $this->passwordRules()]);
        } catch (ValidationException $validationException) {
            // Livewire keeps component state across a failed request, and a
            // plaintext password has no business riding along in the next one.
            $this->reset('password', 'password_confirmation');

            throw $validationException;
        }

        $this->member()->forceFill([
            'password' => $validated['password'],
            'password_set_at' => CarbonImmutable::now(),
            'remember_token' => Str::random(60),
        ])->save();

        $this->reset('password', 'password_confirmation');

        session()->regenerate();

        $this->redirectIntended(default: route('today', absolute: false), navigate: true);
    }
};

?>

<div class="rise">
    <flux:heading size="xl" class="font-display tracking-wide">
        Bienvenido, {{ Str::of($this->member()->name)->explode(' ')->first() }}
    </flux:heading>
    <flux:text class="mt-2">
        Elegí una contraseña para entrar desde ahora. El enlace de WhatsApp ya no sirve.
    </flux:text>

    <form wire:submit="createPassword" class="mt-8 flex flex-col gap-5">
        <flux:input
            wire:model="password"
            label="Contraseña"
            type="password"
            autocomplete="new-password"
            passwordrules="{{ Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
            required
            autofocus
            data-test="password"
        />

        <flux:input
            wire:model="password_confirmation"
            label="Repetila"
            type="password"
            autocomplete="new-password"
            required
            data-test="password-confirmation"
        />

        <flux:button type="submit" variant="primary" class="w-full" data-test="submit">
            Guardar y entrar
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <flux:link as="button" type="submit" variant="subtle" class="text-sm">
            No soy yo, salir
        </flux:link>
    </form>
</div>
