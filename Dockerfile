FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl mysqli opcache pdo_mysql zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

COPY . .

RUN sed -i 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        'TraceEnable Off' \
        '<Directory /var/www/html/public>' \
        '    Options -Indexes +FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/application.conf \
    && a2enconf application \
    && printf '%s\n' \
        'display_errors=Off' \
        'log_errors=On' \
        'error_log=/proc/self/fd/2' \
        'expose_php=Off' \
        'session.use_strict_mode=1' \
        'session.use_only_cookies=1' \
        'session.save_path=/var/www/html/storage/sessions' \
        'upload_max_filesize=12M' \
        'post_max_size=14M' \
        > /usr/local/etc/php/conf.d/production.ini

COPY docker-entrypoint.sh /usr/local/bin/container-entrypoint.sh
RUN chmod +x /usr/local/bin/container-entrypoint.sh

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/container-entrypoint.sh"]
