{{-- What a page that sat open too long gets back.

     The app is a phone screen people leave open overnight, so the CSRF token
     behind it goes stale on the ordinary path rather than the exotic one: the
     login form, and the two full-page POSTs — logout and revoking a shared
     link. All three answered with the framework's bare English "419 | Page
     Expired", which reads as a crash rather than as "volvé a intentar".

     Same shape as the 404 next to it: the app's own voice, and one button
     back in. `url('/')` rather than a route, because the way back depends on
     whether the session survived — the gate sends a member to "Hoy" and
     everyone else to "Entrar". --}}
@component('layouts::auth', ['title' => 'Se venció la página'])
    <div class="rise text-center">
        <flux:heading size="xl" class="font-display tracking-wide">
            Se venció la página
        </flux:heading>

        <flux:text class="mt-2">
            Estuvo abierta demasiado tiempo y lo que mandaste no llegó. Volvé a abrirla y probá de nuevo.
        </flux:text>

        <flux:button href="{{ url('/') }}" variant="primary" class="mt-8 w-full" data-test="home">
            Volver a Logralo
        </flux:button>
    </div>
@endcomponent
