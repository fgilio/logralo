#!/usr/bin/env bash
# Installs the Laravel Cloud CLI and seeds its API token, so a session comes up
# able to talk to production without an interactive login.
#
# Why a file and not the environment variable, and what goes wrong without it:
# docs/architecture/laravel-cloud-production.md, "Authenticating the CLI".
# The resolution path is ConfigRepository + HasAClient in laravel/cloud-cli.
#
# Requires: composer, jq. Safe to rerun, and a no-op without a token.
set -euo pipefail

# First, because everything below is a global mutation — a Composer install, a
# symlink, a file in $HOME — and none of it is worth anything without the token.
if [[ -z ${LARAVEL_CLOUD_API_TOKEN:-} ]]; then
    echo "cloud: LARAVEL_CLOUD_API_TOKEN is unset; run \`cloud auth\` to log in" >&2
    exit 0
fi

# Composer refuses to run plugins as root unless this is set, and hosted
# sessions run as root. Harmless everywhere else.
export COMPOSER_ALLOW_SUPERUSER=1

# Look for an existing install before deciding to install. Without this a
# session that installed the CLI but could not link it reinstalls on every
# start, because the `export PATH` below dies with this process.
composer_bin=""
for candidate in "$HOME/.config/composer/vendor/bin" "$HOME/.composer/vendor/bin"; do
    if [[ -x $candidate/cloud ]]; then
        composer_bin=$candidate
        break
    fi
done

# A `cloud` already on PATH is somebody else's install; leave it alone.
if [[ -z $composer_bin ]] && ! command -v cloud >/dev/null 2>&1; then
    composer global require laravel/cloud-cli --no-interaction --no-progress

    # The loop above covers the two conventional COMPOSER_HOME locations; a
    # machine that configured a third is why we ask Composer rather than guess.
    composer_bin="$(composer config --global home)/vendor/bin"
fi

if [[ -n $composer_bin ]]; then
    export PATH="$PATH:$composer_bin"

    # A child process cannot change its caller's PATH, so leave later shells a
    # binary they can find. Outside the install branch on purpose: the link is
    # the part that goes missing, and it has to come back without a reinstall.
    # Never replace a `cloud` somebody else put there.
    if [[ ! -e /usr/local/bin/cloud && -w /usr/local/bin ]]; then
        ln -s "$composer_bin/cloud" /usr/local/bin/cloud
    fi
fi

if ! command -v cloud >/dev/null 2>&1; then
    echo "cloud: installed, but the binary is not on PATH" >&2
    exit 1
fi

config_file=$HOME/.config/cloud/config.json

existing='{}'
if [[ -f $config_file ]]; then
    # Also the fail-fast on a corrupt config: without this parse, a malformed
    # file would read as "token not found" below and get truncated by the
    # append, because jq's exit 2 inside an `if` is indistinguishable from 1.
    existing=$(jq '.' "$config_file")
fi

if jq -e --arg t "$LARAVEL_CLOUD_API_TOKEN" \
    '(.api_tokens // []) | index($t)' <<<"$existing" >/dev/null; then
    echo "cloud: token already stored in $config_file" >&2
    exit 0
fi

# Append, never replace: a laptop may hold tokens for other orgs. Nothing
# prunes — see "Authenticating the CLI" in the architecture doc.
umask 077
mkdir -p "${config_file%/*}"
jq --indent 4 --arg t "$LARAVEL_CLOUD_API_TOKEN" \
    '.api_tokens = ((.api_tokens // []) + [$t])' \
    <<<"$existing" \
    >"$config_file"

# umask only governs a file this script creates; an existing one keeps its mode.
chmod 600 "$config_file"

echo "cloud: token written to $config_file" >&2
