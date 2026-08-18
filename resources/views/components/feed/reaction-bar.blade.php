@props(['mark', 'reacted' => null])

@use('App\Enums\ReactionEmoji')

{{-- Hidden rather than built on demand: x-if would create the bar inside the
     same click that opens it, and click.outside would shut it again before the
     finger left the screen. --}}
<div
    x-show="showing"
    x-cloak
    x-transition
    id="reactions-bar-{{ $mark->id }}"
    x-on:click.outside="close()"
    x-on:keydown.escape.window="close()"
    {{-- Across the middle of the card: the ＋ is the only way in now, and a bar
         measured from the foot would land on the very row it sits in — a 3u
         card's note band moves that foot and the emoji with it. --}}
    class="pointer-events-none absolute inset-x-0 top-1/2 z-10 flex -translate-y-1/2 justify-center p-2"
    role="group"
    aria-label="Reaccionar"
>
    <div
        class="pointer-events-auto flex items-center gap-0.5 rounded-full bg-white/95 p-1 shadow-lg ring-1 ring-black/10 backdrop-blur dark:bg-zinc-800/95 dark:ring-white/10"
    >
        @foreach (ReactionEmoji::cases() as $emoji)
            <button
                type="button"
                x-on:click="choose('{{ $emoji->value }}')"
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
