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
- **No emoji.** GD renders one TTF at a time and has no colour-glyph support, so an emoji comes out as a hollow box. `ShareCard` carries no emoji by construction. The flame after the streak is a picture rather than a character: `resources/images/flame.png` is the PWA icon with its flat ground keyed out, so the card ends "14 de agosto · 12 🔥" without ever asking GD to draw a 🔥. The share _text_ may carry emoji — that is text in WhatsApp, not a glyph anyone has to draw.
- **The fonts and the flame are committed.** `resources/fonts/` holds Anton and Archivo as TTF because FreeType cannot read a woff2 and `@fontsource` ships nothing else; both are OFL. `resources/images/flame.png` is there for the same reason — the brand mark is an SVG, and nothing in the pipeline can rasterise one.

## What the card says, and what it stopped saying

A mark's card is the goal name, as loud as it fits, over the day it was marked and the streak beside it — a number in ember, then the flame. That is all of it.

The streak is written the same way everywhere now: `x-flame` puts the number before the mark on the feed, the goal card and the share page, because "12 🔥" is how somebody would type it into the chat this is competing with.

Three things came off, and each was saying something the card's own reader already knew:

- **The wordmark.** "LOGRALO" in the top corner was branding a picture that arrives under a link to `logralo.fgilio.com`, in a chat where the same card has landed a dozen times. The site name is in the unfurl; it does not need to be in the photo too.
- **The member's name.** It is sent by the person who earned it, into a group of five who know each other, and the photo is usually of them. `share/partials/mark.blade.php` still names them on the page itself, where a stranger who followed the link needs it.
- **The badge.** It carried the streak, above a title, which is where the streak now sits beside the date. A card saying "12 días seguidos" twice reads as a bug. The recap keeps its badge, because "Cerró el mes" is not repeated anywhere else on it.

The title is drawn at 8.8% of the card's width, and shrunk from there if it would not fit. A goal name is allowed forty characters, and Anton at the size "Leer" wants would run "Natación por la mañana antes del trabajo" off the edge. FreeType scales linearly, so `fit()` measures once and takes the ratio rather than stepping the size down.

## What goes in the chat

The line above the link is **what the member typed when they marked it**, and only if they typed nothing, `Marqué {goal}`.

"Franco marcó Gimnasio" was a caption written by software, sent by Franco, over a card that already said Gimnasio in ninety-point type. The note is the one part of a share nobody else could have written — it is where the 6am and the rain go — so it is the part that gets sent. The fallback is first person and carries no name, for the same reason the card does not.

The unfurl's own text is then deliberately almost empty: `og:title` is the site name and there is no `og:description` at all. The picture carries the goal, the day and the streak; a title repeating them and a description pitching the app were two lines of grey under something that had already made the point. `partials/head.blade.php` drops the description tags entirely when a page passes an empty one, because an empty `content` attribute is not the same as no tag.

## The two shapes

| Format     | Size      | What it is for                                                                                |
| ---------- | --------- | --------------------------------------------------------------------------------------------- |
| `og`       | 1200×630  | the link preview. 1.91:1 is the ratio that gets the large card rather than the thumbnail tile |
| `portrait` | 1080×1350 | the file itself, for a story post or anywhere a preview will not render                       |

Cards render on first request and are cached on the photo disk under `shares/{token}/`, and served from this origin rather than redirected to the bucket: production signs its photo URLs and those expire, while an unfurl is fetched whenever a chat client feels like it.

A card is composed once and kept, and the renderer hands back whatever is on the disk without asking the composer whether it would still draw that. So the file is named `og-{design}.jpg`, after `ShareCardFormat::DESIGN` — the redesign above would otherwise have reached new shares only, while every link already sitting in a chat kept unfurling the wordmark and the member's name. The version is in the filename rather than the directory because revoking is a `deleteDirectory` on the token, and it has to take the older cards with it: one left behind is a private photo still in the bucket. Live shares keep their superseded copies until they are revoked, which costs a few kilobytes and no privacy.

The response itself is `private, no-cache, must-revalidate` with an ETag. `public, immutable` was tempting — the content behind a token really never changes — but a card can carry a private photo, and a CDN or browser holding a year-long copy would keep serving it after revocation, from in front of the lookup that is supposed to stop it. Revocation has to mean something, so every request revalidates and the ETag makes that cost a 304 rather than the bytes. What WhatsApp caches on its own servers after the unfurl is beyond reach, and always was.

The share button `fetch()`es the unfurl card on `pointerdown` to warm it. A preview that comes back slowly is a preview the sender's own client gives up on, leaving a bare link in the chat.

The tall card is fetched later — when the hold opens the menu that offers it, not on every touch. It still has to be in hand before `navigator.share()` is called, because an `await` between the gesture and the call spends the transient activation on iOS and the sheet never opens; the menu's dwell time is what pays for it. A plain tap never sends a file, so it no longer downloads one either.

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

The page under the picture says each thing once. The goal name was the heading, then the sub-line under the avatar, then the photo's alt text — three times, over a photograph of the thing. It is the heading; the sub-line is the member's name and nothing else, the day is in the header, and the note gets the ember rule, because it is the one line on the page nobody else could have written.

- A member arriving from a link lands on `/#mark-{id}`; the feed card carries that id, and `:target` scrolls and highlights it in CSS with no script.
- Reactions live on the share page too, so a tap from WhatsApp becomes a reaction without loading the feed. Members react; everyone sees the counts.
- The visit count goes back on the card in the feed, visible only to whoever shared it. Crawlers do not count — WhatsApp fetches a link once to build its preview, and counting that would report a view for every message sent whether or not anyone opened it.
