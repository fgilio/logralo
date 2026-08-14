# Sharing to WhatsApp

The share button existed from the first version and nobody used it twice. This is why, and what replaced it.

## What was wrong

A share sent `route('today')` — the app root. The recipient's WhatsApp fetches that URL with an anonymous crawler, gets redirected to `/entrar`, and unfurls the login page. Every share anyone in the group ever sent produced the same grey "Entrar · Logralo" tile. There were also no OpenGraph tags at all, so there was no image to draw and the preview fell back to a favicon-sized thumbnail.

Worse, the photo and the text were mutually exclusive. `navigator.share()` drops the caption when a file comes with it, so the old code picked one: if the blob prefetch succeeded it sent a naked photo with no context and no link, and otherwise it sent a line of text over a dead root URL. Neither is worth sending twice.

## The fix, in one sentence

Everything shareable has its own public URL whose `og:image` is a composed card, so **one tap sends a link and the unfurl carries the picture**.

## The token is the access control

The group is private — no registration, no invite flow — but a link preview is built by an anonymous crawler, so anything it has to draw lives outside `auth`. Marks and recaps therefore carry a `share_token`: 24 alphanumeric characters, about 143 bits.

- **Minted at creation, not on the first tap.** `navigator.share()` has to be called inside the gesture that opened it; an `await` for a round trip to mint a token spends the transient activation and the sheet never opens on iOS. A token that already exists is a URL the template can print. This is the same constraint that puts the camera behind the sheet rather than the hold.
- **Revoking clears the token** and then deletes the rendered cards from the bucket. That order matters: clearing second fails open, because a throw after the files are gone leaves a link that still resolves and a controller that will happily compose the cards again. Clearing first means any later failure costs an orphaned file rather than privacy.
- **Sharing again mints a new token**, so changing your mind does not resurrect the address you were running from. A card that is still shared keeps the token it has — rotating there would quietly break links the group is still passing around.
- **Revoked and never-existed both answer 404.** A 410 would confirm that a token was once real.
- Revoking lives on the share page itself, owner-only, because that is the only screen where the member is looking at exactly what everyone they sent it to can see.

This does mean a shared photo is reachable by anyone holding the link. That is not a larger exposure than the feature it replaces: the old share sent the raw JPEG into a group chat, where it could already be forwarded anywhere.

## Why the card is drawn with GD and not a browser

The obvious way to render an OG image is to screenshot a Blade view — `spatie/laravel-og-image` does exactly that, through `spatie/laravel-screenshot` and Browsershot.

It needs Node **and a Chrome binary**. Laravel Cloud has no Chrome binary, and getting one there means Puppeteer downloading ~150 MB during the build. `laravel-cloud-production.md` forbids a build that touches the network, and `tests/Arch/BuildTest.php` guards it, after a versioned font URL went 404 mid-deploy and failed a deploy over a change to `.env.example`. Trading that rule for nicer text layout is a bad trade.

So `App\Services\ShareCardComposer` draws the card with Intervention over GD, which is already the photo pipeline. The costs are real and worth knowing:

- **Layout is arithmetic.** Everything scales off the card's width, so the wide unfurl and the tall portrait share one routine.
- **No emoji.** GD renders one TTF at a time and has no colour-glyph support, so an emoji comes out as a hollow box. `ShareCard` carries no emoji by construction; the flame is the badge's colour instead of a character. The share _text_ may carry emoji — that is text in WhatsApp, not a glyph anyone has to draw.
- **The fonts are committed.** `resources/fonts/` holds Anton and Archivo as TTF because FreeType cannot read a woff2 and `@fontsource` ships nothing else. Both are OFL.

## The two shapes

| Format     | Size      | What it is for                                                                                |
| ---------- | --------- | --------------------------------------------------------------------------------------------- |
| `og`       | 1200×630  | the link preview. 1.91:1 is the ratio that gets the large card rather than the thumbnail tile |
| `portrait` | 1080×1350 | the file itself, for a story post or anywhere a preview will not render                       |

Cards render on first request and are cached on the photo disk under `shares/{token}/`, and served from this origin rather than redirected to the bucket: production signs its photo URLs and those expire, while an unfurl is fetched whenever a chat client feels like it.

The response itself is `private, no-cache, must-revalidate` with an ETag. `public, immutable` was tempting — the content behind a token really never changes — but a card can carry a private photo, and a CDN or browser holding a year-long copy would keep serving it after revocation, from in front of the lookup that is supposed to stop it. Revocation has to mean something, so every request revalidates and the ETag makes that cost a 304 rather than the bytes. What WhatsApp caches on its own servers after the unfurl is beyond reach, and always was.

The share button `fetch()`es the unfurl card on `pointerdown` to warm it. A preview that comes back slowly is a preview the sender's own client gives up on, leaving a bare link in the chat.

## The gestures

- **Tap** the share button → the native sheet with the link. One tap, and the unfurl draws the photo.
- **Hold** → a small menu: send the image, copy the link, see how it looks.
- **Enter or Space** → the same as a tap, and **↓** opens the menu.

The hold _opens_ the menu rather than sharing the image directly, for the same reason the goal card's hold opens a sheet instead of the camera: a press timer is not a user gesture, and a `navigator.share()` fired from one is refused on iOS.

The keyboard needs its own handler because `short-press` rides on `pointerup`, which a keyboard never sends — without it, Enter did nothing at all. A click from the keyboard carries `detail === 0`, which is how the two are told apart without a tap firing both.

Once a card is revoked, the button is replaced by "Compartir de nuevo" for whoever can put it back, which mints the fresh token.

## Asking at the right moment

Sharing did not fail because the button was hard to find. It failed because nothing ever asked.

`App\Services\StreakMilestone` marks 3, 7, 14, 21, 30, 50, 75 and 100 days, then every fifty. Landing on one opens a celebration with the card and a share button — the one moment somebody actually wants to tell the group. It is deliberately rare: a celebration on every mark is a dialog to dismiss, which is worse than no dialog.

The streak it tests is the one **ending on the day that was marked**, not the one counting back from today. Inside the grace window those are different numbers, and marking yesterday at 11am would otherwise test today's run — celebrating the wrong day, or missing it.

Only the streak is checked. Taking the lead in the month would be worth celebrating too, but it costs a standings query on the hottest tap in the app.

## The way back

- A member arriving from a link lands on `/#mark-{id}`; the feed card carries that id, and `:target` scrolls and highlights it in CSS with no script.
- Reactions live on the share page too, so a tap from WhatsApp becomes a reaction without loading the feed. Members react; everyone sees the counts.
- The visit count goes back on the card in the feed, visible only to whoever shared it. Crawlers do not count — WhatsApp fetches a link once to build its preview, and counting that would report a view for every message sent whether or not anyone opened it.
