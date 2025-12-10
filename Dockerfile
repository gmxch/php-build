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

# Ensure VERSION provided
RUN test -n "${VERSION}" || (echo "ERROR: VERSION build-arg required" && exit 1)

# Download & optionally verify tarball
RUN wget -q "https://www.php.net/distributions/php-${VERSION}.tar.gz" -O php-${VERSION}.tar.gz && \
    if [ -n "${PHP_SHA256}" ]; then echo "${PHP_SHA256}  php-${VERSION}.tar.gz" | sha256sum -c -; fi && \
    tar -xzf php-${VERSION}.tar.gz && mv php-${VERSION} php-src

# Optional: patch files (pastikan path ada di build context)
# COPY patches/zend_language_scanner.l /build/php-src/Zend/zend_language_scanner.l
# COPY patches/php_cli.c            /build/php-src/sapi/cli/php_cli.c

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
    make -j$(nproc) && make install

# Strip executables to reduce size
RUN find ${PHP_PREFIX} -type f -executable -exec strip --strip-unneeded {} \; || true

# Create tarball of installed PHP
RUN tar -C ${PHP_PREFIX} -czf /php-${VERSION}.tar.gz .

# Stage 2: export tarball only (final artifact)
FROM scratch AS export
COPY --from=builder /php-${VERSION}.tar.gz /php-${VERSION}.tar.gz
