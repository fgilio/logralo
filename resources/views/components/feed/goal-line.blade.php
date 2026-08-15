@props(['mark'])

<p {{ $attributes->class(['truncate text-xs']) }}>
    {{ $mark->goal->emoji }} {{ $mark->goal->name }}
    <span aria-hidden="true">·</span>
    <time datetime="{{ $mark->created_at?->toIso8601String() }}">
        {{ $mark->created_at?->diffForHumans(short: true) }}
    </time>
</p>
