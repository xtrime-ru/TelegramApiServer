FROM danog/madelineproto:latest AS base

# Install system files and keep the existing root-based runtime behavior.
USER root

FROM base AS zstd-build

RUN apt-get update \
    && apt-get install -y --no-install-recommends autoconf gcc libc6-dev make pkg-config libtool unzip libzstd-dev \
    && rm -rf /var/lib/apt/lists/*

# Upstream PIE metadata omits --with-libzstd; pass its Autoconf variable instead.
RUN --mount=type=bind,from=ghcr.io/php/pie:bin,source=/pie,target=/usr/local/bin/pie \
    set -eux; \
    CFLAGS="-O3" with_libzstd=yes pie install --no-interaction kjdev/zstd; \
    install -D "$(php-config --extension-dir)/zstd.so" \
        "/artifacts$(php-config --extension-dir)/zstd.so"; \
    mkdir -p "/artifacts$PHP_INI_DIR/conf.d"; \
    echo 'extension=zstd.so' > "/artifacts$PHP_INI_DIR/conf.d/zstd.ini"

FROM base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzstd1 \
    && rm -rf /var/lib/apt/lists/*

COPY --link --from=zstd-build /artifacts/ /

RUN php --ri zstd

STOPSIGNAL SIGTERM

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.0 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0
STOPSIGNAL SIGTERM

ENTRYPOINT ["./entrypoint.sh"]
