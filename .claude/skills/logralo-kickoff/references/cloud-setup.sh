#!/usr/bin/env bash
# Bootstrap for hosted Claude Code (web) sessions and fresh checkouts.
# Copy to scripts/cloud/setup.sh during the kickoff.
#
# Simple by design: Logralo runs on SQLite locally (PostgreSQL on PlanetScale
# is production-only), so a full checkout only needs composer deps, node deps,
# an .env, a database file, built assets, git hooks, and the Cloud CLI.
# The Publica.la fleet carries a much heavier vendored bootstrap
# (static-PHP snapshots, restricted-egress fallbacks); adopt pieces of it
# later only if hosted sandboxes turn out to need them.
#
# Flux Pro credentials: the repo is public, so auth.json is never committed.
# Hosted sessions and CI provide FLUX_USERNAME / FLUX_LICENSE_KEY as
# environment variables; this script writes the gitignored auth.json from
# them when it is missing.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

if [[ ! -f auth.json && -n "${FLUX_USERNAME:-}" && -n "${FLUX_LICENSE_KEY:-}" ]]; then
    composer config http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY"
fi

composer install --no-progress --prefer-dist --no-interaction

# Laravel Cloud CLI, for inspecting and deploying production. It reads nothing
# the steps below produce, and a cold install is ~17s of Packagist, so it runs
# alongside them rather than after. Started here, not earlier, to keep two
# Composer processes off the shared cache at once.
bash scripts/cloud-cli.sh &
cloud_cli_pid=$!

npm ci

[[ -f .env ]] || cp .env.example .env
grep -q '^APP_KEY=.\+' .env || php artisan key:generate --ansi
[[ -f database/database.sqlite ]] || touch database/database.sqlite
php artisan migrate --force

# Assets read composer vendor/ (Flux CSS import), so the build must run after
# composer install, never before it.
npm run build

# Install git hooks. Local dev has lefthook on PATH via brew; hosted sandboxes
# and CI fall back to the npm-installed binary.
if command -v lefthook >/dev/null 2>&1; then
    lefthook install
elif [[ -x node_modules/.bin/lefthook ]]; then
    node_modules/.bin/lefthook install
fi

# Non-fatal: the app builds and its tests run without the Cloud CLI, so a
# missing token or a Packagist hiccup must not take the bootstrap down with it.
# cli.sh has already said what went wrong on stderr; this only adds the retry.
wait "$cloud_cli_pid" || echo "cloud: setup failed; rerun: bash scripts/cloud-cli.sh" >&2
