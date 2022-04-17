FROM php:8.1-cli

ADD https://github.com/ufoscout/docker-compose-wait/releases/download/2.9.0/wait /usr/local/bin/docker-compose-wait
ADD docker/php/conf.d/. "$PHP_INI_DIR/conf.d/"

RUN apt-get update && apt-get upgrade -y \
    && apt-get install apt-utils procps -y \
    # Install main extension
    && apt-get install git zip vim libzip-dev libgmp-dev libevent-dev libssl-dev libnghttp2-dev libffi-dev -y \
    && docker-php-ext-install -j$(nproc) sockets zip gmp pcntl bcmath ffi mysqli pdo pdo_mysql \
    # Install additional extension
    && mkdir -p /usr/src/php/ext/ && cd /usr/src/php/ext/ \
    && pecl bundle ev \
    && docker-php-ext-install -j$(nproc) ev \
    # Install PrimeModule for AuthKey generation speedup
    && git clone https://github.com/danog/PrimeModule-ext \
    && cd PrimeModule-ext && make -j$(nproc) \
    && make install \
    && cd ../  \
    && rm -rf PrimeModule-ext/ \
    # Install composer
    && chmod +x /usr/local/bin/docker-compose-wait \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    # Cleanup
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && rm -rf /usr/src

EXPOSE 9503

ENTRYPOINT docker-compose-wait && nice -n 20 php server.php -e=.env.docker --docker -s=*