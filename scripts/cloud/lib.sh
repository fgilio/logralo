#!/usr/bin/env bash
# Shared helpers and configuration for the cloud session bootstrap.
# Every module under scripts/cloud sources this file for logging, project
# location, cloud-session detection, and the settings they all agree on.
#
# Everything the bootstrap needs to know about this repo lives in the "project
# config" block below; the helpers under it are generic. Keep new per-repo
# values in that block rather than hardcoding them in a module, so a reader can
# see the whole contract in one place. scripts/cloud/SETUP.md records the
# measurements that justify the design.

# --- project config ---------------------------------------------------------
# CLOUD_PROJECT_SLUG names the status/log files and default checkout locations.
CLOUD_PROJECT_SLUG='logralo'
# Environment variable prefix for per-repo overrides:
#   <PREFIX>_PROJECT_DIR, <PREFIX>_CLOUD_SETUP_SKIP_DEPS
CLOUD_PROJECT_ENV_PREFIX='LOGRALO'
# What await.sh's waiting message says the bootstrap is provisioning.
CLOUD_WAIT_HINT='PHP 8.5, dependencies, assets, database'
# The committed profile environment.sh seeds .env from in cloud sessions.
# Logralo has no separate .env.ci: the suite runs on PostgreSQL in CI through
# real environment variables set by the workflow, and .env.example already
# carries the SQLite defaults a sandbox wants.
CLOUD_ENV_PROFILE='.env.example'
# Files that uniquely identify this repo's checkout, verified on every
# project-dir candidate. Keep the markers cheap and specific.
cloud_project_markers() {
    # The artisan entrypoint plus the PHP-version pin the bootstrap reads, plus
    # the product spec that only this repo carries, pin this to Logralo.
    [ -f "$1/artisan" ] && [ -f "$1/.github/php-version" ] && [ -f "$1/docs/mvp-v1-scope.md" ]
}
# No extension list: the runtime is the static build from the snapshot, which
# links every extension this app uses (gd and exif for PhotoProcessor, sqlite3,
# pgsql, intl, mbstring, sockets, and imagick) into the binary itself.
# -----------------------------------------------------------------------------

# The orchestrator records its outcome here so await.sh can block until the
# environment is ready. The session-start hook writes the run's output to the
# log file.
: "${CLOUD_SETUP_STATUS_FILE:=${TMPDIR:-/tmp}/${CLOUD_PROJECT_SLUG}-cloud-setup.status}"
: "${CLOUD_SETUP_LOG_FILE:=${TMPDIR:-/tmp}/${CLOUD_PROJECT_SLUG}-cloud-setup.log}"

log() {
    printf '\n==> %s\n' "$*"
}

warn() {
    printf 'Warning: %s\n' "$*" >&2
}

# True in a hosted cloud session: Claude Code on the web, Codex Cloud, or any
# harness that opts in with LOGRALO_CLOUD_SESSION=1 (LOGRALO_CLOUD_SESSION=0 forces the
# local path anywhere). This is the single cloud-detection contract; any other
# place in this repo that needs to know it is running in a sandbox calls this
# function rather than testing one marker on its own.
cloud_session() {
    case "${LOGRALO_CLOUD_SESSION:-}" in
        1) return 0 ;;
        0) return 1 ;;
    esac
    if [ "${CLAUDE_CODE_REMOTE:-}" = 'true' ]; then
        return 0
    fi
    if [ -n "${CODEX_PROXY_CERT:-}${CODEX_CI:-}" ]; then
        return 0
    fi
    return 1
}

# Locate the checkout regardless of which harness invoked us: Claude Code
# (CLAUDE_PROJECT_DIR), Codex Cloud (CODEX_PROJECT_DIR/CODEX_WORKSPACE),
# GitHub Actions (GITHUB_WORKSPACE), or a bare shell.
cloud_project_dir() {
    local override_var="${CLOUD_PROJECT_ENV_PREFIX}_PROJECT_DIR"
    local candidates=(
        "${!override_var:-}"
        "${CLAUDE_PROJECT_DIR:-}"
        "${CODEX_PROJECT_DIR:-}"
        "${CODEX_WORKSPACE:-}"
        "${GITHUB_WORKSPACE:-}"
        "$PWD"
        "/home/user/${CLOUD_PROJECT_SLUG}"
        "/workspace/${CLOUD_PROJECT_SLUG}"
    )

    local candidate
    for candidate in "${candidates[@]}"; do
        if [ -n "$candidate" ] && cloud_project_markers "$candidate"; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    warn "Could not find the ${CLOUD_PROJECT_SLUG} project directory. Set ${override_var} to the checkout path."
    return 1
}

# Print the PHP series pinned in .github/php-version; empty when the pin is
# absent. Shared by php.sh's install and the snapshot restore's marker check so
# the two paths cannot parse the pin differently.
cloud_pinned_php_series() {
    if [ -f .github/php-version ]; then
        tr -d '[:space:]' < .github/php-version
    fi
}

# Print the ini scan dir the given PHP binary was compiled with, empty when it
# reports none. The restored binary carries its own scan dir, which is not the
# distro's, so the drop-ins have to be written where it will actually look.
cloud_php_scan_dir() {
    local dir
    dir="$("$1" --ini 2>/dev/null | sed -n 's/^Scan for additional .ini files in: *//p')"
    if [ "$dir" = '(none)' ]; then
        dir=''
    fi
    printf '%s\n' "$dir"
}

# Write a file that may live under a root-owned path. Tries directly first, so
# a root session pays nothing, then through passwordless sudo. Returns non-zero
# when neither works, leaving the caller to decide whether that is fatal.
cloud_root_write() {
    local path="$1" content="$2"
    if printf '%s\n' "$content" > "$path" 2>/dev/null; then
        return 0
    fi
    printf '%s\n' "$content" | sudo -n tee "$path" >/dev/null 2>&1
}

# Run apt with the recovery the sandbox images need: the baked package lists go
# stale enough that pool URLs 404, and a changed release label breaks a plain
# update too. Install, then refresh allowing the change and retry once,
# surfacing the real apt error on final failure. The runtime no longer comes
# from apt; this remains for the odd missing tool (zstd, for the snapshot).
cloud_apt_install() {
    local apt_log
    apt_log="$(mktemp)"
    if ! sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" >"$apt_log" 2>&1; then
        warn 'apt install failed. Refreshing the package lists and retrying.'
        sudo -n apt-get update --allow-releaseinfo-change -qq >>"$apt_log" 2>&1 \
            || warn 'apt-get update failed.'
        if ! sudo -n DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$@" >>"$apt_log" 2>&1; then
            warn "Could not install: $*. Last apt output:"
            tail -n 15 "$apt_log" >&2
            rm -f "$apt_log"
            return 1
        fi
    fi
    rm -f "$apt_log"
}
