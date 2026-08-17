{{-- The dashed placeholder a section shows before it has anything to say. --}}
<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-zinc-300 p-8 text-center dark:border-white/15']) }}>
    {{ $slot }}
</div>
