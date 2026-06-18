#!/bin/sh
set -e

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --no-progress
fi

exec "$@"
