<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Recuperar contraseña')] class extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    public function sendResetLink(): void
    {
        $this->validate();

        // The broker's answer is deliberately thrown away and the message is
        // always the same, so the form cannot be used to find out who is a
        // member. The broker is timeboxed internally for the same reason.
        Password::sendResetLink(['email' => $this->email]);

        session()->flash('status', 'Si ese email es de alguien del grupo, le llega un enlace.');
    }
};

?>

<div class="rise">
    <flux:heading size="xl" class="font-display tracking-wide">Recuperar contraseña</flux:heading>
    <flux:text class="mt-2">Te mandamos un enlace para elegir una nueva.</flux:text>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" class="mt-6">
            <flux:callout.text>{{ session('status') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="sendResetLink" class="mt-8 flex flex-col gap-5">
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

        <flux:button type="submit" variant="primary" class="w-full" data-test="submit">
            Mandar enlace
        </flux:button>
    </form>

    <div class="mt-6 text-center">
        <flux:link href="{{ route('login') }}" wire:navigate class="text-sm">
            Volver a entrar
        </flux:link>
    </div>
</div>
