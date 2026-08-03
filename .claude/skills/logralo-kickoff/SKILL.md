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

1. **Latest versions of everything.** Pest 5 (not 4), latest Pint/Rector/Larastan/PHPStan, PHP 8.5, latest Tailwind 4.x / Vite. Install unpinned to get the latest and verify with `composer outdated -D` / `npm outdated`. Version numbers in reference files are baselines, not pins.
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
| DB (local) | SQLite | Zero-config local dev |
| DB (cloud) | MySQL 8 | Laravel Cloud managed |
| Testing | Pest v5 (latest) | Always `it()`, never class-based; plugins on matching major |
| AI | laravel/ai | For the goal-difficulty judging feature |
| Monitoring | Nightwatch | `laravel/nightwatch` |
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
- Required: `laravel/ai`, `laravel/horizon`, `laravel/nightwatch`, `livewire/blaze`, `livewire/flux-pro`, `livewire/livewire`.
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
| `Pest.php` | `tests/Pest.php` (add imports; fakes, freezeTime, RefreshDatabase) |
| `ci.yml` | `.github/workflows/ci.yml` |
| `claude-md-template.md` | template for root `CLAUDE.md` (replace the pre-kickoff CLAUDE.md) |
| `claude-settings.json` | `.claude/settings.json` |
| `session-start.sh` | `.claude/hooks/session-start.sh` (chmod +x) |
| `cloud-setup.sh` | `scripts/cloud/setup.sh` (chmod +x) |
| `cloud-link.sh` | `scripts/cloud-link.sh` (chmod +x) |

`.env.example`: `DB_CONNECTION=sqlite`.

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

Commit `.cloud/config.json` — it holds resource IDs (not secrets) and is required for non-interactive CLI usage by agents. All cloud commands take `-n` and `--json`. Set the `FLUX_*` env vars and the build-command prefix per § 2. Database: MySQL 8. Laravel Cloud auto-deploys every push to `main`.

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

`tests/Pest.php` from `references/Pest.php`: RefreshDatabase, `Http::preventStrayRequests()`, `Process::preventStrayProcesses()`, `Sleep::fake()`, `freezeTime()`.

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
| Branch protection on `ci-success` | GitHub settings |
| SessionStart hook + bootstrap | `.claude/settings.json`, `.claude/hooks/session-start.sh`, `scripts/cloud/setup.sh` |
| Laravel Cloud linked (personal org) | `.cloud/config.json` (committed) |
| Nightwatch installed + env vars | `composer.json`, Cloud env |
| Arch tests | `tests/Arch/` |
| `tests/Pest.php` global config | `tests/Pest.php` |
| Root CLAUDE.md from template | `CLAUDE.md` |
| `composer test` fully green | — |

## Open items (decide during kickoff)

- **License file**: repo is public with no LICENSE (= all rights reserved). Decide (MIT?) with Franco.
- **Domain**: check `logralo.app` availability (from the meeting notes).
- **Nightwatch account**: confirm which Nightwatch account/app Logralo reports to (personal vs work).
