<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Entrar')] class extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Always blamed on the email field, and always the same message,
            // so the form never reveals which accounts exist.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('today', absolute: false), navigate: true);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
};

?>

<div class="rise">
    <flux:heading size="xl" class="font-display tracking-wide">Volvé a la racha</flux:heading>
    <flux:text class="mt-2">Entrá con tu email y tu contraseña.</flux:text>

    @if (session('status'))
        <flux:callout variant="secondary" icon="information-circle" class="mt-6">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="login" class="mt-8 flex flex-col gap-5">
        <flux:input
            wire:model="email"
            label="Email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            autofocus
            data-test="email"
        />

        <flux:input
            wire:model="password"
            label="Contraseña"
            type="password"
            autocomplete="current-password"
            viewable
            required
            data-test="password"
        />

        <div class="flex items-center justify-between">
            <flux:checkbox wire:model="remember" label="No cerrar sesión" />

            <flux:link href="{{ route('password.request') }}" wire:navigate class="text-sm">
                Me la olvidé
            </flux:link>
        </div>

        <flux:button type="submit" variant="primary" class="w-full" data-test="submit">
            Entrar
        </flux:button>
    </form>
</div>
