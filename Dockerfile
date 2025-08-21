# Base image
FROM ubuntu:22.04

# Environment
ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_PREFIX=/usr/local/php8

# Install build dependencies
RUN apt-get update && apt-get install -y \
    build-essential \
    libsqlite3-dev \
    libxml2-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev \
    libzip-dev \
    libonig-dev \
    libreadline-dev \
    pkg-config \
    bison \
    re2c \
    autoconf \
    wget \
    tar \
    make \
    perl \
    libtool \
    gcc-aarch64-linux-gnu \
    g++-aarch64-linux-gnu \
    git \
    && rm -rf /var/lib/apt/lists/*

# Set working dir
WORKDIR /build

# Download PHP source
RUN wget https://www.php.net/distributions/php-8.4.11.tar.gz && \
    tar -xzf php-8.4.11.tar.gz && \
    mv php-8.4.11 php-src

# Copy patch files (pastikan patch ada di folder build-context)
COPY zend_language_scanner.l /build/php-src/Zend/zend_language_scanner.l

# Configure & build PHP for ARM64
WORKDIR /build/php-src
RUN chmod +x buildconf && ./buildconf --force && \
    export CC=aarch64-linux-gnu-gcc && \
    export CXX=aarch64-linux-gnu-g++ && \
    ./configure --host=aarch64-linux-gnu --prefix=$PHP_PREFIX \
        --with-zlib \
        --enable-mbstring \
        --with-curl \
        --with-openssl \
        --enable-zip \
        --enable-cli \
        --enable-fpm \
        --with-readline && \
    make -j$(nproc) && make install

# Set PATH
ENV PATH="$PHP_PREFIX/bin:$PATH"

# Verify PHP
RUN php -v
