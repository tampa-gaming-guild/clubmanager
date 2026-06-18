#!/bin/sh
set -e

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
fi

# Bring the local schema up to date. Safe to run on every start: migrate/seed:run
# are no-ops once nothing is pending.
if [ -f vendor/bin/phinx ]; then
    vendor/bin/phinx migrate -e local
    vendor/bin/phinx seed:run -e local
fi

exec "$@"
