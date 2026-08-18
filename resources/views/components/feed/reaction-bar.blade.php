@props(['mark', 'reacted' => null])

@use('App\Enums\ReactionEmoji')

{{-- Hidden rather than built on demand: x-if would create the bar inside the
     same click that opens it, and click.outside would shut it again before the
     finger left the screen.

     Across the middle of the card, over the photo rather than over the row the
     ＋ sits in: measured from the foot it would land on that row, and a 3u
     card's note band moves the foot without moving the button. --}}
<div
    x-show="showing"
    x-cloak
    x-transition
    id="reactions-bar-{{ $mark->id }}"
    x-on:click.outside="close()"
    x-on:keydown.escape.window="close()"
    class="absolute top-1/2 left-1/2 z-10 flex -translate-x-1/2 -translate-y-1/2 items-center gap-0.5 rounded-full bg-white/95 p-1 shadow-lg ring-1 ring-black/10 backdrop-blur dark:bg-zinc-800/95 dark:ring-white/10"
    role="group"
    aria-label="Reaccionar"
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
