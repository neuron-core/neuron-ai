ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update && apt-get install -y \
    curl \
    libcurl4-openssl-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    poppler-utils \
    unzip \
    && docker-php-ext-install \
        calendar \
        curl \
        dom \
        mbstring \
        pcntl \
        pdo_mysql \
        simplexml \
        sockets \
        xml \
        zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer-cache

WORKDIR /app
