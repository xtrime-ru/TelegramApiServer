FROM php:8.4-cli-alpine

RUN apk add --no-cache make g++ && \
    curl -sSLf https://github.com/danog/PrimeModule-ext/archive/refs/tags/2.0.tar.gz | tar -xz && \
    cd PrimeModule-ext-2.0 && \
    make -j$(nproc) && \
    make install && \
    cd .. && \
    rm -r PrimeModule-ext-2.0 && \
    apk del make g++

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions pcntl uv-beta ffi pgsql memprof intl gmp mbstring pdo_mysql xml dom iconv zip igbinary gd && \
    rm /usr/local/bin/install-php-extensions

RUN apk add --no-cache ffmpeg nghttp2 jemalloc

ENV LD_PRELOAD=libjemalloc.so.2

STOPSIGNAL SIGTERM

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.0 > /tas_version

ADD docker/php/php.ini $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0
STOPSIGNAL SIGTERM

ENTRYPOINT ["./entrypoint.sh"]
