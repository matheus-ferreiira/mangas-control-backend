#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force

# Iniciar php-fpm em background
php-fpm -D

# Aguardar porta 9000 estar pronta
echo "Waiting for php-fpm on port 9000..."
for i in $(seq 1 15); do
    if nc -z 127.0.0.1 9000 2>/dev/null; then
        echo "php-fpm is ready"
        break
    fi
    echo "  attempt $i/15..."
    sleep 1
done

# Iniciar nginx em foreground
exec nginx -g 'daemon off;'
