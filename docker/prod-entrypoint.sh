#!/bin/sh
set -e

# A APP_KEY criptografa as credenciais bancárias gravadas no banco: precisa vir do
# ambiente e ser estável. Gerar uma nova tornaria todas as credenciais ilegíveis.
if [ -z "$APP_KEY" ] && ! grep -Eq '^APP_KEY=.+' /app/.env 2>/dev/null; then
    echo "[oursoft-pay] APP_KEY ausente. Defina-a no .env (php artisan key:generate --show) e guarde-a com segurança." >&2
    exit 1
fi

mkdir -p storage/logs storage/framework/cache storage/framework/views storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan config:cache
php artisan route:cache

# Só o container web migra (RUN_MIGRATIONS=true); fila/agendador apenas aguardam.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "$1" = "nginx" ]; then
    php-fpm -D
fi

exec "$@"
