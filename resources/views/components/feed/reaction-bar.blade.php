@props(['mark', 'reacted' => null])

@php
    use App\Enums\ReactionEmoji;
@endphp

{{-- One bar per card, and both ways in land here: the ＋ button opens it, and so
     does holding the card, which can then drag onto an emoji and let go. A
     selector rather than a set of checkboxes — one reaction per member per
     mark, and choosing the current one takes it away. --}}
<div
    x-show="showing"
    x-cloak
    x-transition
    x-on:pointerdown.stop
    x-on:click.outside="close()"
    x-on:keydown.escape.window="close()"
    class="absolute inset-x-0 bottom-0 z-10 flex justify-center p-2"
    role="group"
    aria-label="Reaccionar"
>
    <div
        class="flex items-center gap-0.5 rounded-full bg-white/95 p-1 shadow-lg ring-1 ring-black/10 backdrop-blur dark:bg-zinc-800/95 dark:ring-white/10"
    >
        @foreach (ReactionEmoji::cases() as $emoji)
            <button
                type="button"
                data-emoji="{{ $emoji->value }}"
                wire:click="react('{{ $mark->id }}', '{{ $emoji->value }}')"
                x-on:click="close()"
                :class="active === '{{ $emoji->value }}' && '-translate-y-2 scale-125'"
                @class([
                    'tap-target grid size-9 place-items-center rounded-full text-xl leading-none transition duration-100',
                    'bg-accent/15 ring-1 ring-accent/40' => $reacted === $emoji,
                ])
                aria-pressed="{{ $reacted === $emoji ? 'true' : 'false' }}"
                data-test="react-{{ $emoji->value }}"
            >
                {{ $emoji->character() }}
            </button>
        @endforeach
    </div>
</div>
