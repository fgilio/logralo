@component('layouts::auth', ['title' => 'Entrar'])
    {{-- A plain form, not a Livewire page: the signature covers the whole URL
         and would not survive Livewire's own round trips. --}}
    <div class="rise">
        <flux:heading size="xl" class="font-display tracking-wide">
            Hola, {{ Str::of($user->name)->explode(' ')->first() }}
        </flux:heading>
        <flux:text class="mt-2">
            Este enlace te deja entrar una sola vez. Después entrás con tu email y contraseña.
        </flux:text>

        {{-- Same URL as this page, so the signature it was opened with is the
             signature the POST is checked against. --}}
        <form method="POST" action="{{ request()->fullUrl() }}" class="mt-8">
            @csrf

            <flux:button type="submit" variant="primary" class="w-full" data-test="submit">
                Entrar como {{ $user->name }}
            </flux:button>
        </form>

        <flux:text size="sm" class="mt-6 text-center">
            ¿No sos vos?
            <flux:link href="{{ route('login') }}" wire:navigate>Entrá con tu cuenta</flux:link>
        </flux:text>
    </div>
@endcomponent
