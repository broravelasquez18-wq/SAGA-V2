FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli gd zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Permite que el .htaccess del proyecto (bloqueo de config/, vendor/, .git, etc.) tenga efecto
RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

COPY composer.json composer.lock /var/www/html/
WORKDIR /var/www/html
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php \
    && composer install --no-dev --optimize-autoloader --no-interaction

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

COPY docker/saga-entrypoint.sh /usr/local/bin/saga-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/saga-entrypoint \
    && chmod +x /usr/local/bin/saga-entrypoint
ENTRYPOINT ["/usr/local/bin/saga-entrypoint"]
CMD ["apache2-foreground"]
