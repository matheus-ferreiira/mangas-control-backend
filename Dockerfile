# ===============================
# STAGE 1 - Composer (build)
# ===============================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-progress \
    --prefer-dist

# ===============================
# STAGE 2 - Runtime
# ===============================
FROM dunglas/frankenphp:php8.4

RUN install-php-extensions \
    pdo_mysql \
    gd \
    intl \
    bcmath \
    redis \
    curl \
    exif \
    mbstring \
    pcntl \
    xml \
    zip

WORKDIR /app

COPY --from=vendor /app/vendor /app/vendor

COPY . .

RUN chown -R www-data:www-data /app \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]