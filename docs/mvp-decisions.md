# MVP Decisions

Decisions made after the kickoff meeting. These refine the ambiguous points in
[kickoff-meeting-notes.md](kickoff-meeting-notes.md). This file is the source of
truth when the two disagree.

## Goals

- Max 5 goals per user.
- "At least one mandatory" means: each user must have at least 1 goal defined
  to participate. There is no special per-goal "mandatory" behavior.

## Photo rule ("pics or it didn't happen")

Per goal:

- Tap always marks the goal done instantly. The core action is never blocked.
- A mark without photo renders as a dimmed/ghost flame in the grid and in the
  feed, visible to the whole group. Social pressure does the verification.
- After 2 consecutive no-photo marks on the same goal, the 3rd tap opens the
  camera directly, with playful copy ("Pics or it didn't happen 📸"). Adding
  the photo makes the mark full and resets the counter.
- Later (not v1): add a photo retroactively during the same day to upgrade a
  ghost mark to a full mark.

## Streaks

- Per goal. A missed day breaks the streak, with grace: yesterday can still be
  logged until a cutoff (e.g. noon of the next day, exact cutoff TBD).
- Flame icon with day count.

## MVP scope (v1)

In:

- Auth, group, goal setup.
- One-screen daily grid (columns = goals, rows = days).
- Photo logging with the rule above.
- Streaks per goal.
- Monthly points scoring/ranking (competition exists before money).
- Photo history feed (swipe back through days).
- Gestures: tap to complete, hold for camera/note, swipe to navigate days.

Out (later):

- Money pool / entry fees / pay-to-recover-streak.
- AI difficulty weighting (only matters when money is involved).
- Categories for virality, boost/follower features.
- Retroactive photo upgrade for ghost marks.

## Database

- PostgreSQL, hosted on PlanetScale (Franco already runs another project
  there) — not the Laravel Cloud managed MySQL the kickoff skill first assumed.
- Local dev keeps the zero-config SQLite default for now. Moving local and CI
  to Postgres for parity is an open item in the kickoff skill.
