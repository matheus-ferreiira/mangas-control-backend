FROM php:8.2-fpm-alpine

# Dependências do sistema
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    zip \
    unzip \
    git \
    mysql-client \
    nodejs \
    npm

# Extensões PHP
RUN docker-php-ext-install pdo pdo_mysql bcmath opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copiar arquivos de dependência primeiro (cache de layers)
COPY composer.json composer.lock ./

# Instalar dependências sem scripts (scripts precisam dos arquivos do app)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copiar o restante do código
COPY . .

# Finalizar composer
RUN composer dump-autoload --optimize && \
    composer run-script post-autoload-dump || true

# Permissões
RUN chown -R www-data:www-data /var/www/html/storage \
    /var/www/html/bootstrap/cache && \
    chmod -R 775 /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Configuração nginx
RUN echo 'server { \
    listen 80; \
    root /var/www/html/public; \
    index index.php; \
    location / { try_files $uri $uri/ /index.php?$query_string; } \
    location ~ \.php$ { \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \
        include fastcgi_params; \
    } \
}' > /etc/nginx/http.d/default.conf

# Supervisor para rodar nginx + php-fpm juntos
RUN echo '[supervisord]\nnodaemon=true\n\
[program:php-fpm]\ncommand=php-fpm\nautostart=true\nautorestart=true\n\
[program:nginx]\ncommand=nginx -g "daemon off;"\nautostart=true\nautorestart=true' \
> /etc/supervisord.conf

EXPOSE 80

CMD ["sh", "-c", "php artisan config:clear && \
    php artisan migrate --force && \
    supervisord -c /etc/supervisord.conf"]
