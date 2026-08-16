#!/usr/bin/env bash
# Bootstrap for hosted Claude Code (web) and Codex Cloud sessions, and for
# fresh checkouts on a developer machine.
#
# What a cold sandbox needs that a laptop does not, and why each piece exists,
# is in scripts/cloud/SETUP.md. The short version: the image ships PHP 8.4 and
# this app requires ^8.5, and the egress proxy blocks the third-party dist
# archives composer downloads, so both the runtime and vendor/ have to come
# from somewhere else.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$here/lib.sh"

# Local sessions manage their own PHP and dependencies and only need the git
# hooks. They run this synchronously, with no status file, so await.sh stays a
# no-op. pla_cloud_session recognizes Claude Code on the web and Codex Cloud,
# so the same entrypoint serves both harnesses; LOGRALO_CLOUD_SETUP_SKIP_DEPS=1
# forces the light path anywhere, PLA_CLOUD_SESSION=1 forces the full one.
skip_deps_var="${PLA_PROJECT_ENV_PREFIX}_CLOUD_SETUP_SKIP_DEPS"
if [ "${!skip_deps_var:-}" = '1' ] || ! pla_cloud_session; then
    local_mode=1
else
    local_mode=0
fi

# On the web the hook already wrote 'running' to the status file when the
# session started. Arm the trap before resolving the project directory so even
# an early failure is recorded as failed:N rather than leaving the status stuck
# on 'running'.
if [ "$local_mode" -eq 0 ]; then
    trap 'rc=$?; if [ "$rc" -eq 0 ]; then printf "done\n" > "$CLOUD_SETUP_STATUS_FILE"; else printf "failed:%s\n" "$rc" > "$CLOUD_SETUP_STATUS_FILE"; fi' EXIT
    printf 'running\n' > "$CLOUD_SETUP_STATUS_FILE"
fi

project_dir="$(pla_project_dir)" || exit 1
cd "$project_dir"
log "Preparing ${PLA_PROJECT_SLUG} in $project_dir"

if [ "$local_mode" -eq 1 ]; then
    bash "$here/lefthook.sh"
    # The Claude hook writes 'running' before this script runs, and a hosted
    # session can still take the light path (LOGRALO_CLOUD_SETUP_SKIP_DEPS=1).
    # Clear that marker so await.sh treats setup as never started instead of
    # waiting for a bootstrap that will not happen. A 'done'/'failed' status
    # from an earlier full run is left alone.
    if [ "$(cat "$CLOUD_SETUP_STATUS_FILE" 2>/dev/null)" = 'running' ]; then
        rm -f "$CLOUD_SETUP_STATUS_FILE"
    fi
    exit 0
fi

# Cheap and independent, so they run alongside the heavy work.
{
    bash "$here/lefthook.sh"

    # Pest's Tia mode resolves every branch's baseline through the default
    # branch, and refuses to guess: with a remote but no origin/HEAD it aborts
    # rather than silently re-run the whole suite while reporting a cache hit.
    # Sandbox checkouts arrive without that ref, so `composer test` fails on it
    # in every fresh session until this runs. Harmless where it is already set.
    if ! git symbolic-ref -q refs/remotes/origin/HEAD >/dev/null; then
        log 'Resolving the default branch for Tia (origin/HEAD)'
        git remote set-head origin --auto >/dev/null 2>&1 \
            || warn 'Could not resolve origin/HEAD. Pest Tia mode will refuse to run; set it with: git remote set-head origin --auto'
    fi
} &
side_pid=$!

# One serialized track, not a fan-out, because each step needs the one before
# it:
#
#   php.sh         the runtime and vendor/
#   environment.sh .env — after vendor/, because its key:generate step shells
#                  out to artisan, which fatals on a missing autoloader; and
#                  before the build, which reads the VITE_* variables
#   node.sh        npm ci and the Vite build. resources/css/app.css imports
#                  vendor/livewire/flux/dist/flux.css, so a build racing the
#                  vendor restore either fails outright or emits CSS missing
#                  every Flux class — and node.sh's warm-resume marker, written
#                  after a degraded build, would make those bad assets stick
#                  for the rest of the session
#   playwright.sh  needs the CLI npm just installed. Best-effort: only
#                  test:browser wants the browser binary, so it never fails the
#                  track on its own
assets_track() {
    bash "$here/php.sh" || return 1
    bash "$here/environment.sh" || warn 'Writing .env failed.'
    bash "$here/node.sh" || return 2
    bash "$here/playwright.sh" || warn 'Playwright browser setup failed.'
}
assets_track && assets_rc=0 || assets_rc=$?
wait "$side_pid" || true

setup_failed=0
if [ "$assets_rc" -eq 1 ]; then
    warn 'PHP dependency setup failed. Skipping the asset build and the database.'
    setup_failed=1
elif [ "$assets_rc" -ne 0 ]; then
    warn 'Node dependency setup failed.'
    setup_failed=1
fi

# The database step needs artisan, so it only runs once the PHP track is in.
if [ "$assets_rc" -ne 1 ]; then
    bash "$here/databases.sh" || {
        warn 'Database setup failed. Artisan and the app will not have a usable database.'
        setup_failed=1
    }
fi

# package:discover and optimize:clear are what composer's skipped
# post-autoload-dump would have run. They need vendor/, and without it artisan
# just fatals on the missing autoloader.
if [ -f vendor/autoload.php ]; then
    log 'Discovering packages and clearing caches'
    php artisan package:discover --ansi || warn 'Package discovery failed.'
    php artisan optimize:clear || true
else
    warn 'vendor/autoload.php is missing. Skipping package discovery.'
fi

if [ "$setup_failed" -ne 0 ]; then
    warn 'Cloud setup finished with errors. See the log above.'
    exit 1
fi

log 'Cloud setup complete.'
