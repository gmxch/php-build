# Stage 1: Build PHP
FROM ubuntu:22.04 AS builder

# Build args 
ARG VERSION
ARG YYYYMMDD
# Setup env
ENV DEBIAN_FRONTEND=noninteractive
ENV PHP_PREFIX=/usr/local/phpbuild
##ENV DATE=${YYYYMMDD}
ENV EXPIRY="${YYYYMMDD}"

# Install build deps
RUN apt-get update && apt-get install -y \
    libc6-dev-arm64-cross \
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

# Download PHP source
RUN wget https://www.php.net/distributions/php-${VERSION}.tar.gz && \
    tar -xzf php-${VERSION}.tar.gz && \
    mv php-${VERSION} php-src

# Patch custom
COPY php${VERSION}/zend_language_scanner.l /build/php-src/Zend/zend_language_scanner.l
COPY php${VERSION}/php_cli.c /build/php-src/sapi/cli/php_cli.c

# Build
WORKDIR /build/php-src
ENV PHP_SKIP_EXPIRY=gmxch-dev
RUN chmod +x buildconf && ./buildconf --force && \
    export CC=aarch64-linux-gnu-gcc && \
    export CXX=aarch64-linux-gnu-g++ && \
    ./configure --host=aarch64-linux-gnu --prefix=$PHP_PREFIX CFLAGS="-DHAVE_MEMFD_CREATE" \
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
    make clean && \
    ##make -j$(nproc) CFLAGS="-DEXPIRY_DATE=\"${EXPIRY}\"" && \
    make -j$(nproc) CFLAGS='-DEXPIRY_DATE="'"${EXPIRY}"'" -DHAVE_MEMFD_CREATE -DMFD_CLOEXEC=0x0001' \
     CPPFLAGS='-DEXPIRY_DATE="'"${EXPIRY}"'"'
    ##make -j$(nproc) CFLAGS='-DEXPIRY_DATE="'"${EXPIRY}"'"' CPPFLAGS='-DEXPIRY_DATE="'"${EXPIRY}"'"' && \
    make install

# Strip binary 
RUN find $PHP_PREFIX -type f -executable -exec strip --strip-unneeded {} \; || true

# Cleaning useless
RUN rm -rf $PHP_PREFIX/include \
           $PHP_PREFIX/lib/*.a \
           $PHP_PREFIX/lib/*.la \
           $PHP_PREFIX/php/man \
           $PHP_PREFIX/php/docs

# Stage 2: Export PHP tarball
FROM scratch AS export
COPY --from=builder /usr/local/phpbuild/ /php/
