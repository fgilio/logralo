# Logralo

Daily goals app with streak counter and photo verification, built for a group of friends. Public repo, pre-scaffold stage.

## Product docs

- `docs/kickoff-meeting-notes.md` — meeting notes. Reference only, NOT a spec or task list.
- `docs/mvp-decisions.md` — post-kickoff decisions (product refinements + infra choices). Wins over the notes.
- `docs/mvp-v1-scope.md` — the v1 product spec from the 2026-08-04 scope interview. Wins over both.

## Bootstrapping the app

The Laravel app is not scaffolded yet. To do the technical kickoff, follow `.claude/skills/logralo-kickoff/SKILL.md` step by step — it is self-contained (all baseline configs in its `references/`).

## Secrets

This repo is PUBLIC. Never commit credentials. The Flux Pro license lives in the gitignored root `auth.json` locally, in the `FLUX_USERNAME`/`FLUX_LICENSE_KEY` GitHub Actions secrets for CI, and as environment variables on Laravel Cloud and hosted Claude Code sessions. Details in the kickoff skill § Secrets.
