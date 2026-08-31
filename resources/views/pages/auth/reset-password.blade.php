<?php

declare(strict_types=1);

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Nueva contraseña')] class extends Component
{
    use PasswordValidationRules;

    /** Locked, so a browser session cannot swap the token it was handed. */
    #[Locked]
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->string('email');
    }

    public function resetPassword(): void
    {
        try {
            $validated = $this->validate([
                'token' => ['required'],
                'email' => ['required', 'string', 'email'],
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $validationException) {
            // Livewire keeps component state across a failed request, and a
            // rejected plaintext password has no business riding along in it.
            $this->reset('password', 'password_confirmation');

            throw $validationException;
        }

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $this->password,
                'password_confirmation' => $this->password_confirmation,
                'token' => $this->token,
            ],
            function (User $user): void {
                $user->forceFill([
                    'password' => $this->password,
                    'password_set_at' => CarbonImmutable::now(),
                    // Kills every surviving "no cerrar sesión" cookie.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        $this->reset('password', 'password_confirmation');

        if ($status !== Password::PasswordReset) {
            // One answer for both rejections. `passwords.user` would say
            // whether an address belongs to the group, and no screen here does.
            $this->addError('email', __(Password::InvalidToken));

            return;
        }

        session()->flash('status', 'Listo, ya podés entrar con la nueva.');

        $this->redirectRoute('login', navigate: true);
    }
};

?>

<div class="rise">
    <flux:heading size="xl" class="font-display tracking-wide">Nueva contraseña</flux:heading>

    <form wire:submit="resetPassword" class="mt-8 flex flex-col gap-5">
        <flux:input
            wire:model="email"
            label="Email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            data-test="email"
        />

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
            Guardar
        </flux:button>
    </form>
</div>
