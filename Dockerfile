ARG MADELINE_IMAGE=danog/madelineproto:latest
FROM ${MADELINE_IMAGE} AS base

# Install system files and keep the existing root-based runtime behavior.
USER root

# Compatibility with base images released before the asynchronous crypto executor.
FROM base AS crypto-build

COPY dev/crypto/ /usr/src/madeline-crypto/
RUN set -eux; \
    mkdir -p /artifacts; \
    if ! php -r 'try { $ffi = FFI::cdef("unsigned madeline_crypto_abi(void); unsigned madeline_crypto_operations(void);", getenv("MADELINE_CRYPTO_LIBRARY") ?: "/usr/local/lib/libmadeline-crypto.so"); exit($ffi->madeline_crypto_abi() === 2 && ($ffi->madeline_crypto_operations() & 7) === 7 ? 0 : 1); } catch (Throwable) { exit(1); }'; then \
        apt-get update; \
        apt-get install -y --no-install-recommends cmake gcc libc6-dev make libssl-dev; \
        cmake -S /usr/src/madeline-crypto -B /tmp/crypto-build -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTING=OFF; \
        cmake --build /tmp/crypto-build --config Release; \
        install -D /tmp/crypto-build/libmadeline-crypto.so /artifacts/usr/local/lib/libmadeline-crypto.so; \
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

ARG MADELINE_CRYPTO_LIBRARY
ENV MADELINE_CRYPTO_LIBRARY="${MADELINE_CRYPTO_LIBRARY:-/usr/local/lib/libmadeline-crypto.so}"

RUN php --ri zstd && php -r '$ffi = FFI::cdef("int madeline_ige_crypt(const char *in, char *out, size_t size, const char *key, char *iv, int encrypt); unsigned madeline_crypto_abi(void); unsigned madeline_crypto_operations(void);", getenv("MADELINE_CRYPTO_LIBRARY")); if ($ffi->madeline_crypto_abi() !== 2 || ($ffi->madeline_crypto_operations() & 7) !== 7 || $ffi->madeline_ige_crypt("", $ffi->new("char[1]"), 0, str_repeat("\0", 32), $ffi->new("char[32]"), 1) !== 1) { throw new RuntimeException("Native crypto initialization failed"); }'

STOPSIGNAL SIGTERM

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.0 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0
STOPSIGNAL SIGTERM

ENTRYPOINT ["./entrypoint.sh"]
