#!/bin/sh
set -e

php artisan config:clear
php artisan migrate --force

php-fpm -D

echo "Waiting for php-fpm..."
for i in $(seq 1 15); do
    if nc -z 127.0.0.1 9000 2>/dev/null; then
        echo "php-fpm ready"
        break
    fi
    sleep 1
done

exec nginx -g 'daemon off;'
