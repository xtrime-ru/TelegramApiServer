FROM php:8.3-cli

RUN apt-get update && apt-get upgrade -y
RUN true \
    # Install main extension
    && apt-get install procps git zip vim libzip-dev libgmp-dev libevent-dev libssl-dev libnghttp2-dev libffi-dev libicu-dev libonig-dev libxml2-dev libpng-dev -y \
    && docker-php-ext-install -j$(nproc) sockets bcmath mysqli pdo_mysql pcntl ffi intl gmp zip gd \
    # Install additional extension
    && mkdir -p /usr/src/php/ext/ && cd /usr/src/php/ext/ \
    && pecl bundle ev-beta && pecl bundle eio-beta \
    && docker-php-ext-install -j$(nproc) ev eio \
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

ADD https://github.com/ufoscout/docker-compose-wait/releases/download/2.9.0/wait /usr/local/bin/docker-compose-wait
RUN chmod +x /usr/local/bin/docker-compose-wait

ADD docker/php/conf.d/. "$PHP_INI_DIR/conf.d/"

EXPOSE 9503

ENTRYPOINT ["./entrypoint.sh"]