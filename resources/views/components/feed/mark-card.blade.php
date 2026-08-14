@props(['entry', 'height' => 1, 'eager' => false, 'reacted' => null])

{{-- The ladder decides the shape, not the content: inside a day the newest mark
     is the cover, the one behind it is the split card, and everything older is
     a row. --}}
@if ($height === 3)
    <x-feed.cover :entry="$entry" :eager="$eager" :reacted="$reacted" />
@elseif ($height === 2)
    <x-feed.split :entry="$entry" :reacted="$reacted" />
@else
    <x-feed.row :entry="$entry" :reacted="$reacted" />
@endif
