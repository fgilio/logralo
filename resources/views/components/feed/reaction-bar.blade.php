@props(['mark', 'reacted' => null])

@use('App\Enums\ReactionEmoji')

{{-- Built on first open rather than left hidden: five buttons on every card of
     every day is most of the feed's DOM, and none of it is on screen. --}}
<template x-if="showing">
    {{-- No x-transition: it needs an x-show to drive it, and x-if is what
         keeps the bar out of the DOM until it is asked for. --}}
    <div
        id="reactions-bar-{{ $mark->id }}"
        x-on:pointerdown.stop
        x-on:click.outside="close()"
        x-on:keydown.escape.window="close()"
        {{-- The band spans the card, so it may not take the taps that belong to
             the controls underneath it. --}}
        class="pointer-events-none absolute inset-x-0 bottom-0 z-10 flex justify-center p-2"
        role="group"
        aria-label="Reaccionar"
    >
        <div
            class="pointer-events-auto flex items-center gap-0.5 rounded-full bg-white/95 p-1 shadow-lg ring-1 ring-black/10 backdrop-blur dark:bg-zinc-800/95 dark:ring-white/10"
        >
            @foreach (ReactionEmoji::cases() as $emoji)
                <button
                    type="button"
                    data-emoji="{{ $emoji->value }}"
                    x-on:click="choose('{{ $emoji->value }}')"
                    :class="active === '{{ $emoji->value }}' && '-translate-y-2 scale-125'"
                    @class([
                        'tap-target grid size-11 place-items-center rounded-full text-xl leading-none transition duration-100',
                        'bg-accent/15 ring-1 ring-accent/40' => $reacted === $emoji,
                    ])
                    aria-label="{{ $emoji->label() }}"
                    aria-pressed="{{ $reacted === $emoji ? 'true' : 'false' }}"
                    data-test="react-{{ $mark->id }}-{{ $emoji->value }}"
                >
                    <span aria-hidden="true">{{ $emoji->character() }}</span>
                </button>
            @endforeach
        </div>
    </div>
</template>
