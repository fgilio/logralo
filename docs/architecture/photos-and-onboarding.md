# Photos, the camera, and how members get in

The places where the implementation deliberately differs from the first reading of the spec, and why.

## The camera is behind the sheet, not the hold

The scope says holding a card opens the native camera. It cannot, reliably.

`<input type="file" capture="environment">.click()` only opens the camera if it runs inside the browser's _transient user activation_. A long press is measured by a timer, and by the time the timer fires the activation is gone — on iOS the call silently does nothing. A camera button that sometimes does nothing is worse than one extra tap.

So:

- **Tap** a pending card → marks it instantly (a ghost mark).
- **Hold** a pending card → opens the sheet, whose primary button is the native camera input. That button is a real tap, so the camera always opens.
- **Tap** when the photo rule is armed → opens the same sheet, with the "Pics or it didn't happen 📸" copy. Backing out leaves the day unmarked, exactly as specified.
- **Tap** a marked card → opens the same sheet, where **Quitar marca** un-marks it while the day is still open. Un-marking is never on the tap: it deletes the day and its photo, and a pocket touch on the same gesture that marks was enough to do it.

The sheet is also what makes "photo, note, or both" possible from one gesture.

## The photo is shrunk before it is uploaded

A 48 MP phone sensor produces a picture that costs about 190 MB to decode — four bytes a pixel, the moment PHP opens it — and the web container has 512 MiB. One of those uploads took the container down, and the ones that didn't still spent a minute of somebody's mobile data uploading twenty times more picture than the feed can show.

So the resize happens on the phone, in `resources/js/compress-photo.js`: fit the photo inside a **12 MP** budget, re-encode as **JPEG at 85%**, upload that.

It is all platform API, no library and no wasm:

| Step      | API                                                                  |
| --------- | -------------------------------------------------------------------- |
| decode    | `createImageBitmap(file, { imageOrientation })`                      |
| downscale | `createImageBitmap(bitmap, { resizeWidth, resizeQuality: 'high' })`  |
| re-encode | `OffscreenCanvas` → `convertToBlob({ type: 'image/jpeg', quality })` |

The wasm codecs — jSquash's MozJPEG, Squoosh, `browser-image-compression`'s worker pool — buy roughly 15% smaller files for something like 600 KB of extra download on a phone that is already on mobile data. None of that reaches the feed anyway: the server re-encodes everything to 1080px, so the only number this step has to hit is "small enough to decode safely". The whole bundle, app included, is 7 KB.

Four things about it are not obvious:

- **Orientation is applied here, not later.** The canvas strips EXIF on the way out, so an orientation this step declines to apply is one nothing downstream can recover — every portrait photo would reach the feed lying on its side. Hence the explicit `imageOrientation: 'from-image'`, which is the spec's default but was not always Chrome's.
- **The decoder does the downscale where it can.** `resizeQuality: 'high'` is a filtered resample rather than the canvas's own scaling. Engines that predate the resize options ignore them instead of failing, so the code measures what came back and lets `drawImage` scale when they did not take.
- **Every failure path returns the original file.** A photo that uploads slowly is a worse day; a photo that never uploads is a broken app. That is also why the server keeps a ceiling of its own.
- **A HEIC comes out as a JPEG**, which quietly fixes the desktop Safari upload that GD could never read — even when that JPEG is the heavier file. HEIC is roughly twice as dense, so an honest re-encode of one routinely grows, and the size rule that would normally discard it is limited to JPEG, PNG and WebP for exactly that reason: those the server opens either way, so the smaller file wins; a HEIC handed back unconverted is one it cannot open at all.

`12` and `85` live in `config/logralo.php`, and the head renders them once into `window.Logralo.photo` — every picker on the site shrinks to the same ceiling, so spelling the megapixel-to-pixel arithmetic into each `x-data` was one copy of it per camera on the site. 12 MP is deliberately about twenty times what the pipeline needs — the headroom is there so this number stays out of the way if a derivative ever grows.

The camera input is no longer on `wire:model` because of this: the resize has to happen between the change event and the upload, so `photoPicker` shrinks the file and then calls `$wire.upload()` itself. The busy overlay moved from `wire:loading` to Alpine for the same reason — the resize is the slower half on an older phone, and `wire:loading` cannot see it. That overlay renders inside the camera button, so the button is disabled while it shows and `open()` refuses to fire: otherwise a tap on the spinner starts a second photo, and whichever upload finished first would clear `busy` and free the save button while the retake was still going up.

The browser test drives the resize through `window.Logralo.compressPhoto` rather than through the input, and that is not laziness: Pest's Laravel test server builds its request with an empty files array (`// @TODO files...` in `LaravelHttpServer`), so no upload can ever complete against it. The test draws a 24 MP JPEG in the page, runs the real pipeline over it, and checks the dimensions, the type and that it got smaller.

### The ceiling underneath it

`PhotoProcessor` asks `UploadedFile::dimensions()` — a header read, not a decode, and the same one the `image` validation rule uses — what an upload would cost before anything decodes it, and refuses past `photos.max_megapixels` (32) with `PhotoTooLargeException`. Phones never reach it. It is the net under the upload that skipped the browser step: a desktop drag-and-drop, or a browser where the resize failed and handed the original back on purpose. Without it the OOM is merely unlikely rather than impossible.

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

HEIC only reaches the server from desktop Safari — iOS transcodes to JPEG inside the file input, and the browser-side resize above re-encodes whatever is left as JPEG. GD cannot decode HEIC, so an upload that gets past both raises `PhotoUnreadableException` with copy that tells the member what to change. That is the one place a wasm codec would still earn its download: `libheif-js` would let a Chrome-on-Android HEIC be decoded client-side rather than refused server-side. Nobody in the group has hit it.

## The photos are not offered for download

What a member proves in here is meant to stay in here, so nothing on the page hands a photo over on a gesture. Four ways out, and each is closed where it happens:

| Gesture                    | Where it is refused                                           |
| -------------------------- | ------------------------------------------------------------- |
| Long press on iOS          | `-webkit-touch-callout: none`, in `resources/css/app.css`     |
| Long press on Android      | the `contextmenu` listener in `resources/js/protect-media.js` |
| Right click on the desktop | the same listener                                             |
| Dragging the picture out   | `-webkit-user-drag: none`, plus the `dragstart` listener      |

Both halves match on `img, picture, video` rather than on a class, and the listeners sit on the document. That is the point of doing it this way: the feed re-renders whole on every reaction, the viewer fills itself from a payload rather than from Blade, and neither has to remember to opt in. Gifs are `<img>` and a gallery is more of them, so those arrive covered too. `picture` is in the selector for the viewer alone, where the letterbox around the photo is part of the same surface and is what a thumb lands on.

The share page is in scope on purpose, and gets there by loading the same bundle. It is the one screen a photo is shown on to somebody outside the group, which makes it the likeliest place for a save, not a reason to allow one — a shop window is still not a shop.

Two things it deliberately does not claim:

- **This is a deterrent, not a lock.** The URL is in the DOM, the network panel lists every request, and every phone screenshots. What it removes is the one-gesture save — which is the one that actually happens, by somebody who was not planning anything.
- **The browser's own video controls are out of reach of any event.** When the first `<video>` lands it needs `controlslist="nodownload" disablepictureinpicture` on the tag itself; the CSS and the listeners cover everything around it, but not the download button inside a native control bar.

`tests/Browser/TodayScreenTest.php` proves the half a headless Chromium can prove: the two events come back cancelled and the computed `user-select` is `none`. The iOS callout has no event behind it and Chromium does not implement the property, so that one is only ever verified on a phone.

## The face on an avatar

Three sources, tried in this order, and `resources/views/components/avatar.blade.php` is the only place that knows the order:

1. **The picture the member uploaded**, from the profile screen.
2. **The Gravatar their email already has.** Everyone in this group has been leaving comments on somebody's blog since 2009, so most of them arrived with a face nobody had to be asked for.
3. **Their coloured initials**, which is what the app drew before any of this.

The interesting one is the middle one, and the interesting part is that it never touches the server. `App\Services\Gravatar` builds a URL out of a SHA-256 of the trimmed, lowercased address — Gravatar still answers to MD5, but the security preset in `tests/Arch/CodeQualityTest.php` bans it and it buys nothing — and the browser is what fetches it. No render waits on a third party, and nothing has to cache whether an address has a picture.

That leaves one problem: an address with no Gravatar. `d=404` is the parameter that makes it answer 404 rather than serving a generated silhouette, which would look exactly like a member who has a picture and would mean the initials never show. So the URL cannot be handed over as an `<img src>`, which would leave a broken image behind — it goes **on top of** the initials, inside the avatar's own box, and `onerror` takes it off the page when the guess was wrong. While the request is in flight the initials are what shows, which is also the right answer offline.

Two details that will look arbitrary in the diff:

- **The initials go in Flux's slot, and neither `name` nor `initials` is passed alongside them.** `flux:avatar` renders `$initials ?? $slot` and derives `$initials` from `name` when it is given one, so passing either silently drops the slot — Gravatar layer and all. The colour seed is passed explicitly for the same reason: it defaults to `name`, which is no longer there to read.
- **`App\Queries\Members` is why the standings row can have a face at all.** Everywhere else carries a `User` to the template, but the standings row and the recap card are built from `Standing`, which a recap freezes into JSON at month close — a picture URL frozen there would be a signature that expired the same hour, or a key for a picture the member has since replaced. So those two look the face up live, by id.

    That lookup is what the class was added for, but not what it is. `GroupPulse` and `MonthlyStandings` were each already reading the whole `users` table on the same render, so an avatar asking for a third copy was the moment to stop: the roster is one scoped read now, and all three take it from there. A group this product caps at a handful of friends is cheaper to hold whole than to ask about twice.

An uploaded avatar goes through `PhotoProcessor` like everything else, and for the guards rather than for the convenience: the pixel-count ceiling, the auto-orientation, the EXIF strip and the URL signing are the whole of what a profile picture needs. It is stored as one square WebP under an `avatars/` key — one derivative, because the biggest avatar on screen is the 56px ring in the pulse strip and `avatars.size` (192) is that at DPR 3.

`LOGRALO_GRAVATAR=false` takes the middle source out without touching a template.

## Joining, over WhatsApp

There is no registration page. `logralo:seed-member` creates the member and prints a signed link.

The link is consumed in **two steps**, and that is not ceremony: WhatsApp fetches a URL to build its preview card. A one-time token that burned on GET would be dead before the member ever tapped it.

1. `GET /acceso/{user}/{token}` — validates the signature and the token, and renders "Entrar como {name}". Nothing is consumed.
2. `POST` to the same signed URL — burns the token, logs them in, sends them to choose a password.

The token is stored as a SHA-256 hash, so a database leak yields no working links; re-running the seed command rotates it, which revokes whatever was sent before; and every rejection — wrong token, already used, never had one — looks identical, so the page is not an oracle for account state.

Until a password exists, `App\Http\Middleware\EnsurePasswordIsSet` keeps the member on the password screen. Logout stays reachable, or a half-onboarded member would be stuck.
