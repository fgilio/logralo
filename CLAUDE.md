# Logralo — daily goals with streaks and photo proof

## Project Overview

A private daily-goals app for one group of friends. Each member keeps up to five goals, marks them with one tap, and proves them with a photo. Streaks reward showing up; the monthly score rewards proof. Everything happens on one screen.

The product spec is `docs/mvp-v1-scope.md` — it wins over `docs/mvp-decisions.md`, which wins over `docs/kickoff-meeting-notes.md`. `docs/architecture/` explains the rules that were not obvious to implement.

## Tech Stack

- **Laravel 13** with PHP 8.5. Where the default `php` is older (Herd keeps several versions side by side, and the sandbox image ships 8.4), anything that shells out goes through `./scripts/php` (the git hooks already do). By hand: `./scripts/php artisan …`, `./scripts/php "$(command -v composer)" test`. In a hosted session the bootstrap installs the pinned series and makes it the default, so commands run directly there — see § Cloud sessions.
- **Livewire 4 single-file components + Flux Pro**, Tailwind 4, Vite
- **SQLite** locally and in hosted sessions, **PostgreSQL 18** in CI and on PlanetScale in production. Production shares jarvis's PlanetScale cluster and keeps its own database inside it — `docs/architecture/planetscale-shared-cluster.md`
- **Pest v5**, PHPStan level 8 via Larastan, Pint, Rector
- **laravel/ai** is installed for the post-MVP goal-difficulty judge; nothing uses it yet and there is no `app/Ai/`

## Architecture

The layering is the thing to keep. It is enforced by `tests/Arch/`.

### `app/Services/` — pure logic, no Eloquent

- `StreakCalculator` — consecutive marked days, given plain date arrays
- `ScoreCalculator` — ordering and the shared podium
- `PhotoRule` — "pics or it didn't happen": when the next mark owes a photo
- `PhotoProcessor` — uploads to feed derivatives and to avatars, EXIF stripped
- `ShareCardComposer` / `ShareCardRenderer` — the WhatsApp unfurl image, drawn and cached
- `StreakMilestone` — which streaks are worth interrupting somebody for
- `Gravatar` — the URL for the picture WordPress may already have for an email, built rather than fetched

### `app/Queries/` — the read side, where Eloquent lives

- `GroupPulse`, `MonthlyStandings`, `FeedPage`, `GoalHistory`, `SharedEntry`, `MarkEntries`, `Members`
- `Members` is the group's roster, held scoped for the request. `GroupPulse`, `MonthlyStandings` and every avatar read it rather than querying `users` themselves

### `app/Actions/` — the write side, one unit of work each

- `MarkGoal`, `UnmarkGoal`, `ToggleReaction`, `CreateGoal`, `RenameGoal`, `ArchiveGoal`, `RestoreGoal`, `CloseMonth`, `IssueMagicLink`, `RecordShareVisit`, `RevokeSharing`, `ResumeSharing`, `UpdateAvatar`, `RemoveAvatar`, `SendFeedback`
- `SendFeedback` is the only one that mails: the row in `feedback` is the deliverable and `App\Mail\FeedbackReceived` is a best-effort nudge to `LOGRALO_FEEDBACK_EMAIL`, wrapped in `rescue` so a mail provider cannot swallow what a member typed

### `app/ValueObjects/` — final readonly

- `UserClock` is the important one: every day boundary, grace window and month edge in the app is resolved through a member's own timezone, and nothing else is allowed to do that arithmetic.

### UI

- `resources/views/pages/` — Livewire page components (`pages::today`)
- `resources/views/livewire/` — nested Livewire components (`goal-card`, `feed`)
- `resources/views/components/` — anonymous Blade only, optimised by Blaze

Livewire SFCs never go in `resources/views/components/`: a non-emoji file there is also a Blade anonymous component, and the two resolve to the same name.

## Key Commands

```bash
composer setup               # first run: deps, .env, database, assets
composer dev                 # server + queue + logs + vite
composer test                # type coverage, phpstan, lint, unit, browser
composer lint                # rector + pint + prettier, writing fixes

php artisan logralo:seed-member "Nombre" email@example.com America/Montevideo
php artisan logralo:close-months            # normally hourly on the scheduler
php artisan migrate:fresh --seed            # local demo data (DemoSeeder)
bash scripts/branding.sh                    # regenerate PWA icons and splashes
```

## Cloud sessions

On Claude Code on the web and Codex Cloud the environment provisions in the background: `scripts/cloud/setup.sh` restores the PHP binary and `vendor/` from the CI-built snapshot, writes `.env` from `.env.example`, installs the node modules and builds the assets, migrates the SQLite database, and installs the git hooks. Run `bash scripts/cloud/await.sh` before tests or asset-dependent work when a fresh session may still be finishing setup; it exits non-zero if the bootstrap failed, so `await.sh && composer test` stops instead of running against a half-built environment.

Two sandbox constraints drive the whole design, and both are measured rather than assumed: the image ships PHP 8.4 while `composer.json` requires `^8.5`, and the egress proxy 403s every third-party `api.github.com` dist archive, which no `--prefer-source` fallback escapes because `phpstan/phpstan` is published dist-only. So both the runtime and `vendor/` come from a draft release on this repo that `.github/workflows/cloud-snapshot.yml` builds and `scripts/cloud/snapshot.sh` restores.

The runtime is not a lookalike: CI and the snapshot build both install PHP with `publicala/php-ci-static` and pass the same `ini-values`, and the snapshot ships that exact binary, so a session runs the build CI ran rather than a distro package of the same version. That is also why nothing here carries an extension list — the static build links them all in, `imagick` included. `scripts/cloud/SETUP.md` is the full account, including what to do when a dependency bump lands before its snapshot is built.

## Environment Variables

See `.env.example`. The ones that are not self-explanatory:

- `LOGRALO_GRACE_CUTOFF_HOUR` — a day D closes at D+1 at this hour, local to each member. Changing it changes streaks, grace and month close together.
- `LOGRALO_PHOTO_DISK` — `photos` locally; in production it is `private`, the name the R2 bucket is mounted under on Laravel Cloud. That bucket is private, so production also sets `LOGRALO_PHOTO_SIGNED_URLS=true`.
- `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local` — uploads are decoded from the local filesystem before being stored, so the temp disk must stay local.
- `IMAGE_DRIVER` — GD by default; only the Imagick driver can read HEIC, and only where ImageMagick has libheif.
- `LOGRALO_FEEDBACK_EMAIL` — where "¿Qué pasó?" is mailed as it lands. Empty is a valid setting: every message is stored in the `feedback` table either way, and this only decides whether an inbox is told.
- `LOGRALO_GRAVATAR` — whether an avatar with no upload behind it tries the member's Gravatar before falling back to initials. The URL is built from a hash of the email and fetched by the browser, so this never puts the server on the network — `docs/architecture/photos-and-onboarding.md`.

## Laravel-First Conventions

Prefer Laravel utilities over native PHP equivalents:

- **Filesystem**: `File::exists()`, `File::get()`, `File::put()`, `File::ensureDirectoryExists()` — never `file_exists`, `file_get_contents`
- **Collections**: `collect()` pipelines instead of nested `array_map`/ `array_filter`/`array_values`
- **Strings**: `Str::` helpers and `Str::of()` instead of `mb_substr`/`explode` chains
- **Arrays**: `Arr::get()`, `Arr::has()` instead of `isset()` chains

## Observability

One canonical `Log::info()` per unit of work, emitted in `finally`, with no manual context array. Data goes through `Context::add('logralo.*', …)`. Warnings always carry a `reason`. No `Log::debug()`. Event names are lowercase and dot-separated: `mark.create.handled`, `month.close.handled`.

A unit of work is an **Action** — plus the two entry points that own one without an Action behind them, `CloseMonthsCommand` and `MagicLinkController`. Queries and Services do not log: a read runs on every render and every anonymous crawler fetch, and logging those buries the events that carry an outcome. Review bots read the rule above and ask for a log in `SharedEntry` or `ShareCardRenderer` — that is the rule applied a layer too wide.

Key context keys: `logralo.user_id`, `logralo.goal_id`, `logralo.mark_id`, `logralo.marked_on`, `logralo.feedback_id`, `logralo.outcome`, `logralo.reject_reason`.

## Pull Request Review Comments

Every review comment gets a reply and a resolved thread before the PR is considered ready — including the ones you decline. An unanswered thread reads as unnoticed, and a silently-resolved one reads as dismissed.

1. Read the threads with `pull_request_read` / `method: get_review_comments`, which carries `is_resolved` and the thread's `PRRT_…` node id.
2. Reply on the comment's numeric id (`#discussion_r…`), then resolve the thread by its node id. Both steps, every thread.
3. **Fixed** → name the commit and say what changed, in one or two sentences. Not "done".
4. **Declined** → say so plainly and give the reason from this codebase: the arch test, the convention, the measurement. Verify the claim first — bots cite `CLAUDE.md` rules they have over-applied, and the answer has to be checkable rather than asserted.
5. **Partly** → say which part, and why the rest was left. A deviation nobody explains gets re-reported next review.
6. End every reply with the Claude Code attribution footer.

Two recurring false positives, both from misreading rules above: a log demanded in a Query or Service (see Observability), and `Storage` called a layering violation in `app/Services` (`tests/Arch/ServicesTest.php` bans Eloquent and `DB`, not the filesystem — `PhotoProcessor` has always written derivatives).

## Deploy and Verify

Laravel Cloud auto-deploys every push to `main`. How production is wired, and the traps in it, are in `docs/architecture/laravel-cloud-production.md`.

1. `gh run watch` until CI is green
2. Wait 3–5 minutes for the rollout, then `cloud deployment:list --json -n` until the newest one says `deployment.succeeded`
3. Hit the app: `/up`, `/entrar`, and `/` redirecting to `/entrar`
4. Check for errors in Nightwatch. **The Nightwatch MCP connection in a Claude session is scoped to a work workspace and cannot see Logralo**, which reports to Franco's personal account — an empty `list_issues` there proves nothing. Ask Franco, or read the Nightwatch web interface.

Never conclude that a resource is missing from `cloud environment:get --fields=environmentVariables`. That list omits everything Cloud injects at deploy time. Ask the running app with `cloud tinker production -n --code='…'`.
