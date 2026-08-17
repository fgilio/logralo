# Routine setup: Claudio

Configuration for the Claude.ai routine that runs `SKILL.md`. The human
setting up the routine reads this once. The runtime does not load this file
at invocation.

One routine, one repo: unlike pla-stack's paper-cuts skill, this one lives in
the repo it audits, so there is no primary/additional split — and no
cross-owner session limit to trip on.

The routine already exists: `trig_014H7kcBXXSsLm6rrsaEca3Y`
(https://claude.ai/code/routines/trig_014H7kcBXXSsLm6rrsaEca3Y). What follows
is the configuration it carries, so it can be rebuilt or judged at a glance.

## Routine config

- **Name:** `Claudio`.
- **Repos:** `fgilio/logralo` only. The skill lives on `main`, so that is the
  branch the run reads.
- **Trigger:** `0 12 * * *` — daily 09:00 America/Montevideo. Early enough
  that the draft PR lands before Franco's review window and the feed wakes up
  with Claudio's photo.
- **Environment:** `env_01Fsxp44hSTzf32bQh6pdL42` (Articlepocket - Logralo).
  It carries the password and reaches `logralo.fgilio.com`.
- **Model:** `claude-opus-5[1m]`. The survey reads wide slices of the
  codebase and leans on judgement (the seam map, the budget calls), then
  drives a real browser against production.
- **Connectors:** none. Nightwatch would be the natural pick, but the MCP
  connection in these sessions is scoped to the work workspace and cannot
  see Logralo, which reports to Franco's personal account — an empty
  `list_issues` there proves nothing (see `CLAUDE.md` § Deploy and Verify).
  The routines API attaches every connected connector on create, so this was
  trimmed afterwards with `clear_mcp_connections`. Re-check it after any
  edit through the web UI.
- **Env vars:** `LOGRALO_CLAUDIO_PASSWORD` — Claudio's production password.
  Already set in the environment config. Without it the run still ships the
  PR but cannot mark the goal, and says so.
- **Network:** Default (the run needs `logralo.fgilio.com`).
- **Behavior:** Auto-fix pull requests **on**. The run subscribes to its
  draft PR and drives CI green; without the flag the PR ships once and dies
  on the first red check.

## Routine instructions

The prompt points at this skill by path rather than by slash command, the
same way pla-stack's routines do:

> Read .claude/skills/claudio/SKILL.md in this repo and follow it end to end.
> …

The rest of the prompt restates the identity, the one-fix rule, the
production URL and the never-mark-through-tinker rule, so a run still has its
guardrails if the file ever fails to load.

## Pausing Claudio

Pause or delete the routine in the Claude.ai UI. His streak breaks honestly,
which is the point.
