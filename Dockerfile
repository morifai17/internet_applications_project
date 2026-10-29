# syntax=docker/dockerfile:1

# --- Composer dependencies (production only) ---------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- PHP-FPM application -----------------------------------------------------
FROM php:8.5-fpm-alpine AS app

RUN apk add --no-cache postgresql-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

COPY docker/php/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data bootstrap/cache storage

COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# --- Nginx front end ---------------------------------------------------------
FROM nginx:1.29-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /app/public /var/www/html/public
