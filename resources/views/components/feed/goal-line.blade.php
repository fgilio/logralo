@props(['mark'])

{{-- Which goal, and how long ago — the line that sits under a name wherever a
     card has room for one. --}}
<p {{ $attributes->class(['truncate text-xs']) }}>
    {{ $mark->goal->emoji }} {{ $mark->goal->name }}
    <span aria-hidden="true">·</span>
    <time datetime="{{ $mark->created_at?->toIso8601String() }}">
        {{ $mark->created_at?->diffForHumans(short: true) }}
    </time>
</p>
