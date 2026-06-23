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

# Configurar php-fpm para usar socket unix
RUN sed -i 's|listen = 127.0.0.1:9000|listen = /var/run/php/php8.2-fpm.sock|' \
    /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's|;listen.owner = www-data|listen.owner = www-data|' \
    /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's|;listen.group = www-data|listen.group = www-data|' \
    /usr/local/etc/php-fpm.d/www.conf

RUN mkdir -p /var/run/php

# Nginx config
RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/nginx.conf /etc/nginx/sites-enabled/app.conf

# Script de inicialização
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
