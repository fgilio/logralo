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

PostgreSQL 18, hosted on PlanetScale (Franco already runs another project
there) — not the Laravel Cloud managed MySQL the kickoff skill first assumed.

Per environment:

- Production: PlanetScale Postgres, region `us-east-2`.
- CI: Postgres in a service container, for production parity on the test suite.
- Local dev and Claude Code hosted sessions: SQLite, for speed and zero setup.

The schema is simple and needs no Postgres-specific features, so the local
SQLite/Postgres split is an accepted trade. If the app grows into Postgres-only
territory, set up Postgres locally then.

## Photo storage

Laravel Cloud object storage bucket (S3-compatible) in production, wired via
the env vars Cloud injects. Local dev uses the `local` disk.

## Queue, cache, sessions

Database driver for all three, with queue workers managed by Laravel Cloud —
same pattern as jarvis. No Redis, no Horizon; at this scale the database
handles it fine.

## Mail

Resend, on its free tier (3,000 emails/month). Evaluated in preference order:
Bento has no free tier ($5/month after the first 100 emails) and Cloudflare
Email Service sending is still beta with free sending limited to verified
recipient addresses. Local dev uses the `log` mailer.

## Auth

Laravel's official Livewire starter kit. How users register and join the
group is being designed separately — not decided here.

## Domain

`logralo.fgilio.com` — a subdomain of Franco's own domain, the fallback the
meeting notes anticipated. No new domain purchase.

## Monitoring

Nightwatch, on Franco's personal account (not the work one).
