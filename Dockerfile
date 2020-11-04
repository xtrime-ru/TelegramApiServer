FROM php:7.4-zts

ADD https://github.com/ufoscout/docker-compose-wait/releases/download/2.7.3/wait /usr/local/bin/docker-compose-wait

RUN apt-get update && apt-get upgrade -y \
    && apt-get install apt-utils -y \
    && apt-get install git zip vim libzip-dev libgmp-dev libevent-dev libssl-dev libnghttp2-dev libffi-dev -y \
    && docker-php-ext-install -j$(nproc) sockets zip gmp pcntl bcmath ffi \
    && docker-php-source extract \
    && cd /usr/src/php/ext/ \
    && pecl bundle event \
    && pecl bundle ev \
    && pecl bundle parallel \
    && docker-php-ext-configure event --with-event-core --with-event-extra --with-event-pthreads \
    && docker-php-ext-install -j$(nproc) ev event parallel \
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && rm -rf /usr/src \
    && chmod +x /usr/local/bin/docker-compose-wait \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /app
WORKDIR /app

RUN cd /app \
    && cp -a docker/php/conf.d/. "$PHP_INI_DIR/conf.d/" \
    && composer install --no-dev \
    && composer clear

VOLUME ["/app/sessions"]

#Creating symlink to save .env in volume
RUN touch '/app/sessions/.env.docker' && \
    ln -s '/app/sessions/.env.docker' '/app/.env.docker'

EXPOSE 9503

ENTRYPOINT docker-compose-wait && php server.php -e=.env.docker --docker -s=*