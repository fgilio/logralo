# The cascade feed

The feed is one list, in one shape, ordered strictly newest to oldest. The only thing that varies is **how tall a card is**, and that is decided by its position within its day.

Six members with five goals each put thirty marks in a day, more than one page of `feed.page_size` (20). At a single height a day could not be seen without loading more.

## The ladder

Inside each day, counting marks only:

| Position          | Height |
| ----------------- | ------ |
| 1st (most recent) | 3u     |
| 2nd               | 2u     |
| 3rd and after     | 1u     |

The ladder **restarts at every day divider**, so each day gets its own cover. The slot is positional: the mark that is newest in a day gets 3u, whatever it is. A mark without a photo renders the goal's emoji at full size, which fills 3u, so nothing is reordered to keep photos on top.

A month recap card keeps its own full-width card and does **not** consume a ladder slot. The day's ladder counts marks only.

The rung is decided server side, in the `rungs()` computed property of `resources/views/livewire/feed.blade.php`, and not in the template. Blade evaluates an anonymous component's attribute expressions twice, once into the data array and once into the attribute bag, so a counter incremented inside `:rung="…"` counts every card twice and hands over the second answer.

## The unit

```
1u  = 76 px      the compact row: a 52 px thumbnail plus its air
gap = 8 px
2u  = 76×2 + 8   = 160 px
3u  = 76×3 + 8×2 = 244 px
```

The taller rungs add whole units plus the gap between the cards they stand in for, so a cover and the three rows it replaces take up the same run of the page.

These numbers exist once, as `--rung-1`, `--rung-2`, `--rung-3` and `--rung-note` in `resources/css/app.css`. The `mark-1u|2u|3u` utilities read them and so does every card, which publishes its own rung as `--card-height` for `contain-intrinsic-size`. Without that per-card value a page of 76 px rows would be estimated at the fallback height and the scrollbar would lie by roughly three times. The theme block is `@theme static` because a variable used only from an inline style is invisible to the class scanner.

The heights are fixed. A card is 3u whether or not it carries a note, with the single exception of the 3u note band below.

At 328 px of content width, which is a 360 px phone under the layout's `px-4`, a 4:3 photo is 246 px tall, within two pixels of 3u. That is where the unit came from, and it means a 3u slot is close to showing a landscape photo whole. It is a coincidence, not a guarantee: on a wider phone the same photo is taller than 244 px and gets cropped.

Photos fill their slot with `object-cover`. A box that spans the page passes `wide` to `x-photo`, which drops the centre-cropped 320 px thumbnail from the srcset. Offered it, a cover on a low-DPR phone would show a crop of the photo where the photo goes. Every other box states its real width through `sizes`, so a 52 px thumbnail is not served the 720 px derivative.

## What each height shows

**3u, the day's most recent mark.** The photo fills the card, edge to edge, under two scrims: the avatar, name, goal, relative time and streak above, the reaction summary with the `＋` and sharing below.

**2u, the second most recent.** A 140 px square photo on the left, 112 px below 360 px so the action row still fits beside it. On the right a column with name, `goal · time`, the streak, the note and the reaction summary.

**1u, everything else that day.** Thumbnail, then two lines: `Nombre · 🎯 Objetivo` and the note (or the time). On the right the reaction summary, the streak and a chevron. Tapping the row expands it in place with the photo, the whole note, sharing, and the `＋` that a row has no room for.

Sharing appears at every height. The button is a labelled pill that shares on a tap and opens the rest on a hold, so the word goes where there is room for it: the bottom scrim at 3u and the expanded panel at 1u. At 2u the column is too narrow, and the button keeps its icon alone. The view count the owner sees rides along wherever the word does.

That menu is teleported to the body. A feed card sets `content-visibility: auto`, which contains its paint, so a menu drawn inside one is cut off at the card's edge.

## The note

`marks.note` is 140 characters, optional, and already in the database. Where it goes was decided per height:

- **3u, its own band under the photo.** The card becomes `3u + 44 px`. This is the one place a card's height depends on its content. Tapping the band unclamps it, since two lines do not always hold 140 characters.
- **2u, in the right-hand column**, clamped to two lines. Height stays exactly 2u.
- **1u, the second line**, italic, truncated. When there is no note that line shows the relative time instead. Height stays exactly 1u, and the whole note is in the panel the row opens.

The note is never laid over the photo.

## The streak is not a reaction

`x-flame` renders a streak as the **brand mark** (the two-tone flame that is the app's identity) plus the count in the display face. It is never an emoji, at any height. Reactions are always emoji characters put there by a person.

The two still read alike, because the brand mark is a two-tone version of the same fire glyph the 🔥 emoji depicts, and `ReactionEmoji::Fire` is one of the five reactions. Whether to drop 🔥 from the reaction set is still open (see below).

## Reactions: showing and adding

Showing and adding are two different problems. A five-emoji bar under every card does not fit in a 76 px row.

**Showing.** Once a mark has any reactions, a summary at every height: the emoji used, overlapped in a small stack, plus the total. About 20 px. The stack shows three at most, and the total beside it stays exact however many kinds are behind it. Your own reaction always holds one of those three slots, ringed in ember, so the ring is reachable even when three louder reactions would push it out.

**Adding.** Two ways into the same floating bar:

1. **The `＋` button.** Beside the summary, which is what makes the feature discoverable. At 1u it lives in the panel the row opens, so a row carries the summary and no button.
2. **Press and hold, then drag to the emoji and release.** Anywhere on the card except the `＋` and the share button, which own their own press.

The bar is built when it first opens rather than left hidden on every card, and opening one closes any other. The card takes `touch-action: pan-y` so a sideways drag along the bar belongs to the picker while the page keeps its scroll. The hit test runs on coordinates rather than on hover, because a touch pointer captured by the card never fires `pointerenter` on the bar.

`ReactionEmoji` is where "one reaction per member per mark, and choosing the current one takes it away" is written down. It also carries each emoji's Spanish name, because left to the glyph a screen reader reads the Unicode name in its own language and 🫵 has none worth hearing.

## A mark without a photo

A mark without a photo shows **the goal's own emoji**, large, on the same hatching the standings use for a half-earned point, with a dashed border. It is visibly not a photograph, but it is an image. The share page renders the same component, so a link and the feed agree.

The consecutive-ghost count survives as a small caption (`sin foto · 2ᵃ vez seguida`) wherever there is a line for it, which is 3u, 2u and the opened row. The collapsed 1u row drops it. A ghost is half a point and keeps the streak alive.

At 3u the furniture stays where it is and drops its scrims. White on a black gradient is legible over a photograph and looks like a rendering bug over a pale ghost ground.

## Blade traps this feed has paid for

- A directive glued to the end of a word (`foto@if`) is left uncompiled, which is what keeps an email address an email address, but its `@endif` still compiles. The view then carries a stray `endif` and will not parse. Captions with a conditional in them are built in PHP.
- An anonymous component renders its attribute bag only if the root element echoes it. A card that hardcodes `class="…"` silently drops `wire:key`, and Livewire then matches cards by position.

## Deliberately not doing

Categories are **not** in this iteration. The group picked the plain cascade over the three variants that grouped the tail by category, so there is no `CategoryGuesser`, no category column, and no chips. If categories come back, the emoji to category table in `app/Services/` is the cheap way in: pure logic, no migration, and it works on every mark ever made.

Also rejected: a big/compact density toggle, a mosaic grid, horizontal per-category rails, and picking a day's cover by reaction count instead of recency.

## Still open

- **Does 🔥 leave the reaction set?** Keeping it means fire means two things. `ReactionEmoji::character()` is a one-line change. Renaming the case is a one-line data migration on top.
