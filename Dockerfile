# syntax=docker/dockerfile:1

# ---------- Stage 1: build front-end assets ----------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci

# vite.config.js reads resources/ and writes public/build
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build


# ---------- Stage 2: PHP dependencies ----------
FROM php:8.4-cli-alpine AS vendor

RUN apk add --no-cache unzip git sqlite-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install without scripts/autoloader first so this layer caches on lockfile changes alone
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader


# Dev dependencies (phpunit, pint) for the `test` target below
FROM vendor AS vendor-dev
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader


# ---------- Stage 3: runtime ----------
FROM php:8.4-cli-alpine AS runtime

# pdo_mysql is the application driver; pdo_sqlite stays for host-side/offline test runs.
RUN apk add --no-cache su-exec sqlite-libs \
    && apk add --no-cache --virtual .build-deps sqlite-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql pdo_sqlite opcache \
    && apk del .build-deps

# Production opcache settings; the code never changes inside a built image
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=20000'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
      echo 'memory_limit=512M'; \
      echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# .env is never used in the container: configuration comes from compose environment
RUN rm -f .env \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && addgroup -g 1000 -S app \
    && adduser -u 1000 -S app -G app \
    && chown -R app:app storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=mysql \
    DB_HOST=mysql \
    DB_PORT=3306 \
    DB_DATABASE=inventory \
    DB_USERNAME=inventory \
    DB_PASSWORD=inventory \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    LOG_CHANNEL=stderr \
    PORT=8000

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["serve"]


# ---------- Stage 4: test runner ----------
# Same app, plus dev dependencies. phpunit.xml pins an in-memory SQLite database,
# so this target needs no volumes and no running services.
FROM runtime AS test

# Real environment variables win over phpunit.xml's <env> entries, so the runtime
# defaults inherited from the stage above must be restated with testing values here.
# The key is a throwaway for the test run only — not a secret, unused by `runtime`.
ENV APP_ENV=testing \
    APP_DEBUG=true \
    APP_KEY=base64:dGVzdC1vbmx5LWtleS1kby1ub3QtdXNlLWluLXByb2Q= \
    DB_CONNECTION=mysql \
    DB_HOST=mysql \
    DB_PORT=3306 \
    DB_DATABASE=inventory_test \
    DB_USERNAME=inventory \
    DB_PASSWORD=inventory \
    SESSION_DRIVER=array \
    CACHE_STORE=array \
    QUEUE_CONNECTION=sync \
    MAIL_MAILER=array \
    BCRYPT_ROUNDS=4

COPY --from=vendor-dev /app/vendor ./vendor
# `artisan test` reads base_path('.env'); without the file every test emits a warning.
RUN cp .env.example .env \
    && composer dump-autoload --optimize --no-interaction \
    && php artisan config:clear \
    && chown -R app:app vendor .env

# Goes through the entrypoint so the suite also waits for MySQL to accept connections.
ENTRYPOINT ["entrypoint", "artisan"]
CMD ["test"]
