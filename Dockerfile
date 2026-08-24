FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev unzip \
    && docker-php-ext-install -j"$(nproc)" curl mbstring dom \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --optimize-autoloader

CMD ["php", "bot.php"]
