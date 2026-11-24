FROM php:8.4-cli-alpine AS base
RUN docker-php-ext-install pcntl
WORKDIR /app

FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM dev AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM base AS production
RUN adduser -D -u 1000 app && mkdir /data /backups && chown app:app /data /backups && chmod 700 /backups
COPY --from=vendor --chown=app:app /app /app
USER app
ENV DB_DATABASE=/data/database.sqlite BACKUP_PATH=/backups MONITOR_PATH=/data/status
CMD ["php", "artisan", "list"]
