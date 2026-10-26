ARG MADELINE_IMAGE=danog/madelineproto:latest
FROM ${MADELINE_IMAGE} AS base

# Install system files and keep the existing root-based runtime behavior.
USER root

# Reuse a compatible extension, otherwise build against this base image's PHP.
FROM base AS crypto-build
COPY dev/crypto/ /usr/src/madeline-crypto/
RUN set -eux; \
    mkdir -p /artifacts; \
    if ! php -r 'exit(extension_loaded("madeline_crypto") && \danog\MadelineProto\Native\Crypto::version() === "0.2.0" && version_compare(phpversion("madeline_crypto"), "0.2.1", ">=") ? 0 : 1);'; then \
        apt-get update; \
        apt-get install -y --no-install-recommends build-essential libclang-dev libssl-dev pkg-config curl ca-certificates; \
        curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --profile minimal --default-toolchain 1.92.0; \
        cd /usr/src/madeline-crypto; \
        /root/.cargo/bin/cargo build --release --locked; \
        install -D target/release/libmadeline_crypto.so "/artifacts$(php-config --extension-dir)/madeline_crypto.so"; \
        mkdir -p "/artifacts$PHP_INI_DIR/conf.d"; \
        echo 'extension=madeline_crypto.so' > "/artifacts$PHP_INI_DIR/conf.d/madeline-crypto.ini"; \
    fi

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
COPY --link --from=crypto-build /artifacts/ /

RUN php --ri zstd && php -r 'if (!extension_loaded("madeline_crypto") || \danog\MadelineProto\Native\Crypto::version() !== "0.2.0" || version_compare(phpversion("madeline_crypto"), "0.2.1", "<")) { throw new RuntimeException("Native crypto extension unavailable"); }'

STOPSIGNAL SIGTERM

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.0 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0
STOPSIGNAL SIGTERM

ENTRYPOINT ["./entrypoint.sh"]
