# Day boundaries, grace and month close

Every rule in Logralo that involves time is a _calendar day_ rule, resolved in the member's own timezone. This is the part of the app most likely to be broken by a well-meaning change, so here is the whole model in one place.

## The rule

A member's day **D** accepts marks from `D 00:00` until `D+1 12:00`, local to that member, and then closes forever. The hour comes from `config('logralo.grace_cutoff_hour')`.

Everything else falls out of that:

- **Today** is always open.
- **Yesterday** is open until noon — that is the grace banner's entire condition.
- **A streak** breaks when a day _closes_ unmarked. An unmarked day that is still open is skipped, not counted as a miss, which is why an untouched today never breaks your flame.
- **A mark becomes immutable** when its day closes, or when its month is recapped, whichever comes first. Un-marking is allowed right up to that instant and refused after it.
- **A month** is closeable only once its last day has closed for _every_ member. With members in different timezones there is no single moment that is "the end of the month" for the group, so `logralo:close-months` runs hourly and asks.

`App\ValueObjects\UserClock` owns all of this arithmetic. Nothing else in the app is allowed to compute a day boundary — if a new feature needs one, add a method there.

## Storage

`marks.marked_on`, `monthly_recaps.month` and `monthly_recaps.posted_on` are **calendar days, not instants**. They are cast through `App\Casts\LocalDate`, which reads and writes exactly `Y-m-d`.

This is not a stylistic choice. Laravel's built-in date casts write through the connection's datetime format, which Postgres coerces back to a date but SQLite stores verbatim as `2026-08-11 00:00:00`. Every `where('marked_on', $date)` then matches nothing — locally and in hosted sessions, while passing in CI on Postgres. The custom cast removes the difference between the two engines.

Never call `setTimezone()` on a value that came out of one of these columns. It is a wall-clock day, and shifting it moves it by ±1.

## Scoring

```
score = (full marks × 1 + ghost marks × 0.5) / (days elapsed × active goals)
```

- **Days elapsed** includes today, counted on the member's own calendar. Every member is measured the same way, so the morning dip applies to everyone.
- **Active goals** is the count of the goals that existed by the last day the window covers, on the member's own calendar. For the live table that is every active goal, since all of them were created on or before today.
- **At close this matters.** A month is scored twelve to thirty-eight hours after it ended, so a goal created in that gap belongs to no part of it. Counting it would push the denominator up and freeze a percentage the member never had a chance to earn. Marks on such a goal are dropped too: grace lets a member create a goal on the 1st and mark it for the 31st, and counting that mark while its goal is out of the denominator would put a frozen score above 100%.
- **Marks on archived goals** count for neither the numerator nor the denominator once the archive day enters the scoring window: archiving "stops the goal counting from that day", and removing it from both sides is what stops a member archiving four goals on the 31st to inflate their percentage. Archiving after a window ends cannot rewrite it. A goal archived on the 1st still belongs to the month that ended on the 31st.
- A member with **no active goals** does not appear in the table at all.
- **Ties share a rank** and the next rank skips (1, 1, 3), so "runner-up" never means "beat nobody".

At month close the standings are **frozen** onto the recap row. Nothing that happens in September can rewrite August.

That holds only because the marks table is shut too. Closing asks every member's clock and marking asks one, so the two agree only while the set of clocks stays fixed — a member who edits their timezone westward, a member seeded after the sweep, or a raised `LOGRALO_GRACE_CUTOFF_HOUR` each reopen a day whose score is already frozen. `MarkGoal` and `UnmarkGoal` therefore refuse any day whose month carries a recap, with `MonthClosedException`. The recap row is the durable fact; recomputing closability from the new clocks would answer for the wrong month.

One gap is left open: `CloseMonth` reads the standings and inserts the recap without a lock. A mark landing between those two statements is counted by neither rule, and an un-mark landing there leaves the frozen score standing on a mark that no longer exists.

Reaching it takes more than winning the milliseconds. `isClosable()` and `openDays()` are the same predicate — `now < closesAt(last day)` — read off the same member's clock, so a day open to a member while their month is closing means their clock changed, or they were created, between the two reads. A lock would have to be taken on every tap, the hottest path in the app, to serialize against a sweep that runs once a month.

## Best streak of the month

The recap's best streak is the highest flame anyone _reached_ during the month, including a run that started earlier. That is what the member actually saw on their card, so it is what the recap celebrates.
