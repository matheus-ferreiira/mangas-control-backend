FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git curl nginx \
    && docker-php-ext-install pdo pdo_mysql zip opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Config nginx
RUN rm /etc/nginx/sites-enabled/default
COPY docker/nginx.conf /etc/nginx/sites-enabled/app.conf

EXPOSE 80

CMD ["sh", "-c", "php artisan config:clear && \
    php artisan migrate --force && \
    php-fpm -D && \
    nginx -g 'daemon off;'"]
