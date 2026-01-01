FROM dunglas/frankenphp:builder-php8.4

# Extensões PHP necessárias para Laravel
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

# Dependências do sistema
RUN apt update && apt install -y \
    git \
    unzip \
    cron \
    supervisor \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer -o composer-setup.php && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    rm composer-setup.php

# Copiar aplicação
WORKDIR /app
COPY . .

# Garantir permissões
RUN chown -R www-data:www-data /app \
    && chmod -R 775 storage bootstrap/cache

# Instalar dependências Laravel
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Gerar cache (opcional, mas recomendado)
RUN php artisan config:clear && \
    php artisan config:cache && \
    php artisan route:cache

EXPOSE 8000

ENTRYPOINT ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000"]