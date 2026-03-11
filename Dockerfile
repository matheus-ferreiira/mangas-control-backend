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

# copia o projeto inteiro primeiro
COPY . .

# instala dependências
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction

# permissões
RUN chown -R www-data:www-data /app \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]