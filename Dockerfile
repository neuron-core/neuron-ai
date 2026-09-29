ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update && apt-get install -y \
    curl \
    libcurl4-openssl-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    libpq-dev \
    libssl-dev \
    poppler-utils \
    unzip \
    && docker-php-ext-install \
        bcmath \
        calendar \
        curl \
        dom \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        simplexml \
        sockets \
        xml \
        zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN pecl install redis igbinary mongodb && docker-php-ext-enable redis igbinary mongodb

RUN echo "memory_limit=-1" > "$PHP_INI_DIR/conf.d/memory-limit.ini"

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer-cache
RUN mkdir -p $COMPOSER_HOME && chmod 1777 $COMPOSER_HOME

WORKDIR /app
