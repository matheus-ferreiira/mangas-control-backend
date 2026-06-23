#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force

# Iniciar php-fpm em background
php-fpm -D

# Aguardar o socket estar pronto
for i in $(seq 1 10); do
    if [ -S /var/run/php/php8.2-fpm.sock ]; then
        echo "php-fpm socket ready"
        break
    fi
    echo "Waiting for php-fpm... ($i)"
    sleep 1
done

# Iniciar nginx em foreground
exec nginx -g 'daemon off;'
