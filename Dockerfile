FROM mlabfactory/php8-apache:v1.4.1

# Imposta working directory (verifica sia quella corretta nell'immagine)
WORKDIR /var/www/workdir

# Copia solo composer per sfruttare cache Docker
COPY composer.json composer.lock ./

# Installa dipendenze production
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress

# Copia tutto il codice
COPY . .

# Permessi corretti
RUN chown -R www-data:www-data /var/www/workdir
RUN mkdir -p /var/www/workdir/storage/cache
RUN mkdir -p /var/www/workdir/storage/logs
RUN cp .env.example .env

# Se usi public/ come document root (es Slim/Laravel)
# Puoi eventualmente fare:
# ENV APACHE_DOCUMENT_ROOT /var/www/html/public

EXPOSE 80
