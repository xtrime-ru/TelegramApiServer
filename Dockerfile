FROM php:7.4-cli

COPY . /app
WORKDIR /app

RUN apt-get update && apt-get upgrade -y \
    && apt-get install apt-utils -y \
    && apt-get install git zip vim libzip-dev libgmp-dev libevent-dev libssl-dev libnghttp2-dev libffi-dev -y \
    && docker-php-ext-install sockets zip gmp pcntl bcmath ffi \
    && PHP_OPENSSL=yes pecl install apcu ev event \
    && docker-php-ext-enable apcu ev event \
    && docker-php-source delete \
    && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer global require hirak/prestissimo \
    && composer install -o --no-dev \
    && composer clear

VOLUME ["/app/sessions"]

#Creating symlink to save .env in volume
RUN touch '/app/sessions/.env.docker' && \
    ln -s '/app/sessions/.env.docker' '/app/.env.docker'

EXPOSE 9503

ENTRYPOINT php server.php --docker -s=*