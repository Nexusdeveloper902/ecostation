# syntax=docker/dockerfile:1
# EcoStation web + realtime + scheduler.
#
# PHP 8.5.12 is the first PHP2Shell-patched release; do not lower this tag
# without also changing the runtime guard in web/bootstrap/app.php.
FROM php:8.5.12-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        sqlite3 \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first so code-only rebuilds keep the Composer layer.
COPY web/composer.json web/composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY web/ ./

RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && php artisan package:discover --ansi \
    && mkdir -p \
        storage/app/private \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/ecostation-entrypoint
RUN chmod +x /usr/local/bin/ecostation-entrypoint

EXPOSE 8000 8081

ENTRYPOINT ["/usr/local/bin/ecostation-entrypoint"]
