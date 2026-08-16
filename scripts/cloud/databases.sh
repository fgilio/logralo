#!/usr/bin/env bash
# The SQLite database a cloud session works against.
#
# This is the join between the PHP track and the environment step: it needs
# vendor/ (php artisan) and a .env, so setup.sh runs it after both.
#
# Logralo has no service daemons to provision. Local dev and hosted sessions
# run on SQLite by choice; PostgreSQL is CI and production only, which is why
# this repo ships no ensure-services.sh and await.sh reports ready as soon as
# the bootstrap finishes.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
cd "$(cloud_project_dir)" || exit 1

if [ ! -f vendor/autoload.php ]; then
    warn 'vendor/autoload.php is missing, so the database cannot be migrated.'
    exit 1
fi

# Both of these normally happen in environment.sh, which runs first and owns
# them. They repeat here because environment.sh exits early when .env already
# exists: a resumed container, or a run that died between writing .env and
# finishing, leaves a .env whose key was never generated and whose database
# file was never created. Cheap to re-check, and the failure they prevent
# (every encryption path throwing) is not obvious from the symptom.
[ -f database/database.sqlite ] || touch database/database.sqlite

# Fail the track rather than warn: this is the last attempt, environment.sh
# having already tried and only warned. An app that reaches the session with an
# empty APP_KEY throws on every encryption path, and migrate below would still
# succeed and let setup.sh record 'done' — so await.sh would greenlight an
# environment nothing encryption-dependent can run in.
if grep -qE '^APP_KEY=\s*$' .env; then
    log 'Generating the application key'
    if ! php artisan key:generate --ansi --force; then
        warn 'key:generate failed. Every encryption path will throw until APP_KEY is set.'
        exit 1
    fi
fi

# migrate, not migrate:fresh: a resumed container keeps whatever the session
# was working with, and a cold one has an empty file, so this is idempotent
# either way. The suite builds its own schema, so this is for the app you
# actually drive (composer dev, artisan tinker, the browser suite's server).
log 'Migrating the SQLite database'
php artisan migrate --force
