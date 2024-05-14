FROM php:8.3-cli

RUN apt-get update && apt-get upgrade -y
RUN true \
    # Install main extension
    && apt-get install procps git zip vim libzip-dev libgmp-dev libuv1-dev libssl-dev libnghttp2-dev libffi-dev libicu-dev libonig-dev libxml2-dev libpng-dev -y \
    && docker-php-ext-install -j$(nproc) sockets bcmath mysqli pdo_mysql pcntl ffi intl gmp zip gd \
    # Install additional extension
    && mkdir -p /usr/src/php/ext/ && cd /usr/src/php/ext/ \
    && pecl bundle uv && pecl bundle igbinary \
    && docker-php-ext-install -j$(nproc) uv igbinary \
    # Install PrimeModule for AuthKey generation speedup
    && git clone https://github.com/danog/PrimeModule-ext \
    && cd PrimeModule-ext && make -j$(nproc) \
    && make install \
    && cd ../  \
    && rm -rf PrimeModule-ext/ \
    # Install composer
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    # Cleanup
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && rm -rf /usr/src

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

ADD docker/php/conf.d/. "$PHP_INI_DIR/conf.d/"

EXPOSE 9503

ENTRYPOINT ["./entrypoint.sh"]