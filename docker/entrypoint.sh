#!/bin/sh
set -e

mkdir -p storage/logs storage/framework/cache storage/framework/views storage/app/private bootstrap/cache

if [ ! -d vendor ] || [ composer.lock -nt vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

exec "$@"
