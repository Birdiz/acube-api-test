# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.4
ARG FRANKENPHP_VERSION=1

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine

WORKDIR /app

# Listen on an unprivileged port so the server can run as a non-root user.
ENV SERVER_NAME=":8080" \
    APP_ENV=dev \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PHP_INI_SCAN_DIR="$PHP_INI_DIR/conf.d:$PHP_INI_DIR/app.conf.d"

RUN apk add --no-cache curl git

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        apcu \
        intl \
        opcache \
        pdo_sqlite \
        zip \
        @composer

COPY docker/php/php.ini $PHP_INI_DIR/app.conf.d/app.ini

RUN mkdir -p /app/var && chown -R www-data:www-data /app /data/caddy /config/caddy

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost:2019/metrics >/dev/null || exit 1
