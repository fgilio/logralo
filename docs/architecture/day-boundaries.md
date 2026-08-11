# Day boundaries, grace and month close

Every rule in Logralo that involves time is a _calendar day_ rule, resolved in the member's own timezone. This is the part of the app most likely to be broken by a well-meaning change, so here is the whole model in one place.

## The rule

A member's day **D** accepts marks from `D 00:00` until `D+1 12:00`, local to that member, and then closes forever. The hour comes from `config('logralo.grace_cutoff_hour')`.

Everything else falls out of that:

- **Today** is always open.
- **Yesterday** is open until noon — that is the grace banner's entire condition.
- **A streak** breaks when a day _closes_ unmarked. An unmarked day that is still open is skipped, not counted as a miss, which is why an untouched today never breaks your flame.
- **A mark becomes immutable** when its day closes. Un-marking is allowed right up to that instant and refused after it.
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
- **Active goals** is the count right now. Marks on archived goals count for neither the numerator nor the denominator: archiving "stops the goal counting from that day", and removing it from both sides is what stops a member archiving four goals on the 31st to inflate their percentage.
- A member with **no active goals** does not appear in the table at all.
- **Ties share a rank** and the next rank skips (1, 1, 3), so "runner-up" never means "beat nobody".

At month close the standings are **frozen** onto the recap row. Nothing that happens in September can rewrite August.

## Best streak of the month

The recap's best streak is the highest flame anyone _reached_ during the month, including a run that started earlier. That is what the member actually saw on their card, so it is what the recap celebrates.
