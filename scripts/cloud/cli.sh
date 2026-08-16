#!/usr/bin/env bash
# Installs the Laravel Cloud CLI and authenticates it from the environment.
#
# The CLI does not read LARAVEL_CLOUD_API_TOKEN. Token resolution runs through
# ~/.config/cloud/config.json (ConfigRepository + HasAClient in
# laravel/cloud-cli), and when no token is stored there every authenticated
# command falls back to browser-based OAuth — which in a headless session
# blocks until the caller times out rather than failing. So we seed the file.
#
# The committed .cloud/config.json pins the org and the app; the credential
# lives in $HOME and never touches the repo.
#
# Safe to rerun: installing and seeding are both skipped when already done.
set -euo pipefail

# Composer refuses to run plugins as root unless this is set, and hosted
# sessions run as root. Harmless everywhere else.
export COMPOSER_ALLOW_SUPERUSER=1

if ! command -v cloud >/dev/null 2>&1; then
    composer global require laravel/cloud-cli --no-interaction --no-progress

    # `composer global` installs into COMPOSER_HOME, which varies by machine
    # (~/.composer on older setups, ~/.config/composer on newer ones). Ask
    # Composer instead of guessing.
    composer_bin="$(composer config --global home)/vendor/bin"

    # Prefer an existing PATH entry over editing anyone's shell profile.
    # Hosted sandboxes have a writable /usr/local/bin; a dev machine usually
    # already carries the Composer bin dir on PATH, so nothing is linked.
    if [[ ":$PATH:" != *":$composer_bin:"* ]] && [[ -w /usr/local/bin ]]; then
        ln -sf "$composer_bin/cloud" /usr/local/bin/cloud
    fi

    export PATH="$PATH:$composer_bin"
fi

if ! command -v cloud >/dev/null 2>&1; then
    echo "cloud: installed, but the binary is not on PATH" >&2
    exit 1
fi

if [[ -z ${LARAVEL_CLOUD_API_TOKEN:-} ]]; then
    echo "cloud: LARAVEL_CLOUD_API_TOKEN is unset; run \`cloud auth\` to log in" >&2
    exit 0
fi

config_file=$HOME/.config/cloud/config.json

mkdir -p "$(dirname "$config_file")"

existing='{}'
if [[ -f $config_file ]]; then
    existing=$(jq '.' "$config_file")
fi

if jq -e --arg t "$LARAVEL_CLOUD_API_TOKEN" \
    '(.api_tokens // []) | index($t)' <<<"$existing" >/dev/null; then
    echo "cloud: token already stored in $config_file" >&2
    exit 0
fi

# Append rather than replace: the CLI keeps one token per organization, and a
# local checkout may already hold tokens for orgs this one knows nothing about.
umask 077
jq --indent 4 --arg t "$LARAVEL_CLOUD_API_TOKEN" \
    '.api_tokens = ((.api_tokens // []) + [$t])' \
    <<<"$existing" \
    >"$config_file"
chmod 600 "$config_file"

echo "cloud: token written to $config_file" >&2
