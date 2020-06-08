FROM php:7.4-cli

COPY . /app
WORKDIR /app

ADD https://github.com/ufoscout/docker-compose-wait/releases/download/2.7.3/wait /usr/local/bin/docker-compose-wait

RUN apt-get update && apt-get upgrade -y \
    && apt-get install apt-utils -y \
    && apt-get install git zip vim libzip-dev libgmp-dev libevent-dev libssl-dev libnghttp2-dev libffi-dev -y \
    && docker-php-ext-install sockets zip gmp pcntl bcmath ffi \
    && PHP_OPENSSL=yes pecl install ev event \
    && docker-php-ext-enable ev event \
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && chmod +x /usr/local/bin/docker-compose-wait \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer global require hirak/prestissimo \
    && composer install -o --no-dev \
    && composer clear

VOLUME ["/app/sessions"]

#Creating symlink to save .env in volume
RUN touch '/app/sessions/.env.docker' && \
    ln -s '/app/sessions/.env.docker' '/app/.env.docker'

EXPOSE 9503

ENTRYPOINT docker-compose-wait && php server.php --docker -s=*