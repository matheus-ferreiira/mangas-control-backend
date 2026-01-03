# ===============================
# STAGE 1 - Composer (build)
# ===============================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

# Instala dependências sem scripts (menos RAM)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-progress \
    --prefer-dist

# ===============================
# STAGE 2 - Runtime (FrankenPHP)
# ===============================
FROM dunglas/frankenphp:php8.4

# Extensões PHP necessárias
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

# Copia vendor pronto
COPY --from=vendor /app/vendor /app/vendor

# Copia o restante da aplicação
COPY . .

# Permissões
RUN chown -R www-data:www-data /app \
    && chmod -R 775 storage bootstrap/cache

# Cache leve (opcional)
RUN php artisan config:clear && php artisan config:cache

EXPOSE 8000

ENTRYPOINT ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]
