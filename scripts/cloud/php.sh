#!/usr/bin/env bash
# PHP runtime and Composer dependencies for cloud sessions.
#
# Provisioning order:
#
# 1. The pinned PHP series (.github/php-version, the same series CI installs)
#    from the sury apt repository the sandbox image already trusts. The base
#    image ships PHP 8.4, and composer.json requires ^8.5, so without this
#    step every composer and artisan call in the session fails its platform
#    check. The fleet installs a static build from publicala/php-ci-static
#    instead; that repo is outside this session's GitHub scope and its release
#    assets 403 here, so apt is Logralo's equivalent (see SETUP.md).
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
cd "$(pla_project_dir)" || exit 1

export COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-1}"

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
    local pinned_version
    pinned_version="$(pla_pinned_php_series)"
    if [ -z "$pinned_version" ]; then
        warn '.github/php-version is missing. Staying on the image PHP; composer will fail its platform check if it is older than composer.json requires.'
        return
    fi

    # Warm resume: a previous session in this container already installed it.
    if [ "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)" = "$pinned_version" ]; then
        log "PHP ${pinned_version} is already the default. Skipping the install."
        return
    fi

    local packages=("php${pinned_version}-cli")
    local extension
    for extension in "${PLA_PHP_APT_EXTENSIONS[@]}"; do
        packages+=("php${pinned_version}-${extension}")
    done
    packages+=("${PLA_EXTRA_APT_PACKAGES[@]}")

    log "Installing PHP ${pinned_version}: ${packages[*]}"
    if ! pla_apt_install "${packages[@]}"; then
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

    if [ "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)" = "$pinned_version" ]; then
        log "Using PHP $(php -r 'echo PHP_VERSION;')."
    else
        warn "Installed PHP ${pinned_version}, but 'php' still resolves to $(command -v php) ($(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)). Sessions run that PHP until PATH or update-alternatives puts ${pinned_version} first."
    fi
}

# --no-scripts keeps post-autoload-dump, which runs artisan package:discover,
# from booting service providers before .env and the database exist. The
# orchestrator runs discovery once they are ready. The Pest plugin still runs,
# so vendor/pest-plugins.json is generated for test discovery.
#
# $1 is 'restored' when the snapshot supplied vendor/, 'missed' otherwise.
install_composer() {
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
    if [ "$1" = 'missed' ] && pla_cloud_session; then
        warn 'composer install failed and no cloud snapshot matched composer.lock. Third-party dist archives are blocked in this sandbox, so a live install cannot complete here. Publish a snapshot for this lock (.github/workflows/cloud-snapshot.yml, runnable from the Actions tab) and re-run scripts/cloud/setup.sh. See scripts/cloud/SETUP.md.'
        return 1
    fi

    # Over a restored snapshot, or outside a sandbox, a failure is plausibly a
    # transient proxy or credential blip. One retry converts it into a slower
    # success instead of a dead environment.
    warn 'composer install failed. Retrying once.'
    composer install --no-interaction --prefer-dist --no-progress --no-scripts
}

ensure_php_runtime
ensure_flux_credentials
if pla_snapshot_restore; then
    snapshot_outcome='restored'
else
    snapshot_outcome='missed'
fi
install_composer "$snapshot_outcome"
