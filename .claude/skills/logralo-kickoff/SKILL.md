---
name: logralo-kickoff
description: >
  Self-contained kickoff guide for bootstrapping the Logralo Laravel app: stack,
  conventions, testing, linting, CI, hosted-session bootstrap, and Laravel Cloud
  deployment. Use when scaffolding the app, wiring config/CI, or deploying.
  All baseline configs live in references/ — no external repo access needed.
---

# Logralo Project Kickoff

Distilled from Publica.la's `pla-app-project-kickoff` skill, adapted for Logralo. Read and follow step by step. Everything needed is in this repo — the source skill, its private reference repos, and PLA org infrastructure are NOT required.

**Deltas vs the PLA original** (keep these in mind when in doubt):

1. **Latest versions of everything.** Pest 5 (not 4) with the **Tia engine** on (§ 6), latest Pint/Rector/Larastan/PHPStan, PHP 8.5, latest Tailwind 4.x / Vite. Install unpinned to get the latest and verify with `composer outdated -D` / `npm outdated`. Version numbers in reference files are baselines, not pins.
2. **Personal Laravel Cloud account** (Franco's), not the Publica.la org.
3. **Public repo.** Secrets are NEVER committed — no committed `auth.json` (the PLA pattern relies on private repos). See § Secrets.
4. **GitHub-hosted runners** (`ubuntu-latest`): public repo = unlimited free Actions minutes. No Depot runners, no org rulesets, no fleet audit machinery.

## 1. Stack

| Component | Version / Tool | Notes |
| --- | --- | --- |
| Framework | Laravel 13 (latest) | `laravel/framework` ^13.x |
| PHP | 8.5 (latest stable) | `"php": "^8.5"`; drop to ^8.4 only if a dependency or Laravel Cloud blocks it |
| Frontend | Livewire 4 SFCs | Single File Components only |
| UI Kit | Flux Pro | Credentials via secrets, see § Secrets |
| Blade compiler | Blaze | `livewire/blaze` — auto for Flux; opt-in for app Blade components via `@blaze` or `Blaze::optimize()->in(...)`, then `php artisan view:clear` |
| CSS | Tailwind v4 (latest) | Via `@tailwindcss/vite` plugin |
| DB (local) | SQLite | Zero-config local dev and hosted Claude Code sessions |
| DB (CI) | PostgreSQL 18 | Service container in `ci.yml`, for production parity |
| DB (production) | PostgreSQL 18 on PlanetScale | Not a Laravel Cloud managed database — see `docs/mvp-decisions.md` |
| Photo storage | Laravel Cloud bucket | S3-compatible object storage for goal photos; `local` disk in dev |
| Queue / cache / sessions | Database driver | Queue workers managed by Laravel Cloud (jarvis pattern); no Redis, no Horizon |
| Mail | Resend | Free tier; `log` mailer in local dev |
| Auth | Livewire starter kit | Registration/join flow designed separately — see Open items |
| Testing | Pest v5 (latest) | Always `it()`, never class-based; **Tia engine on locally** (§ 6); plugins on matching major |
| AI | laravel/ai | For the goal-difficulty judging feature |
| Monitoring | Nightwatch | `laravel/nightwatch`, Franco's personal account |
| Build | Vite (latest) | With `@tailwindcss/vite` + `laravel-vite-plugin` |

## 2. Secrets (public repo — read first)

The Flux Pro license is the only secret at kickoff time. It must never appear in a committed file, this skill included.

| Where | Mechanism |
| --- | --- |
| Local dev (Franco's machine) | `auth.json` at repo root, gitignored (already in place) |
| GitHub Actions | Repo secrets `FLUX_USERNAME` + `FLUX_LICENSE_KEY` (already set); CI writes http-basic before `composer install` |
| Claude Code web sessions | Set `FLUX_USERNAME` + `FLUX_LICENSE_KEY` as session/project environment variables; `scripts/cloud/setup.sh` writes `auth.json` from them |
| Laravel Cloud | Set both as environment variables; prepend `composer config http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"` to the build commands |

Keep `/auth.json` in `.gitignore` (Laravel ships it there — never remove that line). Known limitation: fork PRs can't read Actions secrets, so `composer install` fails for external contributors' CI until a maintainer pushes their branch.

Flux repo registration in `composer.json` (this part is public and committed):

```json
"repositories": [{ "name": "flux-pro", "type": "composer", "url": "https://composer.fluxui.dev" }]
```

## 3. Project Bootstrap

The app lives at the **repo root** of `fgilio/logralo` (not a subdirectory). Scaffold into a temp dir and move the files in, preserving the existing `README.md`, `docs/`, `.claude/`, `.gitignore`, and the gitignored `auth.json`.

### 3.1 Create Laravel project

```bash
composer create-project laravel/laravel logralo-tmp
rsync -a --ignore-existing logralo-tmp/ . && rm -rf logralo-tmp
```

Merge `.gitignore` by hand (keep Laravel's, retain `/auth.json`).

### 3.2 composer.json

- `"php": "^8.5"` in `require`.
- Flux Pro repository block (§ 2).
- Required: `laravel/ai`, `laravel/nightwatch`, `livewire/blaze`, `livewire/flux-pro`, `livewire/livewire`. No `laravel/horizon` — queues run on the database driver with Cloud-managed workers.
- Dev: `driftingly/rector-laravel`, `larastan/larastan`, `laravel/pail`, `laravel/pint`, `pestphp/pest` (**v5**), `pestphp/pest-plugin-browser`, `pestphp/pest-plugin-laravel`, `pestphp/pest-plugin-type-coverage`, `rector/rector`.
- Install everything unpinned (`composer require pkg` / `composer require pkg --dev`) so the latest majors resolve. Then run `composer outdated -D` and confirm nothing lags a major. Pest plugins must match Pest's major.
- Scripts: copy the block from `references/composer-scripts.json` (adapt the `dev` concurrently line if services change). `composer setup` bootstraps everything; `composer dev` runs all services.

### 3.3 package.json + config files

Copy from `references/` (versions there are baselines — install latest):

| File | Destination |
| --- | --- |
| `package.json` | repo root (keep `lefthook` in devDependencies: hosted sandboxes and CI use `node_modules/.bin/lefthook`) |
| `app.css` | `resources/css/app.css` (Tailwind v4 entry + Flux imports) |
| `vite.config.js` | repo root |
| `pint.json` | repo root |
| `rector.php` | repo root |
| `phpstan.neon` | repo root |
| `lefthook.yml` | repo root |
| `prettier.config.mjs` | repo root |
| `.prettierignore` | repo root |
| `.editorconfig` | repo root |
| `livewire.php` | `config/livewire.php` (SFC mode, page locations) |
| `Pest.php` | `tests/Pest.php` (add imports; fakes, freezeTime, RefreshDatabase, Tia config) |
| `ci.yml` | `.github/workflows/ci.yml` |
| `tia-baseline.yml` | `.github/workflows/tia-baseline.yml` (records the shared Tia baseline; gates nothing) |
| `claude-md-template.md` | template for root `CLAUDE.md` (replace the pre-kickoff CLAUDE.md) |
| `claude-settings.json` | `.claude/settings.json` |
| `session-start.sh` | `.claude/hooks/session-start.sh` (chmod +x) |
| `cloud-setup.sh` | `scripts/cloud/setup.sh` (chmod +x) |
| `cloud-link.sh` | `scripts/cloud-link.sh` (chmod +x) |

`.env.example`: `DB_CONNECTION=sqlite`. CI overrides it with `DB_*` job env vars pointing at its Postgres service (§ 8).

### 3.4 Hosted-session bootstrap

`.claude/hooks/session-start.sh` (SessionStart hook wired via `.claude/settings.json`) dispatches to `scripts/cloud/setup.sh`, so every Claude Code session — including web sessions from the phone — lands on a runnable app: composer deps (writing `auth.json` from `FLUX_*` env vars when missing), npm ci, `.env`, SQLite file, migrations, asset build, lefthook install.

Kept intentionally simple (the PLA fleet's heavier vendored bootstrap with static-PHP snapshots is overkill here; revisit only if hosted sandboxes hit restricted-egress failures). Rule that must survive any rewrite: the asset build reads composer `vendor/` (Flux CSS import), so `npm run build` runs after `composer install`, never before or beside it.

### 3.5 Laravel Cloud (personal account)

```bash
composer global require laravel/cloud-cli   # if missing
cloud auth                                  # authenticate with the PERSONAL account
cloud skills:install --global               # deploying-laravel-cloud skill (one-time)
```

Create the app on Laravel Cloud (dashboard or CLI), then link non-interactively:

```bash
CLOUD_ORG_ID=org-... bash scripts/cloud-link.sh logralo
```

Commit `.cloud/config.json` — it holds resource IDs (not secrets) and is required for non-interactive CLI usage by agents. All cloud commands take `-n` and `--json`. Set the `FLUX_*` env vars and the build-command prefix per § 2. Laravel Cloud auto-deploys every push to `main`.

Database: PostgreSQL on PlanetScale, not a Laravel Cloud managed database. Provision it on PlanetScale in `us-east-2`, then set `DB_CONNECTION=pgsql` plus the PlanetScale connection env vars on the Cloud environment.

Domain: `logralo.fgilio.com`. Add it as a custom domain on the Cloud environment and point the DNS record where Cloud instructs.

Other Cloud resources: an object storage bucket for goal photos (Cloud injects the S3 env vars — point the default `FILESYSTEM_DISK` at it in production), and managed queue workers running the `database` queue driver (jarvis pattern — no Redis, no Horizon). Mail goes through Resend: set the Resend API key on the Cloud environment and `MAIL_MAILER=resend`.

## 4. Code Conventions

- `declare(strict_types=1)` in every PHP file
- Enums: string-backed, names describe values
- Models: `casts()` method, ULIDs via `HasUlids`, final
- Services: final, injected via constructor, no Eloquent dependencies
- Jobs: final, implement `ShouldQueue`
- Value objects: `readonly` classes
- Frontend: Livewire 4 SFCs only (no class-based components), pages in `resources/views/pages/`
- Flux Pro for all UI components. Never hand-roll what Flux provides.
- Strict comparisons (`===`) everywhere; `DateTimeImmutable` over `DateTime`
- No superfluous elseif/else — early returns
- Ordered class elements: traits, cases, constants, properties, construct, public, protected, private
- Rector: `codingStyle: true` (not `strictBooleans`), skip `EncapsedStringsToSprintfRector` — keep `"Checking {$name}..."` interpolation

### Laravel-First Conventions

Always prefer Laravel utilities over native PHP equivalents. Hard rule.

**Filesystem** — `File` facade, never native functions:

| Instead of | Use |
| --- | --- |
| `is_file($path)`, `file_exists($path)` | `File::exists($path)` |
| `is_dir($path)` | `File::isDirectory($path)` |
| `file_get_contents($path)` | `File::get($path)` |
| `file_put_contents($path, $data)` | `File::put($path, $data)` |
| `mkdir($path, 0755, true)` | `File::ensureDirectoryExists($path)` |
| `rmdir($path)` / `unlink($path)` | `File::deleteDirectory($path)` / `File::delete($path)` |

**Collections** — `collect()` pipelines, never nested array functions:

```php
// Bad
$result = array_values(array_filter(array_map($fn, $items)));

// Good
$result = collect($items)->map($fn)->filter()->values()->all();
```

Prefer semantic methods: `->reject()` over negated `->filter()`, `->contains()` over `in_array()`, `->pluck()` over `array_column()`, `->keys()` over `array_keys()`.

**Strings** — `Str::` helpers and `Str::of()` fluent API:

| Instead of | Use |
| --- | --- |
| `str_starts_with($s, $p)` | `Str::startsWith($s, $p)` |
| `str_contains($s, $p)` | `Str::contains($s, $p)` |
| `str_ends_with($s, $p)` | `Str::endsWith($s, $p)` |
| `mb_substr` / `mb_strpos` parsing | `Str::before($s, ':')` / `Str::after($s, ':')` |
| `explode` + `array_map` + `array_filter` | `Str::of($s)->explode("\n")->map(...)->filter()` |

**Arrays** — `Arr::get($arr, 'a.b')` over `isset()` chains, `Arr::has()` over `array_key_exists()`.

**PHPStan note**: `Collection::values()->all()` returns `array<int, T>`, not `list<T>`. Add `/** @var list<T> */` when the declared return is `list<T>`.

## 5. Directory Structure

```
app/
  Actions/          # Single-purpose action classes
  Ai/               # AI agents, tools, middleware (Agents/, Middleware/, Tools/)
  Console/          # Artisan commands
  Enums/            # String-backed enums
  Exceptions/       # Custom exceptions
  Http/             # Controllers, middleware, requests
  Jobs/             # Queued jobs (ShouldQueue)
  Models/           # Eloquent models (HasUlids)
  Providers/        # Service providers
  Services/         # Business logic services
  ValueObjects/     # Readonly value objects
config/             # ai.php, livewire.php, ...
database/           # factories/, migrations/, seeders/
docs/               # architecture/, patterns/, plans/ + kickoff notes
resources/
  css/  js/
  views/
    components/     # Reusable Blade/Livewire components
    layouts/
    pages/          # Livewire SFC page components
scripts/            # cloud/setup.sh, cloud-link.sh
tests/
  Arch/  Browser/  Feature/  Unit/  fixtures/  Pest.php
```

## 6. Testing

Pest v5. Always `it()` syntax. Test types:

| Type | Location | When |
| --- | --- | --- |
| Arch | `tests/Arch/` | Code quality, conventions, layer deps |
| Unit | `tests/Unit/` | Pure logic, no DB or framework |
| Feature | `tests/Feature/` | Livewire, HTTP, jobs, services |
| Browser | `tests/Browser/` | Full-stack rendering via Playwright (`pest-plugin-browser`) |

**Non-negotiable quality gates**: 100% type coverage (`--min=100`), PHPStan level 8, Pint clean, Rector clean.

`tests/Pest.php` from `references/Pest.php`: RefreshDatabase, `Http::preventStrayRequests()`, `Process::preventStrayProcesses()`, `Sleep::fake()`, `freezeTime()`, and the Tia config below.

### 6.1 Tia engine (test impact analysis) — required

Pest 5's Tia engine re-runs only the tests a change actually touched and replays cached results for the rest. It is not optional here: the whole point of a solo, phone-driven project is a sub-10-second inner loop. **Use it — do not run the suite without it locally, and do not rely on it on CI.**

Configured in `tests/Pest.php` (already in `references/Pest.php`), so no flag is needed day to day:

```php
pest()->tia()
    ->always()     // activate without a flag
    ->locally()    // ...but restrict that auto-activation to non-CI runs
    ->baselined()  // pull the shared baseline recorded by tia-baseline.yml
    ->filtered()   // load only the affected test files, not the whole suite
    ->watch([...]) // extra glob → test-directory mappings
```

Spell out both `always()` and `locally()`. `locally()` *restricts* `always()` — it does not imply it — and the docs define the `--tia --locally` flag pair as the equivalent of exactly this chain. Drop `always()` and you risk a config that never auto-activates, which looks identical to Tia simply not helping.

| Rule | Why |
| --- | --- |
| **On locally** (`locally()`) | Fast inner loop; `composer test:unit` picks it up with no extra flags |
| **Off on CI** | A gate must execute the real suite, and `locally()` does not deliver that: Pest sets its environment from a `--ci` **argument**, not from `CI=true`, so an unflagged CI run counts as local and `always()` fires. Gate jobs run `composer test:unit:full`, which pins `--no-tia` |
| **Explicit `--tia` still wins on CI** | `locally()` restricts *auto*-activation only. An explicit `--tia` takes effect regardless — which is exactly how `tia-baseline.yml` records a graph on CI without contradicting `locally()`. `--no-tia` is the reverse escape hatch |
| **Browser tests never use it** | `phpunit.xml` keeps them out of the default suite and `test:browser` selects them by name with `--no-tia`. They stay out of the baseline, and impact-analyzing a full-stack browser suite is the least trustworthy case |
| **Needs a coverage driver** | PCOV (preferred) or Xdebug must be enabled locally — Tia records the test↔file graph through it. Without one it cannot record, so the run falls back to a plain full run |
| **`--fresh` after weird results** | If replays look stale or the graph is suspect, `composer test:tia-baseline` (or `vendor/bin/pest --tia --fresh`) re-records from scratch |
| **Never trust a replay for a claim** | Before saying "tests pass" on work you are shipping, run `composer test:unit:full` (`--no-tia`) or let CI say it |

Flags worth knowing (`--tia` is only needed if the config above is absent):

| Flag / env | Effect |
| --- | --- |
| `--tia` / `PEST_TIA=1` | Replay from the baseline, or record one if none exists |
| `--no-tia` | Force a full run for this invocation |
| `--tia --fresh` | Discard the graph and re-record |
| `--tia --filtered` / `PEST_TIA_FILTERED=1` | Narrow PHPUnit to the affected test files |
| `--tia --locally` / `PEST_TIA_LOCALLY=1` | On locally, skipped on CI |
| `--tia --baselined` / `PEST_TIA_BASELINED=1` | Fetch the shared CI baseline when needed |
| `--tia --refetch` | Force a baseline fetch inside the 24h cooldown |
| `--baseline` | Print the storage directory (used by the artifact upload) |

**Shared baseline.** `references/tia-baseline.yml` → `.github/workflows/tia-baseline.yml` records a clean graph on every push to `main` plus nightly and uploads it as the `pest-tia-baseline` artifact, so a fresh clone starts warm instead of paying for a full recording run. It mirrors the `test` job (Postgres service, Flux auth, built assets) but with `coverage: pcov`, and it gates nothing — `ci-success` stays the only required check. Keep it out of `ci-success`'s `needs:` list.

**Caveats to expect:**

- **Record the baseline without `--coverage`.** Tia needs the pcov/Xdebug *driver*, not a coverage report. `pest --tia --coverage --fresh` writes `coverage.bin.gz` and no `graph.json` — same silent-green, useless-artifact failure as the detached HEAD below. Verified on a runner: drop `--coverage` and a 98 KB `graph.json` appears.
- **Check out the branch in the baseline job** (`ref: ${{ github.ref_name }}`). Tia keys the graph by branch and skips `saveGraph()` entirely on a detached HEAD, which is what a default `actions/checkout` gives you. The symptom is quiet: the job goes green, the artifact uploads, and it holds `coverage.bin.gz` with no `graph.json` — every consumer then fails with *"Baseline downloaded but the artifact is missing expected files"*.
- State lives in `~/.pest/tia/<project-key>/`, keyed off the normalized git remote — outside the repo, so nothing to gitignore, but also nothing that survives an ephemeral hosted sandbox.
- `baselined()` fetches by shelling out to GitHub's CLI — it uses `gh` to download the `pest-tia-baseline` artifact from the last successful `tia-baseline.yml` run. A missing or logged-out `gh` is **fatal**, not a fallback: `BaselineSync::validateGhDependencies()` panics, and every test command dies before running a test. Franco's machine has `gh`; hosted Claude Code web sessions do not, so `references/Pest.php` only calls `baselined()` when `ExecutableFinder` can see the binary, and those sessions record their own graph instead. A fetched graph is validated against project state and discarded if it does not match, which does fall back to a local rebuild.
- A **failed fetch starts a 24-hour cooldown** before Pest tries again — `--tia --refetch` forces one inside that window. So a hosted session that fails the fetch once won't retry on its own for a day; not a problem for ephemeral sandboxes (each is a fresh `~`), but worth knowing on a long-lived machine that was briefly offline or logged out of `gh`.
- Cosmetic edits (comments, docblocks, Pint/Prettier passes) normalize to the same hash and trigger zero tests. That is correct behaviour, not a broken graph.
- The graph rebuilds itself when `composer.lock`, `phpunit.xml*`, `vite.config.*`, the Node lockfile, or the PHP version changes.
- **Keep every selection flag off the default run.** `--group`, `--exclude-group`, `--filter`, `--testsuite`, `--dirty` and the rest of `Tia::PARTIAL_SELECTION_FLAGS` mark a run as partial, and Tia silently steps aside: `TIA does not apply to partial runs — running the selected tests directly`. A `test:unit` that says `--exclude-group=browser` therefore never replays, and a baseline job that says it uploads nothing. Hold browser tests out with `defaultTestSuite="Arch,Unit,Feature"` in `phpunit.xml` instead, and select them by name — `--testsuite=Browser` — in `test:browser`, which is partial on purpose and pins `--no-tia`.

**Standard arch tests** (`tests/Arch/`) — create the ones whose layer exists, add the rest as layers appear:

| Test file | Enforces |
| --- | --- |
| `CodeQualityTest.php` | `declare(strict_types=1)` (`arch('strict types')->expect('App')->toUseStrictTypes()`) |
| `TestStyleTest.php` | `it()` syntax only |
| `EnumsTest.php` | String-backed, naming |
| `ServicesTest.php` | Final, no Eloquent dependencies |
| `JobsTest.php` | Final, ShouldQueue |
| `ModelsTest.php` | Final, HasUlids, casts() |
| `LivewireSfcTest.php` | SFCs only, correct directories |
| `ObservabilityTest.php` | No `Log::debug()`, namespaced context keys |
| `LayerDependenciesTest.php` | e.g. Enums don't depend on Models |
| `AgentToolsTest.php` / `AiMiddlewareTest.php` | AI layer conventions (once `app/Ai/` exists) |

## 7. Linting

| Tool | Config | Purpose |
| --- | --- | --- |
| Pint (latest) | `pint.json` | PHP formatting, Laravel preset + strict rules |
| Rector (latest) | `rector.php` | Transforms: dead code, early return, coding style |
| Prettier | `prettier.config.mjs` | JS/CSS/JSON/YAML/Markdown, `proseWrap: "never"`, Tailwind class sorting |
| PHPStan + Larastan (latest) | `phpstan.neon` | Level 8 |
| Lefthook | `lefthook.yml` | Pre-commit fixers only (pint + rector piped, prettier split by content, `stage_fixed: true`) |

No pre-push hook: analysis and tests run in CI behind `ci-success`. Never bypass hooks with `--no-verify`.

## 8. Git Workflow & CI

- Branch from `main`: `feature/{description}`, lowercase hyphen-separated. Small solo changes may go straight to `main` while the project is pre-launch — tighten once collaborators join.
- CI: `references/ci.yml` → `.github/workflows/ci.yml`. Gates mirror `composer test`; single `ci-success` aggregate job.
- The `test` job runs against a `postgres` service container (`pdo_pgsql` extension, `DB_*` job env) so the suite exercises the same engine as production. Local runs stay on SQLite.
- Second workflow, `references/tia-baseline.yml` → `.github/workflows/tia-baseline.yml`: records and uploads the shared Pest Tia baseline on push to `main` and nightly (§ 6.1). It is not a gate — keep it out of `ci-success`'s `needs:` list.
- Branch protection (once CI is green on `main`): require only the `ci-success` status check. `gh api repos/fgilio/logralo/branches/main/protection` or the settings UI.

## 9. AI Integration (laravel/ai)

All AI code in `app/Ai/`. Patterns: Conversational Agent (`Agent`, `Conversational`, `HasTools` + `Promptable`), Structured Output Agent (+ `HasStructuredOutput` with `schema(JsonSchema)`), Tool (`description()`, `handle(Request)`, `schema()`), Middleware (pipeline `handle(AgentPrompt, Closure)`).

Config: `config/ai.php`, multi-provider. Testing: `MyAgent::fake(['response text'])`, `MyClassifier::fake([['category' => '...']])`.

First real use case: judging goal difficulty for fair point weighting (see `docs/mvp-decisions.md` — post-MVP, but structure `app/Ai/` for it).

## 10. Observability

Full guide: `references/wide-events.md`. Summary:

- One canonical `Log::info()` per unit of work (jobs, commands, HTTP gates, service boundaries), usually in `finally`
- Context via `Context::add('logralo.key', value)` — always `logralo.`-namespaced
- Warnings must include `reason`; no `Log::debug()`, no breadcrumbs
- Queue failures centralized in a global `Queue::failing` listener (`job.failed.safety_net`)
- Nightwatch surfaces context in production job/exception records

### Post-deploy verification

1. `gh run watch` until CI is green; Laravel Cloud auto-deploys `main`
2. Wait 3–5 min for the rollout
3. Nightwatch MCP `list_issues` — zero new issues after 5 min = green
4. If issues: `get_issue()` for stack trace + context, read source, fix, repeat

## 11. CLAUDE.md Practice

Replace the pre-kickoff root `CLAUDE.md` using `references/claude-md-template.md`. Add directory-level CLAUDE.md files (`tests/`, `app/Ai/`, `app/Models/`, `app/Jobs/`) only for non-obvious patterns: structural conventions and the *why* behind decisions. Never lists of current implementations or anything derivable from reading the code.

## 12. Kickoff Checklist

| Item | File |
| --- | --- |
| PHP 8.5 + strict types | `composer.json`, `pint.json` |
| Laravel 13 (latest) | `composer.json` |
| Pest v5 + plugins on matching major | `composer.json` require-dev |
| Tia engine configured (`locally`/`baselined`/`filtered`) | `tests/Pest.php` |
| Tia baseline workflow (not a gate) | `.github/workflows/tia-baseline.yml` |
| Everything on latest majors | `composer outdated -D`, `npm outdated` clean |
| Flux Pro repo block | `composer.json` repositories |
| Flux secrets wired, nothing committed | gitignored `auth.json`; Actions secrets; Cloud + web-session env vars |
| Livewire 4 SFCs | `config/livewire.php` |
| Tailwind v4 entry | `resources/css/app.css` |
| Vite config | `vite.config.js` |
| Pint / Rector / PHPStan L8 / Prettier / EditorConfig | repo root configs |
| Lefthook pre-commit | `lefthook.yml` + `lefthook install` |
| Composer scripts (setup, dev, lint, test) | `composer.json` scripts |
| SQLite local default | `.env.example` |
| CI + `ci-success` | `.github/workflows/ci.yml` |
| Tests run on Postgres in CI | `.github/workflows/ci.yml` (`postgres` service + `DB_*` env) |
| Branch protection on `ci-success` | GitHub settings |
| SessionStart hook + bootstrap | `.claude/settings.json`, `.claude/hooks/session-start.sh`, `scripts/cloud/setup.sh` |
| Laravel Cloud linked (personal org) | `.cloud/config.json` (committed) |
| PlanetScale Postgres provisioned + wired | Cloud env (`DB_CONNECTION=pgsql` + credentials) |
| Cloud bucket for photos + queue workers | Laravel Cloud environment (bucket env vars, `database` queue driver) |
| Resend wired for mail | Cloud env (`MAIL_MAILER=resend` + API key); `log` mailer locally |
| Nightwatch installed + env vars (personal account) | `composer.json`, Cloud env |
| `logralo.fgilio.com` pointed at Cloud | Cloud custom domain + DNS |
| Arch tests | `tests/Arch/` |
| `tests/Pest.php` global config | `tests/Pest.php` |
| Root CLAUDE.md from template | `CLAUDE.md` |
| `composer test` fully green | — |

## Open items (decide during kickoff)

- **Registration/join flow**: how users register and join the group — Franco is designing this in a separate session. Scaffold starter-kit auth, but don't invent the join flow.

Settled since: domain is `logralo.fgilio.com`, Nightwatch runs on Franco's personal account, PlanetScale region is `us-east-2` (all in `docs/mvp-decisions.md`).

License is settled: FSL-1.1-MIT (`LICENSE.md` at repo root). Keep it intact when scaffolding.
