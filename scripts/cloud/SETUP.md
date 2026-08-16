# Cloud environment setup

How a hosted sandbox (Claude Code on the web, Codex Cloud) provisions Logralo, and what to run before working in one.

## What to run

The `SessionStart` hook launches `scripts/cloud/setup.sh` in the background, so the session is usable before provisioning finishes. Anything that needs the finished environment synchronizes on it:

```bash
bash scripts/cloud/await.sh && composer test
```

`await.sh` exits 0 once setup is done (or was never started), non-zero when it failed, so chaining with `&&` stops instead of running tests against a half-built environment. The full log is at `$TMPDIR/logralo-cloud-setup.log`. To re-run the bootstrap by hand: `bash scripts/cloud/setup.sh`.

On a developer machine the same entrypoint installs the git hooks and nothing else. `pla_cloud_session()` in `lib.sh` is the single detection contract; `LOGRALO_CLOUD_SETUP_SKIP_DEPS=1` forces the light path anywhere and `PLA_CLOUD_SESSION=1` forces the full one.

## Why a sandbox needs more than `composer setup`

Two things a laptop has that a sandbox does not. Both were measured in a Claude Code on the web session, not assumed.

**The image ships PHP 8.4 and `composer.json` requires `^8.5`.** Every composer and artisan call fails its platform check until a newer PHP is the default. `php.sh` installs the series pinned in `.github/php-version` from the sury apt repository the image already trusts, then selects it with `update-alternatives`. The one recovery that matters is baked in: the image's package lists are stale enough that every `php8.5-*` pool URL 404s, so `pla_apt_install` refreshes with `--allow-releaseinfo-change` and retries.

**The egress proxy blocks the archives composer downloads.** Packagist metadata answers fine, but the dist archives it points at live on `api.github.com`, and those return 403 for every third-party repo — with a token and without one (the sandbox exports `GITHUB_TOKEN=proxy-injected`, a placeholder composer will happily send and GitHub will reject). `--prefer-source` is not a way out: `phpstan/phpstan` is published dist-only, with no `source` entry in `composer.lock` to clone, so an install gets through every other package and then dies on that one.

So vendor/ has to arrive some other way. The one GitHub surface a sandbox can always reach is this repo itself, and that is what `.github/workflows/cloud-snapshot.yml` and `scripts/cloud/snapshot.sh` are for: CI builds a complete `vendor/`, publishes it as a zstd asset on a draft release here, and the sandbox restores the one whose `composer.lock` digest matches the checkout. Draft releases carry no git tag and are invisible without push access, which the session credential has.

Everything else the sandbox needs is ordinary: npm and the Vite build reach the public registry directly, and Chromium is pre-provisioned at `PLAYWRIGHT_BROWSERS_PATH`.

Two smaller things the bootstrap also fixes, both of which otherwise break `composer test` in a fresh session. Sandbox checkouts arrive without `origin/HEAD`, and Pest's Tia mode aborts rather than guess the branch every baseline falls back to, so `setup.sh` resolves it with `git remote set-head origin --auto`. And sandboxes run as root, where composer refuses to load plugins unless `COMPOSER_ALLOW_SUPERUSER` is set, so `php.sh` persists that variable to the shell profiles rather than only exporting it for its own process.

## When the snapshot is missing

`composer install` failing twice in a sandbox almost always means no snapshot matches the current `composer.lock` — a dependency bump landed and the snapshot for the new lock has not been built yet. The workflow runs on pushes to `main` that touch `composer.json`, `composer.lock`, `.github/php-version`, or the workflow itself, and can be started by hand from the Actions tab (`workflow_dispatch`) when retention has pruned the snapshot a still-checked-out lock needs. Once it has run, `bash scripts/cloud/setup.sh` picks it up.

`PLA_CLOUD_SNAPSHOT=0` disables the restore for a run, which is only useful when debugging the fallback.

## Flux Pro credentials

The repo is public, so `auth.json` is never committed. Hosted sessions and CI provide `FLUX_USERNAME` and `FLUX_LICENSE_KEY` as environment variables (repository secrets of the same name in CI), and `php.sh` writes the gitignored `auth.json` from them. A restored snapshot already contains `livewire/flux-pro`, so a session with the variables unset still gets a working vendor — it just cannot install it live.

## Relationship to the Publica.la fleet

The layout, the module split, and the `lib.sh` config-block convention come from the fleet bootstrap in [`publicala/pla-stack`](https://github.com/publicala/pla-stack) (`.claude/skills/cloud-bootstrap-audit/`). `await.sh`, `environment.sh` and `lefthook.sh` are the canonical files verbatim; keep them that way, and send improvements upstream rather than editing them here.

Two modules deliberately diverge, and the reason is the same for both: `publicala/php-ci-static` is outside this repo's sandbox GitHub scope, so its release assets 403 here exactly like any other third-party repo's.

- `php.sh` installs PHP from apt instead of restoring a static build from `php-ci-static`.
- `snapshot.sh` and `cloud-snapshot.yml` carry vendor only, with no `php` block in the manifest, and the workflow builds with `shivammathur/setup-php` and the Flux secrets rather than `publicala/php-ci-static/setup-php-vendor`.

`setup.sh`, `lib.sh`, `node.sh`, `playwright.sh` and `databases.sh` are per-repo in every fleet consumer too, so they carry no sync obligation. Logralo runs on SQLite in sandboxes and has no service daemons, which is why it ships no `ensure-services.sh`.
