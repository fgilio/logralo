#!/usr/bin/env bash
# PHP runtime and Composer dependencies for cloud sessions.
#
# Provisioning order:
#
# 1. The pinned PHP series (.github/php-version, the same series CI installs)
#    from the sury apt repository the sandbox image already trusts. The base
#    image ships PHP 8.4, and composer.json requires ^8.5, so without this
#    step every composer and artisan call in the session fails its platform
#    check. apt is the cheapest source that works here: the image already
#    trusts sury, so no release asset has to travel through the egress proxy
#    (see SETUP.md).
# 2. The CI-built vendor snapshot from this repo's own draft releases
#    (snapshot.sh) — the one GitHub surface the sandbox proxy always allows.
# 3. composer install. Over a restored snapshot this is a fast no-op that
#    validates the unpacked vendor against the lock and regenerates the
#    autoloader. Without a snapshot it is the real install, and in a sandbox it
#    will fail on the third-party dist archives the proxy blocks — that is
#    expected, and the warning says so rather than pretending it is transient.
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

# Install the PHP series CI runs, from sury, and make plain `php` resolve to
# it. Everything in a cloud session (composer, artisan, vendor/bin/*) then runs
# the same PHP the tests will run under. ./scripts/php stays the entrypoint for
# developer machines, where Herd may hold several versions side by side.
ensure_php_runtime() {
    local pinned_version="$1"

    # Warm resume, or a base image that already ships the pinned series. Either
    # way the interpreter is right and only the extensions still need checking,
    # which ensure_php_extensions does next.
    if [ "$(cloud_running_php_series)" = "$pinned_version" ]; then
        log "PHP ${pinned_version} is already the default. Skipping the install."
        return
    fi

    # Cold path: one apt call for the interpreter and every extension, rather
    # than installing the CLI and then discovering the extensions missing.
    local packages=("php${pinned_version}-cli")
    local extension
    for extension in "${CLOUD_PHP_APT_EXTENSIONS[@]}"; do
        packages+=("php${pinned_version}-${extension}")
    done
    log "Installing PHP ${pinned_version}: ${packages[*]}"
    if ! cloud_apt_install "${packages[@]}"; then
        warn "Could not install PHP ${pinned_version}. Staying on $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'the image PHP'); composer will fail its platform check."
        return
    fi

    # sury registers every installed series with update-alternatives and leaves
    # the highest one selected in auto mode only if nothing pinned another.
    # Select ours explicitly so `php` is unambiguous.
    if [ -x "/usr/bin/php${pinned_version}" ]; then
        sudo -n update-alternatives --set php "/usr/bin/php${pinned_version}" >/dev/null 2>&1 \
            || warn "Could not select /usr/bin/php${pinned_version} as the default php."
    fi
    hash -r

    if [ "$(cloud_running_php_series)" = "$pinned_version" ]; then
        log "Using PHP $(php -r 'echo PHP_VERSION;')."
    else
        warn "Installed PHP ${pinned_version}, but 'php' still resolves to $(command -v php) ($(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)). Sessions run that PHP until PATH or update-alternatives puts ${pinned_version} first."
    fi
}

# Install any configured extension the running php is missing. Deliberately not
# folded into ensure_php_runtime: that function returns early whenever the
# interpreter is already the pinned series, and the day the base image ships
# that series a cold container would take the early return and never install
# gd or sqlite3 at all. The failure would surface as an Intervention or PDO
# fatal deep in a test run rather than as a bootstrap error, so the extension
# check has to be independent of whether the interpreter needed installing.
ensure_php_extensions() {
    local pinned_version="$1" missing
    mapfile -t missing < <(cloud_missing_php_extensions)
    if [ "${#missing[@]}" -eq 0 ]; then
        return
    fi

    local packages=() extension
    for extension in "${missing[@]}"; do
        packages+=("php${pinned_version}-${extension}")
    done

    log "Installing missing PHP extensions: ${missing[*]}"
    cloud_apt_install "${packages[@]}" \
        || warn "Could not install: ${missing[*]}. Code that needs them will fail at runtime."
}

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

pinned_php="$(cloud_pinned_php_series)"
if [ -z "$pinned_php" ]; then
    warn '.github/php-version is missing. Staying on the image PHP; composer will fail its platform check if it is older than composer.json requires.'
else
    ensure_php_runtime "$pinned_php"
    ensure_php_extensions "$pinned_php"
fi

ensure_flux_credentials
snapshot_missed=0
cloud_snapshot_restore || snapshot_missed=1
install_composer "$snapshot_missed"
