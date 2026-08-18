# Goal visibility

A goal is `group` (the default, and what every goal was before the column existed) or `private`. A private goal exists only on its owner's screen: their grid, their feed, their streak. This is the first slice of a larger model — audiences of specific people and groups, public goals, jointly-held goals — built so that widening it later is widening two scopes rather than hunting call sites.

## The two scopes

Both live on `Goal`, and they are the only places allowed to answer a visibility question:

- **`visibleTo($viewer)`** — every group goal, plus the viewer's own private ones. The feed reads through it, and so does the feed's reaction handler, so a mark id lifted from someone else's page cannot reach a private mark. Both reach it through `Mark::visibleTo($viewer)`, which owns no policy of its own: it carries the goal-level answer across the join, so a new surface that reads marks has one scope to call. When audiences arrive, the Goal scope grows an `orWhereHas` and the feed is done.
- **`sharedWithGroup()`** — group goals only, owner included. The pulse ring and the monthly table read through this one.

## Why the owner's own ring excludes their private goals

The pulse ring and the standings are group surfaces: what they show one member, they show all of them. If the owner's ring counted a private goal, either everyone's copy of the ring would (a 2/3 whose third goal nobody can find hints that a hidden goal exists), or the owner would see different numbers than everyone else on the same strip. So the group competes on what the group can witness, and the score stays comparable inside its audience: a private goal is on neither side of the ratio, exactly as an archived one is on neither side.

The visible consequence, worth knowing before it is reported as a bug: a member whose grid has three goals, one private, shows a ring of n/2 — and a member whose goals are all private drops out of the standings, the same as a member with no active goals.

## Visibility is a read filter

Flipping a goal between group and private rewrites nothing: marks carry no visibility of their own, so the change applies to the whole history in both directions, and flipping back restores the old view. This is also why there is no per-mark exception and no "was public at the time" logic anywhere.

## Recaps

A month closing reads through the same two answers. The frozen standings come from `MonthlyStandings`, so they already count group goals only, and `CloseMonth::bestStreak()` scans `sharedWithGroup()` — the recap posts into the group feed and credits a member by name, so a private goal's streak winning it would disclose the activity to everyone.

Recaps that are already frozen are the one surface the read filter does not reach, on purpose. A recap stores aggregate standings (names, counts, ranks — nothing per goal), of a competition the group watched live, and it is immutable by the same rule that closes marks with the day. Flipping a goal private later does not rewrite that record, just as unmarking a closed day is not offered either.

Share links are the one deliberate hole, unchanged from `sharing.md`: a mark's share token grants access to that mark to anyone holding the link, whatever the goal's visibility. Sharing is an act of the owner, and revoking it is on the share page as before.
