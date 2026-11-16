FROM dunglas/frankenphp:php8.5-trixie

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    DEBIAN_FRONTEND=noninteractive

WORKDIR /app

# Install system dependencies, PostgreSQL/intl/bcmath/pcntl PHP extensions, and Node.js 22
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        unzip \
        zip \
    && install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        pcntl \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Install Node dependencies
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Copy application source
COPY . .

# Build Wayfinder routes and production Vite bundle, then strip Node artifacts from runtime image
RUN cp .env.example .env \
    && sed -i 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' .env \
    && sed -i 's|^APP_KEY=.*|APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=|' .env \
    && composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && APP_ENV=production npm run build \
    && rm -f .env \
    && rm -rf node_modules \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && sed -i 's/\r$//' /app/docker/start.sh \
    && chmod +x /app/docker/start.sh

EXPOSE 8080

CMD ["/app/docker/start.sh"]
