{{-- Where a link that misses lands.

     The share URL is the only address the group hands to people outside the
     app, and it stops working on purpose: revoking clears the token, and a
     paste out of WhatsApp can arrive cut short. Both answer 404 — the same
     answer, so neither confirms the token was ever real — and until now that
     404 was the framework's bare English page, on the one screen a friend who
     has never seen Logralo is most likely to meet it on. --}}
@component('layouts::auth', ['title' => 'Acá no hay nada'])
    <div class="rise text-center">
        <flux:heading size="xl" class="font-display tracking-wide">
            Acá no hay nada
        </flux:heading>

        <flux:text class="mt-2">
            El enlace puede estar mal copiado, o quien lo compartió puede haberlo dado de baja.
        </flux:text>

        <flux:button href="{{ url('/') }}" variant="primary" class="mt-8 w-full" data-test="home">
            Ir a Logralo
        </flux:button>
    </div>
@endcomponent
