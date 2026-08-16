#!/usr/bin/env bash
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
cd "$(cloud_project_dir)" || exit 1

# Build the sandbox .env from the committed profile (lib.sh's CLOUD_ENV_PROFILE,
# .env.ci for repos with a CI profile, .env.example otherwise) and point the
# service hosts at localhost. The profile names services by their compose
# aliases, but in the sandbox they listen on 127.0.0.1. Only write .env when it
# is missing, so a resumed session keeps any edits made during the session.
if [ -f .env ]; then
    exit 0
fi

if [ ! -f "$CLOUD_ENV_PROFILE" ]; then
    warn "No $CLOUD_ENV_PROFILE found to build .env from."
    exit 0
fi

log "Writing .env from $CLOUD_ENV_PROFILE"
cp "$CLOUD_ENV_PROFILE" .env

# Rewrite any *_HOST whose value is a compose service name, rather than an
# enumerated key list, so a connection added to the profile is covered
# automatically. External hosts (MAIL_HOST) are left alone because only *_HOST
# keys with those exact values match.
sed -i -E \
    's/^([A-Z_]+_HOST)=(singlestore|redis|mysql|valkey)$/\1=127.0.0.1/' \
    .env

# An empty APP_KEY would fail the first artisan command that touches
# encryption. Profiles normally commit a fixed key; generate one only when the
# profile leaves it blank.
if grep -qE '^APP_KEY=\s*$' .env && [ -f artisan ]; then
    php artisan key:generate --ansi || warn 'key:generate failed.'
fi

# Repos on sqlite need the database file to exist before anything boots the
# app (composer post-autoload runs package:discover, which reads DB config).
if grep -qE '^DB_CONNECTION=sqlite' .env && [ -d database ]; then
    touch database/database.sqlite
fi
