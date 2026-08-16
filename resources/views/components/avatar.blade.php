@props(['user' => null, 'userId' => null, 'name' => null, 'size' => 'sm'])

{{-- A member's face, in the one order the whole app agrees on: the picture
     they uploaded, then the Gravatar their email already has, then the
     coloured initials that have always been the fallback.

     The middle one is the reason this is a component rather than six copies of
     `flux:avatar`. A Gravatar URL is a guess — `d=404` is what makes an
     address with no picture answer 404 instead of with a silhouette — so it
     cannot be handed over as `src`, which would leave a broken image behind.
     It goes on top of the initials instead, and takes itself off the page when
     the guess was wrong. Nothing here reaches the network: the URL is a hash
     of the email, and the browser is what fetches it. --}}

@php
    // Standings rows and recap winners arrive as a frozen id and name rather
    // than as a member, so the roster is what turns one back into a face.
    $member = $user ?? resolve(App\Queries\Members::class)->find($userId);

    $picture = $member?->pictureUrl();
@endphp

@if ($picture !== null)
    <flux:avatar :name="$member->name" :src="$picture" circle :size="$size" {{ $attributes }} />
@else
    @php
        // Only asked for once the upload has come up empty, so a member with a
        // picture never pays for a hash nothing will render.
        $gravatar = $member?->gravatarUrl();

        // A name with no member behind it is somebody a recap still remembers
        // and the roster no longer has.
        $initials = $member?->initials() ?? App\Models\User::initialsFor((string) $name);

        // The seed decides the initials colour, and members have grown to know
        // their own. It stays the user id wherever there is one.
        $seed = $member?->id ?? $userId ?? $name;
    @endphp

    {{-- Everything goes in the slot, and neither `name` nor `initials` is
         passed with it. The slot is the only way into the avatar's own
         rounded, `relative` box, which is where the Gravatar layer has to sit
         — and Flux renders `$initials ?? $slot`, deriving the initials from
         `name` when it is given one. Passing either drops the slot, layer and
         all. The colour seed is passed explicitly for the same reason: it
         defaults to `name`, which is no longer there to read. --}}
    <flux:avatar color="auto" :color:seed="$seed" circle :size="$size" {{ $attributes }}>
        {{ $initials }}

        @if ($gravatar !== null)
            <img
                src="{{ $gravatar }}"
                alt=""
                onerror="this.remove()"
                referrerpolicy="no-referrer"
                decoding="async"
                {{-- A feed of twenty cards is twenty faces, and all but the
                     first few are below the fold. --}}
                loading="lazy"
                class="absolute inset-0 size-full rounded-[var(--avatar-radius)] object-cover"
            >
        @endif
    </flux:avatar>
@endif
