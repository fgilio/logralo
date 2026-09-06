{{-- Where a link that misses lands.

     The share URL is the only address the group hands to people outside the
     app, and it stops working on purpose: revoking clears the token, and a
     paste out of WhatsApp can arrive cut short. Both answer 404 — the same
     answer, so neither confirms the token was ever real — and until now that
     404 was the framework's bare English page, on the one screen a friend who
     has never seen Logralo is most likely to meet it on.

     It is the whole app's 404 rather than the share page's, though, and a
     mistyped address or a stale bookmark lands here too. Only a share can have
     been taken down by somebody, so only a share is told so: everyone else was
     sent looking for a person who never sent them anything. --}}
@php($shared = request()->is('l/*'))

@component('layouts::auth', ['title' => 'Acá no hay nada'])
    <div class="rise text-center">
        <flux:heading size="xl" class="font-display tracking-wide">
            Acá no hay nada
        </flux:heading>

        <flux:text class="mt-2">
            @if ($shared)
                El enlace puede estar mal copiado, o quien lo compartió puede haberlo dado de baja.
            @else
                El enlace puede estar mal copiado, o la dirección ya no existe.
            @endif
        </flux:text>

        <flux:button href="{{ url('/') }}" variant="primary" class="mt-8 w-full" data-test="home">
            Ir a Logralo
        </flux:button>
    </div>
@endcomponent
