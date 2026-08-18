# Cloud environment setup

How a hosted sandbox (Claude Code on the web, Codex Cloud) provisions Logralo, and what to run before working in one.

## What to run

The `SessionStart` hook launches `scripts/cloud/setup.sh` in the background, so the session is usable before provisioning finishes. Anything that needs the finished environment synchronizes on it:

```bash
bash scripts/cloud/await.sh && composer test
```

`await.sh` exits 0 once setup is done (or was never started), non-zero when it failed, so chaining with `&&` stops instead of running tests against a half-built environment. The full log is at `$TMPDIR/logralo-cloud-setup.log`. To re-run the bootstrap by hand: `bash scripts/cloud/setup.sh`.

On a developer machine the same entrypoint installs the git hooks and nothing else. `cloud_session()` in `lib.sh` is the single detection contract; `LOGRALO_CLOUD_SETUP_SKIP_DEPS=1` forces the light path anywhere and `LOGRALO_CLOUD_SESSION=1` forces the full one.

## Why a sandbox needs more than `composer setup`

Two things a laptop has that a sandbox does not. Both were measured in a Claude Code on the web session, not assumed.

**The image ships PHP 8.4 and `composer.json` requires `^8.5`.** Every composer and artisan call fails its platform check until a newer PHP is on `PATH`. The runtime arrives with the snapshot, described below.

**The egress proxy blocks the archives composer downloads.** Packagist metadata answers fine, but the dist archives it points at live on `api.github.com`, and those return 403 for every third-party repo — with a token and without one (the sandbox exports `GITHUB_TOKEN=proxy-injected`, a placeholder composer will happily send and GitHub will reject). `--prefer-source` is not a way out: `phpstan/phpstan` is published dist-only, with no `source` entry in `composer.lock` to clone, so an install gets through every other package and then dies on that one.

So both have to arrive some other way. The one GitHub surface a sandbox can always reach is this repo itself, and that is what `.github/workflows/cloud-snapshot.yml` and `scripts/cloud/snapshot.sh` are for: CI builds a complete `vendor/` and captures the PHP binary it just ran, publishes them as zstd assets on a draft release here, and the sandbox restores the pair whose `composer.lock` digest matches the checkout. Draft releases carry no git tag and are invisible without push access, which the session credential has.

Note that "this repo's releases" means the API, not the download host. `api.github.com` answers for an attached repo; a `github.com/<owner>/<repo>/releases/download/...` URL answers 403 in a sandbox for _every_ repo, this one included. Anything the bootstrap needs at provisioning time has to be an asset on a release here, fetched through the API.

Everything else the sandbox needs is ordinary: npm and the Vite build reach the public registry directly, and Chromium is pre-provisioned at `PLAYWRIGHT_BROWSERS_PATH`.

Two smaller things the bootstrap also fixes, both of which otherwise break `composer test` in a fresh session. Sandbox checkouts arrive without `origin/HEAD`, and Pest's Tia mode aborts rather than guess the branch every baseline falls back to, so `setup.sh` resolves it with `git remote set-head origin --auto`. And sandboxes run as root, where composer refuses to load plugins unless `COMPOSER_ALLOW_SUPERUSER` is set, so `php.sh` persists that variable to the shell profiles rather than only exporting it for its own process.

## The runtime is CI's, not a lookalike

Both `ci.yml` and `cloud-snapshot.yml` install PHP with [`publicala/php-ci-static`](https://github.com/publicala/php-ci-static), and the snapshot ships the binary that action produced. A sandbox therefore runs the same build as CI, not merely the same version.

That is the part a distro package could not do. `php8.5-cli` from a package repository is a different build with a different extension set and different compile flags, so "CI passed" and "it passes here" were only ever loosely related. The static build is linked against nothing but glibc, which is what makes it relocatable: the binary CI ran drops into a sandbox and works.

Two consequences worth knowing:

- **There is no extension list anywhere in this bootstrap.** The build links them all in, `gd`, `exif`, `intl`, `sqlite3`, `pgsql`, `sockets` and `imagick` included. `ci.yml`'s PHP steps carry no `extensions:` input for the same reason.
- **Configuration is part of the parity.** `ci.yml` defines `PHP_INI_VALUES`, `cloud-snapshot.yml` passes the same string, and `php.sh`'s `ci_php_ini_values` renders the same directives into the restored binary's scan dir. Change one and change all three: a directive set on only one side is exactly the drift this arrangement exists to prevent.

The restore installs to `/usr/local/bin/php`. If something earlier in `PATH` shadows it, the bootstrap says so rather than reporting a restore the session will never use. The PHP leg is best-effort on top of `vendor/`: a session that gets `vendor/` but not the binary is degraded, not broken, so only a vendor miss makes the restore fail.

## When the snapshot is missing

`composer install` failing twice in a sandbox almost always means no snapshot matches the current `composer.lock` — a dependency bump landed and the snapshot for the new lock has not been built yet. The workflow runs on pushes to `main` that touch `composer.json`, `composer.lock`, `.github/php-version`, or the workflow itself, and can be started by hand from the Actions tab (`workflow_dispatch`) when retention has pruned the snapshot a still-checked-out lock needs. Once it has run, `bash scripts/cloud/setup.sh` picks it up.

`LOGRALO_CLOUD_SNAPSHOT=0` disables the restore for a run, which is only useful when debugging the fallback.

## Flux Pro credentials

The repo is public, so `auth.json` is never committed. Hosted sessions and CI provide `FLUX_USERNAME` and `FLUX_LICENSE_KEY` as environment variables (repository secrets of the same name in CI), and `php.sh` writes the gitignored `auth.json` from them. A restored snapshot already contains `livewire/flux-pro`, so a session with the variables unset still gets a working vendor — it just cannot install it live.

## The browser suite, piped, looks hung

`composer test:browser` takes about half a minute. `vendor/bin/pest --testsuite=Browser | tail` never comes back at all — and it is not the suite. Chromium inherits the pipe's write end and keeps it open after pest has exited, so `tail` waits for an EOF nobody will send, and a run that passed reads as a hang for as long as anyone is willing to wait for it. `timeout` does not rescue it either: killing pest leaves the browser holding the pipe.

Redirect instead of piping, and read the file:

```bash
composer test:browser > /tmp/browser.log 2>&1; tail -20 /tmp/browser.log
```

## Module layout

`setup.sh` is the only entrypoint; every other module does one job and is safe to run on its own while debugging.

| Module           | Job                                                                              |
| ---------------- | -------------------------------------------------------------------------------- |
| `lib.sh`         | Config block plus the shared helpers. Everything repo-specific lives here.       |
| `php.sh`         | The Flux credentials, the snapshot restore, `composer install`, the CI ini list. |
| `snapshot.sh`    | Finds and unpacks the CI-built PHP binary and `vendor/`. Sourced by `php.sh`.    |
| `environment.sh` | Writes `.env` from `.env.example`.                                               |
| `node.sh`        | `npm ci` and the Vite build.                                                     |
| `databases.sh`   | Creates the SQLite file and migrates it.                                         |
| `playwright.sh`  | The browser binary for `test:browser`. Best-effort.                              |
| `lefthook.sh`    | Installs the git hooks.                                                          |
| `await.sh`       | Blocks until `setup.sh` finishes.                                                |

Anything a module needs to know about this repo — the slug, the env profile, the checkout markers — belongs in `lib.sh`'s config block, not inline in the module. That is what keeps the modules readable as generic steps.
