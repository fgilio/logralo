# The cascade feed

The feed is one list, in one shape, ordered strictly newest to oldest. There is no big/compact toggle, no mosaic, no category grouping: the only thing that varies is **how tall a card is**, and that is decided by its position within its day.

This replaces the old feed, where every mark was the same ~380 px card. Six members with five goals each put twenty marks in a day, which was nine phone screens — more than one page of `feed.page_size`, so the feed could not show a single day without loading more.

## The ladder

Inside each day, counting marks only:

| Position          | Height |
| ----------------- | ------ |
| 1st (most recent) | 3u     |
| 2nd               | 2u     |
| 3rd and after     | 1u     |

The ladder **restarts at every day divider**, so each day gets its own cover. Order is purely chronological and the slot is purely positional — the mark that is newest in a day gets 3u, whatever it is.

That last point overrides an earlier draft rule ("a mark without a photo never takes the podium"). It existed because a photoless mark at 3u would have been a giant empty box. It no longer applies: a mark without a photo now renders the goal's emoji at full size (see below), which fills a 3u slot perfectly well. Reordering to keep photos on top would break the chronology, and the chronology wins.

A month recap card keeps its own full-width card and does **not** consume a ladder slot; the day's ladder counts marks only.

## The unit

```
1u  = 76 px      the compact row: a 52 px thumbnail plus its air
gap = 8 px
2u  = 76×2 + 8   = 160 px
3u  = 76×3 + 8×2 = 244 px
```

The heights are fixed. A card is 3u whether or not it carries a note, so the rhythm of the list never depends on content — with the single exception of the 3u note band, below.

At roughly 316 px of content width a 4:3 photo is 237 px tall, which is within a few pixels of 3u. That is where the unit came from, and it means the 3u slot is close to showing a landscape photo whole. It is a coincidence, not a guarantee: on a wider phone the same photo is taller than 244 px and gets cropped.

Photos fill their slot with `object-cover`. `marks.photo_width` and `marks.photo_height` are already stored, so the crop can favour the centre on portrait photos rather than guessing.

## What each height shows

**3u — the day's most recent mark.** The photo fills the card, edge to edge. Over it: a top scrim with the avatar, name, goal and relative time on the left and the streak on the right; a bottom scrim with the reaction summary and the `＋` button.

**2u — the second most recent.** A 140 px square photo on the left. On the right, a column with name and `goal · time`, the streak, the note, and the reaction summary. With no note the column is centred and reads exactly as it did before.

**1u — everything else that day.** Thumbnail, then two lines: `Nombre · 🎯 Objetivo` and the note (or the time). On the right, the reaction summary and the streak. Tapping the row expands it in place with the photo and the reaction bar.

Sharing to WhatsApp survives at every height, since that is half of what the app is for: the button rides the top scrim at 3u, sits beside the reactions at 2u, and waits inside the expanded panel at 1u.

## The note

`marks.note` is 140 characters, optional, and already in the database. Where it goes was decided per height:

- **3u — its own band under the photo.** The card becomes `3u + 44 px`. The photo is never covered by text. This is the one place where a card's height depends on its content: a 3u mark with a note is taller than one without.
- **2u — in the right-hand column**, clamped to two lines. Height stays exactly 2u.
- **1u — the second line**, italic, truncated with an ellipsis. When there is no note that line shows the relative time instead. Height stays exactly 1u.

The 3u band is also where a comment count will go when comments arrive.

## The streak is not a reaction

`x-flame` renders a streak as the **brand mark** — the two-tone flame that is the app's identity — plus the count in the display face. It is never an emoji, at any height. Reactions are always emoji characters put there by a person.

The two still read alike, because the brand mark is a two-tone version of the same fire glyph the 🔥 emoji depicts, and `ReactionEmoji::Fire` is one of the five reactions. Whether to drop 🔥 from the reaction set is still open (see below).

## Reactions: showing and adding

These are two different problems, and the old feed solved both with the same control. A five-emoji bar under every card works at 380 px and does not fit in a 76 px row.

**Showing** — a summary that is always visible at every height: the emoji already used, overlapped in a small stack, plus the total. About 20 px. Your own reaction is ringed in ember.

**Adding** — two ways into the same floating bar:

1. **The `＋` button.** A small round button in the corner of the card, which opens the bar. This is what makes the feature discoverable.
2. **Press and hold, then drag to the emoji and release.** The WhatsApp gesture, available on the photo, the row, the thumbnail — anywhere on the card. Same bar, same component.

The bar is a **selector, not a set of checkboxes**: a member has exactly one reaction per mark, and tapping the current one removes it.

## A mark without a photo

The old ghost treatment — a grey box reading `🌫️ marcó sin foto` tucked under the card — is gone. A mark without a photo now shows **the goal's own emoji**, large and sharp, on a flat ghost-tinted ground with a dashed border. It is still visibly not a photograph, but it is an image rather than a penalty box.

The consecutive-ghost count survives as a small caption (`sin foto · 2ᵃ seguida`) at 3u and 2u, and is hidden at 1u where there is no room. Scoring is untouched: a ghost is still half a point and still keeps the streak alive.

At 3u the furniture stays exactly where it is but drops its scrims: white on a black gradient is legible over a photograph and looks like a rendering bug over a pale ghost ground, so a mark without a photo wears the card's own colours.

## Deliberately not doing

Categorising the feed was where this started, and it is **not** in this iteration. The group picked the plain cascade over the three variants that grouped the tail by category, so there is no `CategoryGuesser`, no category column, and no chips. If categories come back, the emoji → category table in `app/Services/` is the cheap way in: pure logic, no migration, and it works on every mark ever made.

Also dropped: the big/compact density toggle, the mosaic grid, the horizontal per-category rails, and picking a day's cover by reaction count instead of recency.

## Still open

- **Does 🔥 leave the reaction set?** Keeping it means fire means two things. `ReactionEmoji::character()` is a one-line change; renaming the case is a one-line data migration on top.

The other question this doc left open — whether the `＋` button appears at 1u — was settled while building it. It does not: a 76 px row carries the summary but no button, and the way to react to one is to tap it open, which is also the way to see the photo. Holding still works everywhere.
