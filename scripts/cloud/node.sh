#!/usr/bin/env bash
# Node dependencies and the Vite build for cloud sessions.
#
# Load-bearing, not best-effort: the feature and browser suites render @vite
# Blade views, which throw Laravel's ViteException without public/build, so
# `composer test` cannot pass until this has run. setup.sh treats a failure
# here as a failed track.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
cd "$(pla_project_dir)" || exit 0

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

# Warm resume: only skip the reinstall and build when nothing they consume has
# changed. The key pairs the lockfile hash (the npm ci input) with the checked
# out commit: public/build bakes in CSS/JS/Blade/vite.config inputs that can
# change without touching package-lock.json, so a resumed container that
# switched commits must rebuild even when the dependency tree is identical — a
# lockfile-only marker would leave it validating the previous commit's assets.
lock_hash="$(sha256sum package-lock.json 2>/dev/null | cut -d' ' -f1)"
build_key="${lock_hash}-$(git rev-parse HEAD 2>/dev/null)"
if [ -f public/build/manifest.json ] && [ -d node_modules ] \
    && [ -n "$lock_hash" ] && [ "$(cat node_modules/.cloud-assets-lockhash 2>/dev/null)" = "$build_key" ]; then
    log 'Node dependencies and assets already built for this lockfile and commit. Skipping.'
    exit 0
fi

# npm ci (not install) is intentional: install "heals" a stale node_modules
# incrementally, but the sandbox's npm rewrites package-lock.json while doing
# it (dropping the optional-dependency libc fields), leaving every session with
# a dirty tree that git hooks and agents keep tripping over. ci installs
# straight from the lockfile and never mutates it; the warm-resume marker above
# is what keeps resumed sessions from paying the full wipe every time.
log 'Installing Node dependencies (npm ci)'
if ! pkg_direct npm ci --no-audit --no-fund; then
    warn 'Node dependency install failed.'
    exit 1
fi

# The build must run after the PHP track: resources/css/app.css imports Flux's
# stylesheet out of vendor/livewire/flux, so a build racing the vendor restore
# either fails outright or emits CSS missing every Flux class. setup.sh
# serializes the two for exactly this reason.
log 'Building frontend assets'
if ! npm run build; then
    warn 'Frontend asset build failed. Browser and @vite view tests need public/build.'
    exit 1
fi

printf '%s\n' "$build_key" >node_modules/.cloud-assets-lockhash
