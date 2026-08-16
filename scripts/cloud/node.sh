#!/usr/bin/env bash
# Node dependencies and the Vite build for cloud sessions.
#
# Load-bearing, not best-effort: the feature and browser suites render @vite
# Blade views, which throw Laravel's ViteException without public/build, so
# `composer test` cannot pass until this has run. setup.sh treats a failure
# here as a failed track.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
cd "$(cloud_project_dir)" || exit 0

# Run package-manager network steps with the proxy env cleared so they connect
# to the registries directly. The agent proxy aborts mid-body on large platform
# binary tarballs (@esbuild/*, @rollup/*, sharp); direct egress to the
# registries is allowed in these environments and any private-registry auth
# comes from ~/.npmrc, not the proxy. (Pattern proven in volpe and ragno.)
pkg_direct() {
    env -u HTTPS_PROXY -u https_proxy \
        -u npm_config_https_proxy -u YARN_HTTPS_PROXY -u GLOBAL_AGENT_HTTPS_PROXY \
        "$@"
}

# Warm resume, gated separately for the two steps because they consume
# different things. npm ci reads package-lock.json and nothing else (.npmrc
# sets ignore-scripts, so no lifecycle script can reach the rest of the
# checkout), while public/build bakes in CSS/JS/Blade/vite.config inputs that
# move without the lockfile. Keying both on lockfile+commit, as one marker
# would, throws away 150MB of correct node_modules and reinstalls the identical
# dependency tree every time a session commits something.
lock_hash="$(sha256sum package-lock.json 2>/dev/null | cut -d' ' -f1)"
build_key="${lock_hash}-$(git rev-parse HEAD 2>/dev/null)"
# The commit half of build_key only describes committed work, so uncommitted
# edits to a build input are invisible to it: a resumed session whose lockfile
# and HEAD both still match would skip the build and leave public/build
# describing the last commit rather than the working tree. Tracking whether
# those inputs are dirty is enough to fall through to a rebuild; the contents
# do not need hashing, because any further edit keeps them dirty and the
# rebuild keeps happening until the work is committed.
build_inputs_dirty="$(git status --porcelain -- resources vite.config.js 2>/dev/null)"
install_marker='node_modules/.cloud-npm-lockhash'
# Outside node_modules, so a reinstall cannot take the build marker with it,
# and beside the artifacts it describes, so a wiped public/build invalidates it.
build_marker='public/build/.cloud-build-key'

# npm ci (not install) is intentional: install "heals" a stale node_modules
# incrementally, but the sandbox's npm rewrites package-lock.json while doing
# it (dropping the optional-dependency libc fields), leaving every session with
# a dirty tree that git hooks and agents keep tripping over. ci installs
# straight from the lockfile and never mutates it; the marker above is what
# keeps resumed sessions from paying the full wipe every time.
if [ -d node_modules ] && [ -n "$lock_hash" ] \
    && [ "$(cat "$install_marker" 2>/dev/null)" = "$lock_hash" ]; then
    log 'Node dependencies already match package-lock.json. Skipping npm ci.'
else
    log 'Installing Node dependencies (npm ci)'
    if ! pkg_direct npm ci --no-audit --no-fund; then
        warn 'Node dependency install failed.'
        exit 1
    fi
    printf '%s\n' "$lock_hash" >"$install_marker"
fi

# The build must run after the PHP track: resources/css/app.css imports Flux's
# stylesheet out of vendor/livewire/flux, so a build racing the vendor restore
# either fails outright or emits CSS missing every Flux class. setup.sh
# serializes the two for exactly this reason.
if [ -f public/build/manifest.json ] && [ -n "$lock_hash" ] \
    && [ -z "$build_inputs_dirty" ] \
    && [ "$(cat "$build_marker" 2>/dev/null)" = "$build_key" ]; then
    log 'Assets already built for this lockfile and commit. Skipping the build.'
    exit 0
fi

log 'Building frontend assets'
if ! npm run build; then
    warn 'Frontend asset build failed. Browser and @vite view tests need public/build.'
    exit 1
fi

printf '%s\n' "$build_key" >"$build_marker"
