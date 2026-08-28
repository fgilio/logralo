<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Grace cutoff
    |---------------------------------------------------------------------------
    |
    | A member's day D accepts marks from D 00:00 until D+1 at this hour, in the
    | member's own timezone, and then closes forever. This single number decides
    | when the grace banner shows, when a streak breaks, and when a month can be
    | closed.
    |
    */

    'grace_cutoff_hour' => (int) env('LOGRALO_GRACE_CUTOFF_HOUR', 12),

    /*
    |---------------------------------------------------------------------------
    | Default timezone
    |---------------------------------------------------------------------------
    |
    | Seeded members start here and can change it from their profile.
    |
    */

    'default_timezone' => (string) env('LOGRALO_DEFAULT_TIMEZONE', 'America/Montevideo'),

    /*
    |---------------------------------------------------------------------------
    | Goals
    |---------------------------------------------------------------------------
    |
    | Five goals fit the grid. After two ghost marks in a row on the same goal,
    | the third tap opens the camera instead of marking.
    |
    */

    'goals' => [
        'max_active' => 5,
        'ghosts_before_camera' => 2,
        'name_max_length' => 40,
        'note_max_length' => 140,
    ],

    /*
    |---------------------------------------------------------------------------
    | Photos
    |---------------------------------------------------------------------------
    |
    | Originals are never kept: every upload is downscaled, stripped of metadata
    | and stored as a feed image plus a card thumbnail.
    |
    | Two of these numbers are about memory rather than looks. A decoded pixel
    | costs four bytes, so a 48 MP camera original is 190 MB inside a 512 MiB
    | container — the browser therefore resizes before it uploads, and the
    | server refuses anything that still would not fit.
    |
    */

    'photos' => [
        'disk' => (string) env('LOGRALO_PHOTO_DISK', 'photos'),
        'webp_quality' => 78,
        'jpeg_quality' => 80,
        // What the phone shrinks to before uploading. Generous on purpose:
        // nothing wider than 1080px is ever stored, so 12 MP is around twenty
        // times what the pipeline needs, and the headroom is what keeps this
        // number out of the way if a derivative ever grows.
        'client_max_megapixels' => 12,
        'client_jpeg_quality' => 85,
        // The ceiling the server will decode, with room for the one full-size
        // bitmap the pipeline holds at a time. Past this an upload is refused
        // in words rather than by taking the container down with it.
        'max_megapixels' => 32,
        // Public buckets keep the feed browser-cacheable. Flip this on for a
        // private bucket and every photo URL becomes a short-lived signed one.
        'signed_urls' => (bool) env('LOGRALO_PHOTO_SIGNED_URLS', false),
        'url_ttl_minutes' => 60,
        'max_upload_kilobytes' => 20480,
    ],

    /*
    |---------------------------------------------------------------------------
    | Avatars
    |---------------------------------------------------------------------------
    |
    | A member's picture, in order: the one they uploaded, then the Gravatar
    | their email already has, then the coloured initials that have always been
    | there. Only the first is stored by this app.
    |
    | One size covers every avatar on screen. The biggest is the 56px ring in
    | the pulse strip, so 192 is that at DPR 3 — and asking Gravatar for a
    | single width is what lets the four faces in this group be four browser
    | cache entries rather than one per size.
    |
    */

    'avatars' => [
        'size' => 192,
        'webp_quality' => 82,
        // Off flips the whole app back to uploads and initials, without
        // touching a template. Nothing here reaches Gravatar from the server:
        // the URL is built from a hash of the email and the browser fetches it.
        'gravatar' => (bool) env('LOGRALO_GRAVATAR', true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Feed
    |---------------------------------------------------------------------------
    */

    'feed' => [
        'page_size' => 20,
    ],

    /*
    |---------------------------------------------------------------------------
    | Comments
    |---------------------------------------------------------------------------
    |
    | The thread under an open photo. Nothing of it reaches the feed, which
    | stays a wall of pictures. The cap is a line's worth: a comment sits next
    | to a face in a box a third of a phone tall, and anything longer is a
    | message that belongs in the chat these five already have.
    |
    */

    'comments' => [
        'max_length' => 280,
    ],

    /*
    |---------------------------------------------------------------------------
    | Feedback
    |---------------------------------------------------------------------------
    |
    | The floating button on every screen behind the gate. Everything sent is
    | kept in the `feedback` table; the address below only decides whether an
    | inbox also hears about it as it lands. Empty means nobody is mailed.
    |
    */

    'feedback' => [
        'email' => (string) env('LOGRALO_FEEDBACK_EMAIL', ''),
        'max_length' => 1000,
    ],

    /*
    |---------------------------------------------------------------------------
    | Push notifications
    |---------------------------------------------------------------------------
    |
    | Web Push, so there is no provider and no native app: the browser hands us
    | an endpoint and we sign a request to it with the VAPID keys, which live in
    | the environment and are read by `config/webpush.php` from the package. On
    | iOS a subscription is only possible once the app is on the home screen.
    |
    | Three events are worth a buzz, and the list is meant to stay this short.
    | A notification on every mark or every reaction is what makes somebody
    | turn the whole thing off.
    |
    */

    'push' => [
        // How close to a day's grace cutoff the "your streak is about to
        // break" nudge goes out. The scheduler runs hourly, so a window
        // rather than an hour means a missed tick still catches the member.
        'reminder_lead_hours' => (int) env('LOGRALO_PUSH_REMINDER_LEAD_HOURS', 3),
    ],

    /*
    |---------------------------------------------------------------------------
    | Magic links
    |---------------------------------------------------------------------------
    |
    | Members join by opening a signed link sent over WhatsApp. The link is only
    | good until they set a password.
    |
    */

    'magic_link' => [
        'ttl_days' => (int) env('LOGRALO_MAGIC_LINK_TTL_DAYS', 14),
    ],

];
