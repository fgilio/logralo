@props(['ghost' => false, 'full' => false, 'size' => 'base', 'ring' => false])

@php
    $icon = $size === 'lg' ? 'size-7' : 'size-6';
@endphp

{{-- What a goal card says about today's mark, once the round trip lands: a
     ghost owes proof, a full mark carries it, and an empty ring — which only
     the row asks for — is that variant's whole affordance, because a tile is
     obviously tappable at its size and a line of text is not.

     Shared by the tile and the row so that a new mark state, or another action
     worth spinning for, is written once instead of twice. --}}
<div wire:loading wire:target="press, save, remove">
    <flux:icon name="loading" variant="micro" class="animate-spin opacity-60" />
</div>

<div wire:loading.remove wire:target="press, save, remove">
    @if ($ghost)
        <span class="text-lg" title="Marcado sin foto">🌫️</span>
    @elseif ($full)
        <flux:icon name="check-circle" variant="solid" class="{{ $icon }} text-accent" />
    @elseif ($ring)
        <span class="block {{ $icon }} rounded-full border-2 border-zinc-300 dark:border-white/20" aria-hidden="true"></span>
    @endif
</div>
