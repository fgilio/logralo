#!/usr/bin/env bash
# Restore the CI-built vendor snapshot in hosted agent sandboxes.
#
# Why this has to exist. The sandbox egress proxy scopes GitHub to the
# session's own repositories. Packagist metadata answers fine, but the dist
# archives it points at live on api.github.com, and those 403 for every
# third-party repo — with a token and without one. `--prefer-source` does not
# rescue it either: `phpstan/phpstan` is published dist-only (no `source` entry
# in composer.lock), so its api.github.com zipball is the only way to obtain it
# and `composer install` dies on that package after syncing every other one.
# Measured in a Claude Code on the web session; see scripts/cloud/SETUP.md.
#
# The one GitHub surface a session can always reach is this repo itself. CI has
# unrestricted egress and the Flux Pro credentials, so it builds a complete
# vendor/ and publishes it as an asset on a draft release here
# (.github/workflows/cloud-snapshot.yml); this module finds the snapshot
# matching the checkout's composer.lock and unpacks it.
#
# Sourced by php.sh, which calls cloud_snapshot_restore before its composer step.
# Restore is strictly best-effort: any miss (no snapshot yet, lock mismatch
# after a fresh dependency bump, download or digest failure) returns 1 and the
# caller proceeds exactly as if this module did not exist.
#
# Draft releases carry no git tag and are visible only to credentials with
# push access — which the session credential has. Guarded to cloud sessions:
# on a developer machine this must never touch vendor/.
#
# The snapshot carries vendor/ only. A PHP binary could travel the same way,
# but it does not need to: php.sh installs the pinned series from the sury apt
# repository the image already trusts, which is cheaper to build and to reason
# about than shipping a runtime through a release asset.

# Derive "owner/repo" from the git remote. In sandboxes the proxy rewrites
# remotes to http://127.0.0.1:<port>/git/<owner>/<repo>, so match the last two
# path segments rather than assuming a github.com URL.
cloud_snapshot_repo() {
    local url
    url="$(git config --get remote.origin.url 2>/dev/null)" || return 1
    url="${url%.git}"
    printf '%s\n' "$url" | grep -oE '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$'
}

cloud_snapshot_api_download() {
    local path="$1" destination="$2" accept="${3:-application/vnd.github+json}"
    curl -fsSL --retry 2 --retry-delay 2 --max-time 900 \
        -H "Accept: ${accept}" \
        -o "$destination" \
        "https://api.github.com/${path}"
}

# Print "release_id<TAB>manifest_asset_id" for snapshot candidates. Drafts
# only, tag prefix cloud-snapshot-, must carry a manifest, and must be
# authored by github-actions[bot]: the workflow's GITHUB_TOKEN is the only
# intended producer, so a draft created by any personal credential is
# ignored rather than restored. Not cryptographic provenance — an actor who
# can land workflow code on main can still mint one — but it keeps a merely
# push-capable credential from feeding sandboxes attacker-controlled assets.
# Candidates whose tag embeds the checkout's lock hash ($2, the sha's first
# 12 hex chars, which the workflow bakes into the tag) sort ahead of the
# rest, newest first within each group, so the matching manifest is fetched
# on the first attempt instead of after one download per newer lock. The
# manifest's full composer_lock_sha256 stays the authority; the tag only
# orders the attempts.
cloud_snapshot_candidates() {
    python3 - "$1" "$2" <<'PY'
import json, sys
releases = json.load(open(sys.argv[1]))
lock_prefix = f'cloud-snapshot-{sys.argv[2]}-'
rows = []
for release in releases:
    tag = release.get('tag_name', '')
    if not release.get('draft') or not tag.startswith('cloud-snapshot-'):
        continue
    if release.get('author', {}).get('login') != 'github-actions[bot]':
        continue
    manifest = next((a for a in release.get('assets', []) if a['name'] == 'manifest.json'), None)
    if manifest is None:
        continue
    rows.append((tag.startswith(lock_prefix), release.get('created_at', ''), release['id'], manifest['id']))
for lock_matches, created, release_id, manifest_id in sorted(rows, reverse=True):
    print(f'{release_id}\t{manifest_id}')
PY
}

# One parse for everything the restore consumes: reads the release listing and
# a candidate's manifest once and prints key<TAB>value lines (the vendor asset
# name is resolved to a download id here, so the shell never handles it). A
# malformed manifest makes python exit non-zero and the caller skips the
# candidate.
cloud_snapshot_release_info() {
    python3 - "$1" "$2" "$3" <<'PY'
import json, sys
releases = json.load(open(sys.argv[1]))
manifest = json.load(open(sys.argv[2]))
release_id = int(sys.argv[3])
assets = {}
for release in releases:
    if release['id'] == release_id:
        assets = {asset['name']: asset['id'] for asset in release.get('assets', [])}
print(f"schema\t{manifest['schema']}")
print(f"lock_sha\t{manifest['vendor']['composer_lock_sha256']}")
print(f"commit\t{manifest['commit']}")
print(f"vendor_sha\t{manifest['vendor']['sha256']}")
print(f"vendor_asset_id\t{assets.get(manifest['vendor']['asset'], '')}")
# .get, not [], so a manifest published before the PHP component existed still
# parses and serves its vendor rather than being skipped as malformed.
php = manifest.get('php') or {}
print(f"php_version\t{php.get('version', '')}")
print(f"php_sha\t{php.get('sha256', '')}")
print(f"php_asset_id\t{assets.get(php.get('asset', ''), '')}")
PY
}

cloud_snapshot_verify() {
    local file="$1" expected="$2"
    [ "$(sha256sum "$file" | cut -d' ' -f1)" = "$expected" ]
}

# Restore the newest snapshot whose composer_lock_sha256 matches the checkout.
# $1: the CI php.ini directives to render as drop-ins (php.sh owns the list).
# Returns 0 only when vendor/ matches the lock (restored now, or already
# current per the marker), 1 on every miss so php.sh falls through to its
# composer step. The PHP binary rides on top and is best-effort: it is the
# runtime the session wants, but vendor/ is what gates everything after this.
cloud_snapshot_restore() {
    local ini_values="${1:-}"

    if [ "${LOGRALO_CLOUD_SNAPSHOT:-1}" = '0' ]; then
        log 'Snapshot restore disabled (LOGRALO_CLOUD_SNAPSHOT=0).'
        return 1
    fi
    if ! cloud_session; then
        return 1
    fi
    if ! command -v python3 >/dev/null 2>&1; then
        warn 'python3 is unavailable. Skipping snapshot restore.'
        return 1
    fi

    local lock_sha
    lock_sha="$(sha256sum composer.lock | cut -d' ' -f1)"

    # A previous restore already produced a vendor for exactly this lock: skip
    # its download. The marker dies with vendor/, so a wiped or half-written
    # vendor never short-circuits. The PHP component is tracked separately: a
    # container can carry a current vendor and still be missing the binary
    # (a resumed image, or a restore where the vendor leg succeeded and the
    # php leg did not), so only when both are in place is the API skipped.
    local vendor_needed=1
    if [ -f vendor/.cloud-snapshot-marker ] && [ "$(cat vendor/.cloud-snapshot-marker)" = "$lock_sha" ]; then
        vendor_needed=0
        local pinned_series
        pinned_series="$(cloud_pinned_php_series)"
        if [ -z "$pinned_series" ] \
            || { [ -x /usr/local/bin/php ] \
                && [ "$(/usr/local/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)" = "$pinned_series" ]; }; then
            log 'vendor/ and the static PHP already match (snapshot marker). Skipping the download.'
            return 0
        fi
        log 'vendor/ matches this composer.lock; checking the snapshot for the PHP component.'
    fi

    local repo
    repo="$(cloud_snapshot_repo)" || { warn 'Could not derive the repo from the git remote. Skipping snapshot restore.'; return 1; }

    local work
    work="$(mktemp -d)"
    # shellcheck disable=SC2064
    trap "rm -rf '$work'" RETURN

    if ! cloud_snapshot_api_download "repos/${repo}/releases?per_page=100" "$work/releases.json"; then
        warn "Could not list ${repo} releases through the session proxy. Skipping snapshot restore."
        return 1
    fi

    local release_id manifest_id info key value chosen_release=''
    local meta_schema='' meta_lock_sha='' meta_commit='' meta_vendor_sha='' meta_vendor_asset_id='' \
        meta_php_version='' meta_php_sha='' meta_php_asset_id=''
    while IFS=$'\t' read -r release_id manifest_id; do
        if ! cloud_snapshot_api_download "repos/${repo}/releases/assets/${manifest_id}" "$work/manifest.json" 'application/octet-stream'; then
            continue
        fi
        info="$(cloud_snapshot_release_info "$work/releases.json" "$work/manifest.json" "$release_id")" || continue
        while IFS=$'\t' read -r key value; do
            case "$key" in
                schema) meta_schema="$value" ;;
                lock_sha) meta_lock_sha="$value" ;;
                commit) meta_commit="$value" ;;
                vendor_sha) meta_vendor_sha="$value" ;;
                vendor_asset_id) meta_vendor_asset_id="$value" ;;
                php_version) meta_php_version="$value" ;;
                php_sha) meta_php_sha="$value" ;;
                php_asset_id) meta_php_asset_id="$value" ;;
            esac
        done <<< "$info"
        if [ "$meta_schema" = '1' ] && [ "$meta_lock_sha" = "$lock_sha" ] && [ -n "$meta_vendor_asset_id" ]; then
            chosen_release="$release_id"
            break
        fi
    done < <(cloud_snapshot_candidates "$work/releases.json" "${lock_sha:0:12}")

    if [ -z "$chosen_release" ]; then
        log 'No cloud snapshot matches this composer.lock. Falling back to a live composer install.'
        return 1
    fi

    log "Restoring the cloud snapshot (release ${chosen_release}, commit ${meta_commit:0:12})."

    # zstd decompresses the snapshot asset, and the live fallback the caller
    # would take on a bail-out is exactly what the proxy blocks — so recover
    # through cloud_apt_install rather than giving up on one attempt. Checked
    # only now: the marker-skip and no-match exits above never need it.
    if ! command -v zstd >/dev/null 2>&1; then
        cloud_apt_install zstd || true
    fi
    if ! command -v zstd >/dev/null 2>&1; then
        warn 'zstd is unavailable and could not be installed. Skipping snapshot restore.'
        return 1
    fi

    # Skip the PHP leg when the exact build is already installed, so a resumed
    # container does not re-download tens of megabytes to arrive where it is.
    local php_wanted=0
    if [ -n "$meta_php_asset_id" ]; then
        if [ -x /usr/local/bin/php ] \
            && [ "$(/usr/local/bin/php -r 'echo PHP_VERSION;' 2>/dev/null)" = "$meta_php_version" ]; then
            log "Static PHP ${meta_php_version} is already in place. Skipping the PHP component."
        else
            php_wanted=1
        fi
    fi

    # The two assets are independent and vendor/ is the one everything after
    # this waits on, so overlap them rather than paying the PHP leg serially.
    local vendor_pid='' php_pid=''
    if [ "$vendor_needed" = 1 ]; then
        cloud_snapshot_api_download "repos/${repo}/releases/assets/${meta_vendor_asset_id}" "$work/vendor.tar.zst" 'application/octet-stream' &
        vendor_pid=$!
    fi
    if [ "$php_wanted" = 1 ]; then
        cloud_snapshot_api_download "repos/${repo}/releases/assets/${meta_php_asset_id}" "$work/php.zst" 'application/octet-stream' &
        php_pid=$!
    fi

    # vendor/ is waited on first and unpacked while the PHP download continues
    # in the background: a slow or stalled PHP leg must never delay the thing
    # composer and the migrate join are blocked on.
    local vendor_downloaded=1
    if [ -n "$vendor_pid" ]; then
        wait "$vendor_pid" || vendor_downloaded=0
    fi

    if [ "$vendor_needed" = 1 ]; then
        if [ "$vendor_downloaded" -ne 1 ] || ! cloud_snapshot_verify "$work/vendor.tar.zst" "$meta_vendor_sha"; then
            warn 'Could not download a verified vendor archive from the snapshot. Falling back to a live composer install.'
            return 1
        fi

        # Unpack beside the target — same filesystem as vendor/, which $TMPDIR
        # need not be — then swap with the old tree kept until the new one is in
        # place, so no failure mode leaves the checkout with no vendor/ at all.
        local unpack_dir=".cloud-snapshot-unpack.$$" old_vendor=".cloud-vendor-old.$$"
        rm -rf "$unpack_dir" "$old_vendor"
        mkdir "$unpack_dir"
        if ! tar -C "$unpack_dir" -xf "$work/vendor.tar.zst" -I zstd; then
            warn 'Could not extract the vendor archive. Falling back to a live composer install.'
            rm -rf "$unpack_dir"
            return 1
        fi
        if [ -e vendor ] && ! mv vendor "$old_vendor"; then
            warn 'Could not move the existing vendor/ aside. Falling back to a live composer install.'
            rm -rf "$unpack_dir"
            return 1
        fi
        if ! mv "$unpack_dir/vendor" vendor; then
            warn 'Could not swap the restored vendor/ into place. Falling back to a live composer install.'
            [ -e "$old_vendor" ] && mv "$old_vendor" vendor
            rm -rf "$unpack_dir"
            return 1
        fi
        rm -rf "$old_vendor" "$unpack_dir"
        printf '%s' "$lock_sha" > vendor/.cloud-snapshot-marker 2>/dev/null \
            || warn 'Could not write the snapshot marker; the next run will re-download.'
        log 'vendor/ restored from the cloud snapshot.'
    fi

    if [ -n "$php_pid" ]; then
        cloud_snapshot_install_php "$work" "$meta_php_sha" "$meta_php_version" "$php_pid" "$ini_values"
    fi

    return 0
}

# Install the downloaded PHP binary and give it the CI ini configuration.
# Every step is a guarded condition rather than a bare command: a failure here
# leaves the session on whatever PHP it already had, which is a degraded but
# working environment, so it must warn instead of claiming a restore that did
# not happen. The install and the drop-in writes try directly and then through
# passwordless sudo, so a non-root agent user with sudo still gets the binary.
cloud_snapshot_install_php() {
    local work="$1" expected_sha="$2" expected_version="$3" php_pid="$4" ini_values="$5"

    if ! { wait "$php_pid" && cloud_snapshot_verify "$work/php.zst" "$expected_sha" \
        && zstd -qdf -o "$work/php" "$work/php.zst" \
        && { install -m 0755 "$work/php" /usr/local/bin/php 2>/dev/null \
            || sudo -n install -m 0755 "$work/php" /usr/local/bin/php 2>/dev/null; }; }; then
        warn 'Could not restore the PHP component from the snapshot (download, digest, or install failed). The session stays on the image PHP.'
        return
    fi

    # The binary carries its own compiled-in scan dir, which is not the
    # distro's. Two drop-ins, matching what the CI action writes: a
    # memory_limit baseline (the compiled default is 128M, tuned for web SAPIs,
    # and OOMs composer and PHPStan) and the CI values in a later-sorted file.
    local scan_dir
    scan_dir="$(cloud_php_scan_dir /usr/local/bin/php)"
    [ -n "$scan_dir" ] || scan_dir='/usr/local/etc/php/conf.d'
    if { mkdir -p "$scan_dir" 2>/dev/null || sudo -n mkdir -p "$scan_dir" 2>/dev/null; } \
        && cloud_root_write "$scan_dir/00-defaults.ini" 'memory_limit=-1'; then
        if [ -n "$ini_values" ] && ! cloud_root_write "$scan_dir/99-ci-parity.ini" "$ini_values"; then
            warn "Could not write $scan_dir/99-ci-parity.ini. The sandbox PHP is not configured like CI."
        fi
    else
        warn "Could not write the ini drop-ins under $scan_dir. The sandbox PHP is not configured like CI."
    fi

    hash -r

    # Ask PHP whether the drop-ins took, rather than trusting that writing them
    # was enough. A misparsed scan dir still writes real files, just somewhere
    # PHP never reads, and nothing about the write fails — so the write
    # succeeding proves nothing and only the interpreter's own answer does.
    if [ -n "$ini_values" ] \
        && [ "$(/usr/local/bin/php -r 'echo ini_get("memory_limit");' 2>/dev/null)" = '128M' ]; then
        warn "The ini drop-ins under $scan_dir are not being read (memory_limit is still the compiled 128M default). The sandbox PHP is not configured like CI."
    fi

    # Installing to /usr/local/bin is not enough on images that resolve php
    # through a version-manager shim earlier in PATH. Say so rather than
    # reporting a restore the session will never actually use.
    if [ "$(php -r 'echo PHP_VERSION;' 2>/dev/null)" = "$expected_version" ]; then
        log "Static PHP restored from the snapshot: $(php -v | head -n1)."
    else
        warn "Restored /usr/local/bin/php ${expected_version}, but 'php' resolves to $(command -v php) ($(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)) — something earlier in PATH shadows it. Sessions run that PHP, not the CI build, until PATH puts /usr/local/bin first."
    fi
}

# Standalone invocation for debugging: bash scripts/cloud/snapshot.sh
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    set -euo pipefail
    source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
    cd "$(cloud_project_dir)" || exit 1
    cloud_snapshot_restore "${LOGRALO_SNAPSHOT_INI_VALUES:-}"
fi
