FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" curl mbstring \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . .

CMD ["php", "bot.php"]
