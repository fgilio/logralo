# Routine setup: Claudio

Configuration for the Claude.ai routine that runs `SKILL.md`. The human
setting up the routine reads this once. The runtime does not load this file
at invocation.

One routine, one repo: unlike pla-stack's paper-cuts skill, this one lives in
the repo it audits, so there is no primary/additional split — and no
cross-owner session limit to trip on.

## Routine config (Claude.ai UI)

- **Name:** `Claudio`.
- **Repos:** `fgilio/logralo` only.
- **Trigger:** daily, morning America/Montevideo (e.g. 09:00 GMT-3). Early
  enough that the draft PR lands before Franco's review window and the feed
  wakes up with Claudio's photo.
- **Model:** the strongest available with large context. The survey reads
  wide slices of the codebase and leans on judgement (the seam map, the
  budget calls), then drives a real browser against production.
- **Connectors:** none. Nightwatch would be the natural pick, but the MCP
  connection in these sessions is scoped to the work workspace and cannot
  see Logralo, which reports to Franco's personal account — an empty
  `list_issues` there proves nothing (see `CLAUDE.md` § Deploy and Verify).
- **Env vars:** `LOGRALO_CLAUDIO_PASSWORD` — Claudio's production password.
  Already set in the environment config. Without it the run still ships the
  PR but cannot mark the goal, and says so.
- **Network:** Default (the run needs `logralo.fgilio.com`).
- **Behavior:** Auto-fix pull requests **on**. The run subscribes to its
  draft PR and drives CI green; without the flag the PR ships once and dies
  on the first red check.

## Routine instructions

Single-line input for the Instructions field:

> /claudio

## Pausing Claudio

Pause or delete the routine in the Claude.ai UI. His streak breaks honestly,
which is the point.
