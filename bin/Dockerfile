FROM php:8.3.23-cli-bullseye

WORKDIR /var/www/workdir

# Dipendenze di sistema necessarie a PHP
RUN apt-get update \
    && apt-get install -y \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        zip \
        zlib1g-dev \
    && docker-php-ext-install \
        intl \
        opcache \
        pdo \
        mysqli \
        pdo_mysql \
        pdo_pgsql \
        bcmath \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Prima composer.json/lock per sfruttare la cache Docker
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# Codice applicativo
COPY ../. .

# Permessi
RUN mkdir -p storage/cache storage/logs \
    && chown -R www-data:www-data /var/www/workdir

CMD ["php", "bin/scheduler"]