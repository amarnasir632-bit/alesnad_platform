FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libpq-dev \
    && docker-php-ext-install mbstring pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-app.conf /etc/apache2/sites-available/000-default.conf
COPY --chown=www-data:www-data . /var/www/alesnad
WORKDIR /var/www/alesnad

RUN mkdir -p /var/www/alesnad/storage/private \
    && chown -R www-data:www-data /var/www/alesnad/storage \
    && chmod 700 /var/www/alesnad/storage/private

ENV APACHE_DOCUMENT_ROOT=/var/www/alesnad/public
EXPOSE 10000
CMD ["bash", "docker/render-start.sh"]
