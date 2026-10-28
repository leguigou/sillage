# PHP 8.4 : sur 8.5 l'extension dom exige les en-têtes Lexbor absents de l'image officielle.
FROM php:8.4-apache
RUN apt-get update && apt-get install -y --no-install-recommends libxml2-dev libonig-dev libcurl4-openssl-dev default-mysql-client msmtp-mta python3 ca-certificates \
    && docker-php-ext-install pdo_mysql mbstring curl dom \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/sillage.conf \
    && a2enconf sillage
COPY deploy/php.ini /usr/local/etc/php/conf.d/sillage.ini
COPY . /var/www/html/
RUN chmod +x /var/www/html/deploy/entrypoint-dokploy.sh \
    && mkdir -p storage/uploads storage/mail storage/sessions && chown -R www-data:www-data storage
EXPOSE 80
