FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql \
    && a2enmod rewrite \
    && printf '%s\n' \
        '<VirtualHost *:80>' \
        '    ServerAdmin webmaster@localhost' \
        '    DocumentRoot /var/www/html/public' \
        '    DirectoryIndex index.php' \
        '' \
        '    <Directory /var/www/html/public>' \
        '        Options Indexes FollowSymLinks' \
        '        AllowOverride None' \
        '        Require all granted' \
        '        FallbackResource /index.php' \
        '    </Directory>' \
        '' \
        '    Alias /assets /var/www/html/public/assets' \
        '    Alias /favicon.png /var/www/html/public/favicon.png' \
        '' \
        '    <Directory /var/www/html/public/assets>' \
        '        Require all granted' \
        '    </Directory>' \
        '' \
        '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
        '    CustomLog ${APACHE_LOG_DIR}/access.log combined' \
        '</VirtualHost>' \
        > /etc/apache2/sites-available/000-default.conf \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
