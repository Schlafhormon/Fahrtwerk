FROM php:8.4-fpm-alpine AS base
RUN apk add --no-cache icu-dev libpq-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev fcgi tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_pgsql intl zip gd mbstring opcache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/app-entrypoint.sh docker/fpm-health.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/fahrtwerk.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-fahrtwerk.conf
ENV TZ=Europe/Berlin
ENTRYPOINT ["/var/www/html/docker/app-entrypoint.sh"]
CMD ["php-fpm", "-F"]

FROM base AS test
USER root
RUN composer install --prefer-dist --no-interaction --no-scripts
ENTRYPOINT []
CMD ["php", "artisan", "test"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
EXPOSE 80

FROM base AS app
USER www-data
