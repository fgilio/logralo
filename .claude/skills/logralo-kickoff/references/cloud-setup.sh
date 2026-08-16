#!/usr/bin/env bash
# Bootstrap for a fresh checkout on a developer machine: composer deps, node
# deps, an .env, a database file, built assets, git hooks. Enough when the
# machine can reach the package registries.
#
# DO NOT copy this to scripts/cloud/setup.sh. It was the original kickoff
# artifact and it does not survive a hosted sandbox: the image's PHP is older
# than composer.json requires, and the egress proxy 403s the third-party dist
# archives, so composer never completes. The working bootstrap is the module
# set in scripts/cloud/, and scripts/cloud/SETUP.md explains what each
# constraint cost to find. Start from those, not from this file.
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
