# Photos, the camera, and how members get in

Two areas where the implementation deliberately differs from the first reading of the spec, and why.

## The camera is behind the sheet, not the hold

The scope says holding a card opens the native camera. It cannot, reliably.

`<input type="file" capture="environment">.click()` only opens the camera if it runs inside the browser's _transient user activation_. A long press is measured by a timer, and by the time the timer fires the activation is gone — on iOS the call silently does nothing. A camera button that sometimes does nothing is worse than one extra tap.

So:

- **Tap** a pending card → marks it instantly (a ghost mark).
- **Hold** a pending card → opens the sheet, whose primary button is the native camera input. That button is a real tap, so the camera always opens.
- **Tap** when the photo rule is armed → opens the same sheet, with the "Pics or it didn't happen 📸" copy. Backing out leaves the day unmarked, exactly as specified.
- **Tap** a marked card → un-marks it, while the day is still open.

The sheet is also what makes "photo, note, or both" possible from one gesture.

## What happens to an upload

`App\Services\PhotoProcessor` never stores the original. Each upload becomes, under one key:

| Derivative                        | Purpose                                      |
| --------------------------------- | -------------------------------------------- |
| `feed-720.webp`, `feed-1080.webp` | the feed `srcset`                            |
| `feed-1080.jpg`                   | fallback, and the file the share sheet sends |
| `thumb.webp`                      | the goal card                                |

- **EXIF is stripped** on every derivative. A group photo feed has no business shipping everyone's GPS coordinates. This is why the pipeline uses Intervention's encoders directly rather than Laravel 13's first-party `Image` facade, which exposes no strip option.
- **Orientation is applied on decode**, so an iPhone photo is stored upright and the stored file needs no orientation tag.
- **Dimensions are recorded** on the mark, so the feed reserves the right box before the image loads and nothing jumps.
- **JPEG is what gets shared.** WhatsApp will not take a WebP, and `navigator.share` drops the caption when files are attached, so the share action sends the photo alone and the "share a link" path sends the text.

## Where the photos live

Production writes to a private Cloudflare R2 bucket on Laravel Cloud, so `LOGRALO_PHOTO_SIGNED_URLS=true` and every derivative is served through a temporary URL.

A signature is only good for an hour, and a fresh one is minted on every render — which would give the polling feed a new `<img src>` each cycle and re-download every photo over mobile data. `PhotoProcessor::url()` therefore caches each signature for three quarters of its life: long enough for the browser cache to work, short enough that nobody is handed a URL about to expire.

The bucket also needs the app's origins in its CORS allow-list. Sharing to WhatsApp `fetch()`es the JPEG derivative to build the `File` that `navigator.share` sends, and that fetch is cross-origin to the bucket.

HEIC only reaches the server from desktop Safari — iOS transcodes to JPEG inside the file input. GD cannot decode it, so an unreadable upload raises `PhotoUnreadableException` with copy that tells the member what to change.

## Joining, over WhatsApp

There is no registration page. `logralo:seed-member` creates the member and prints a signed link.

The link is consumed in **two steps**, and that is not ceremony: WhatsApp fetches a URL to build its preview card. A one-time token that burned on GET would be dead before the member ever tapped it.

1. `GET /acceso/{user}/{token}` — validates the signature and the token, and renders "Entrar como {name}". Nothing is consumed.
2. `POST` to the same signed URL — burns the token, logs them in, sends them to choose a password.

The token is stored as a SHA-256 hash, so a database leak yields no working links; re-running the seed command rotates it, which revokes whatever was sent before; and every rejection — wrong token, already used, never had one — looks identical, so the page is not an oracle for account state.

Until a password exists, `App\Http\Middleware\EnsurePasswordIsSet` keeps the member on the password screen. Logout stays reachable, or a half-onboarded member would be stuck.
