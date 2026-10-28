# Imagem de desenvolvimento (código montado por volume; servidor embutido do artisan).
FROM php:8.4-cli

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y git unzip libzip-dev libicu-dev default-mysql-client --no-install-recommends \
    && docker-php-ext-install pdo_mysql zip bcmath intl pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

EXPOSE 8010

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8010"]
