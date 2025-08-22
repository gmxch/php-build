# ==========================
# Stage 1: Build PHP
# ==========================
FROM ubuntu:22.04 AS builder

ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_PREFIX=/usr/local/php8

# Install build deps
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

WORKDIR /build

# Ambil source PHP
RUN wget https://www.php.net/distributions/php-8.4.11.tar.gz && \
    tar -xzf php-8.4.11.tar.gz && \
    mv php-8.4.11 php-src

# Patch kalau ada
COPY zend_language_scanner.l /build/php-src/Zend/zend_language_scanner.l

# Build PHP
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
        --with-zlib && \
    make -j$(nproc) && make install

# Strip binary supaya kecil
RUN find $PHP_PREFIX -type f -executable -exec strip --strip-unneeded {} \; || true

# Hapus file header, static lib, man, docs
RUN rm -rf $PHP_PREFIX/include \
           $PHP_PREFIX/lib/*.a \
           $PHP_PREFIX/lib/*.la \
           $PHP_PREFIX/php/man \
           $PHP_PREFIX/php/docs

# ==========================
# Stage 2: Export PHP murni
# ==========================
FROM scratch AS export
COPY --from=builder /usr/local/php8/ /php/
