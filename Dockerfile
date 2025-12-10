# Stage 1: Build PHP (x86_64 native)
FROM ubuntu:24.04 AS builder

ARG VERSION
ARG PHP_SHA256=""
ENV DEBIAN_FRONTEND=noninteractive \
    PHP_PREFIX=/usr/local/phpbuild \
    SKIP_EXPIRY=gmxch-dev

# Install build deps (no cross toolchain)
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    wget \
    ca-certificates \
    tar \
    autoconf \
    libtool \
    pkg-config \
    bison \
    re2c \
    git \
    perl \
    make \
    zlib1g-dev \
    libxml2-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev \
    libzip-dev \
    libonig-dev \
    libbz2-dev \
    libreadline-dev \
    libicu-dev \
    libsodium-dev \
    gcc \
    g++ \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build




# Download & optionally verify tarball
RUN wget https://www.php.net/distributions/php-${VERSION}.tar.gz && \
    tar -xzf php-${VERSION}.tar.gz && \
    mv php-${VERSION} php-src


# Patch custom
COPY php${VERSION}/zend_language_scanner.l /build/php-src/Zend/zend_language_scanner.l
COPY php${VERSION}/php_cli.c /build/php-src/sapi/cli/php_cli.c



WORKDIR /build/php-src
RUN chmod +x buildconf || true && ./buildconf --force

# Configure & build (native x86_64)
RUN export CC=gcc && export CXX=g++ && \
    ./configure --prefix=${PHP_PREFIX} \
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
        --with-bz2 \
        --with-zlib \
        CFLAGS="-DHAVE_MEMFD_CREATE -O2" && \
    make clean && \
    make -j$(nproc) CFLAGS='-DHAVE_MEMFD_CREATE -DMFD_CLOEXEC=0x0001' && \
    make install

# Strip executables to reduce size
RUN find ${PHP_PREFIX} -type f -executable -exec strip --strip-unneeded {} \; || true

# Create tarball of installed PHP
RUN tar -C ${PHP_PREFIX} -czf /php-${VERSION}.tar.gz .


# Cleaning useless
RUN rm -rf $PHP_PREFIX/include \
           $PHP_PREFIX/lib/*.a \
           $PHP_PREFIX/lib/*.la \
           $PHP_PREFIX/php/man \
           $PHP_PREFIX/php/docs

# Stage 2: export tarball only (final artifact)
FROM scratch AS export
COPY --from=builder /usr/local/phpbuild/ /php/
