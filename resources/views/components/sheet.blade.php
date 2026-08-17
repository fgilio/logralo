{{-- The app's bottom sheet: a Flux flyout pinned to the bottom edge, wearing
     the drag handle and the safe-area padding every sheet owes the phones
     with a home indicator. --}}
@props(['name'])

<flux:modal
    :name="$name"
    flyout
    position="bottom"
    {{ $attributes->merge(['class' => 'max-h-[85dvh] overscroll-contain rounded-t-2xl p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))]']) }}
>
    <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-zinc-300 dark:bg-white/20"></div>

    {{ $slot }}
</flux:modal>
