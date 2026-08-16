#!/usr/bin/env bash
# PHP runtime and Composer dependencies for cloud sessions.
#
# The runtime and vendor/ both come from the same place: a snapshot CI builds
# and publishes on this repo's own draft releases (snapshot.sh), which is the
# one GitHub surface the sandbox proxy always allows. The base image ships PHP
# 8.4 while composer.json requires ^8.5, so until that restore lands every
# composer and artisan call fails its platform check.
#
# The binary is the same static build ci.yml installs, so a session is not just
# on the same PHP version as CI, it is on the same binary with the same ini
# directives. That is why there is no extension list anywhere in this
# bootstrap: the build links them all in.
#
# composer install then runs. Over a restored snapshot it is a fast no-op that
# validates the unpacked vendor against the lock and regenerates the
# autoloader. Without a snapshot it is the real install, and in a sandbox it
# will fail on the third-party dist archives the proxy blocks — that is
# expected, and the warning says so rather than pretending it is transient.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$here/lib.sh"
source "$here/snapshot.sh"
cd "$(cloud_project_dir)" || exit 1

export COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-1}"

# The export above only covers this process. Sandboxes run as root, where
# composer aborts ("no plugin should be loaded if running as super user is not
# explicitly allowed") rather than running its scripts — which would make every
# command CLAUDE.md documents (`composer test`, `composer lint`, `composer dev`)
# fail in a session shell. Persist it to the shell profiles so the whole
# session has it, not just this bootstrap.
persist_composer_superuser() {
    if [ "$(id -u)" -ne 0 ] || ! cloud_session; then
        return
    fi
    local line='export COMPOSER_ALLOW_SUPERUSER=1 # logralo cloud bootstrap'
    local profile
    for profile in "$HOME/.bashrc" "$HOME/.profile"; do
        if [ -f "$profile" ] && grep -qF 'logralo cloud bootstrap' "$profile"; then
            continue
        fi
        printf '%s\n' "$line" >> "$profile" 2>/dev/null \
            || warn "Could not append COMPOSER_ALLOW_SUPERUSER to $profile."
    done
}
persist_composer_superuser

# Some sandboxes export GITHUB_TOKEN as a literal placeholder (measured value
# in Claude Code on the web: 'proxy-injected'). Composer sends it as a GitHub
# credential and every request it authenticates comes back 403. Drop it for
# this track; the git credential proxy still authenticates the actual fetches.
if [ "${GITHUB_TOKEN:-}" = 'proxy-injected' ]; then
    unset GITHUB_TOKEN
fi

# Flux Pro credentials. The repo is public, so auth.json is never committed
# (.gitignore carries it). Hosted sessions and CI provide FLUX_USERNAME and
# FLUX_LICENSE_KEY as environment variables; write the gitignored auth.json
# from them when composer does not already have the credentials.
ensure_flux_credentials() {
    if [ -f auth.json ] && grep -q 'composer.fluxui.dev' auth.json 2>/dev/null; then
        return
    fi
    if [ -z "${FLUX_USERNAME:-}" ] || [ -z "${FLUX_LICENSE_KEY:-}" ]; then
        warn 'FLUX_USERNAME / FLUX_LICENSE_KEY are unset, so livewire/flux-pro cannot be downloaded. Set them on the environment to install it live; a restored snapshot already contains it.'
        return
    fi
    composer config http-basic.composer.fluxui.dev "$FLUX_USERNAME" "$FLUX_LICENSE_KEY" \
        || warn 'Could not write the Flux Pro credentials.'
}

# The php.ini directives ci.yml passes to the PHP setup action as
# PHP_INI_VALUES, rendered here as ini lines for the drop-in the restore writes
# into the binary's scan dir. Keep the two in sync: the point of shipping CI's
# binary is that the sandbox is configured like CI, and a directive set in only
# one of them quietly breaks that.
ci_php_ini_values='memory_limit=512M
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=64M'

# --no-scripts keeps post-autoload-dump, which runs artisan package:discover,
# from booting service providers before .env and the database exist. The
# orchestrator runs discovery once they are ready. The Pest plugin still runs,
# so vendor/pest-plugins.json is generated for test discovery.
#
# $1 is 1 when the snapshot did not supply vendor/, 0 when it did.
install_composer() {
    local snapshot_missed="$1"
    if [ ! -f composer.json ]; then
        return
    fi

    log 'Installing Composer dependencies'
    if composer install --no-interaction --prefer-dist --no-progress --no-scripts; then
        return
    fi

    # A sandbox that never got a snapshot has already lost this: the
    # third-party dist archives live on api.github.com and answer 403 here, and
    # composer's per-package fallback to source is both slow (it clones the
    # whole dependency tree) and futile (phpstan/phpstan is published dist-only
    # and has no source to clone). Retrying buys a second ten-minute walk to
    # the same failure, so say what actually unblocks it and stop.
    if [ "$snapshot_missed" -eq 1 ] && cloud_session; then
        warn 'composer install failed and no cloud snapshot matched composer.lock. Third-party dist archives are blocked in this sandbox, so a live install cannot complete here. Publish a snapshot for this lock (.github/workflows/cloud-snapshot.yml, runnable from the Actions tab) and re-run scripts/cloud/setup.sh. See scripts/cloud/SETUP.md.'
        return 1
    fi

    # Over a restored snapshot, or outside a sandbox, a failure is plausibly a
    # transient proxy or credential blip. One retry converts it into a slower
    # success instead of a dead environment.
    warn 'composer install failed. Retrying once.'
    composer install --no-interaction --prefer-dist --no-progress --no-scripts
}

ensure_flux_credentials

# One restore brings both the runtime and vendor/. It returns non-zero only on
# a vendor miss: the PHP component is best-effort on top, and a session that
# gets vendor but not the binary is degraded rather than broken.
snapshot_missed=0
cloud_snapshot_restore "$ci_php_ini_values" || snapshot_missed=1
install_composer "$snapshot_missed"
