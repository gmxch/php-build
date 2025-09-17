# Stage 1: Build PHP
FROM ubuntu:22.04 AS builder

ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_PREFIX=/usr/local/phpbuild

# Build args (default 8.4.11 kalau gak diisi)
ARG PHP_VERSION=8.4.11

# Install build deps
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libsodium-dev \
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
    zlib1g-dev \
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

WORKDIR /build

# Download PHP source sesuai versi
RUN wget https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz && \
    tar -xzf php-${PHP_VERSION}.tar.gz && \
    mv php-${PHP_VERSION} php-src

# Patch sesuai custom (ambil dari folder root repo kamu)
COPY php${PHP_VERSION}/zend_language_scanner.l /build/php-src/Zend/zend_language_scanner.l
COPY php${PHP_VERSION}/php_cli.c /build/php-src/sapi/cli/php_cli.c

# Build
WORKDIR /build/php-src
RUN chmod +x buildconf && ./buildconf --force && \
    export CC=aarch64-linux-gnu-gcc && \
    export CXX=aarch64-linux-gnu-g++ && \
    ./configure --host=aarch64-linux-gnu --prefix=$PHP_PREFIX \
        --enable-cli \
        --enable-fpm \
        --enable-mbstring \
        --with-openssl \
        --with-curl \
        --enable-zip \
        --with-readline \
        --with-sodium \
        --enable-intl \
        --with-hash \
        --with-zlib && \
    make -j$(nproc) && make install

# Strip binary supaya kecil
RUN find $PHP_PREFIX -type f -executable -exec strip --strip-unneeded {} \; || true

# Hapus file tidak perlu
RUN rm -rf $PHP_PREFIX/include \
           $PHP_PREFIX/lib/*.a \
           $PHP_PREFIX/lib/*.la \
           $PHP_PREFIX/php/man \
           $PHP_PREFIX/php/docs

# Stage 2: Export PHP tarball
FROM scratch AS export
COPY --from=builder /usr/local/phpbuild/ /php/