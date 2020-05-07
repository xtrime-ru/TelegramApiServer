FROM php:7.4-cli

COPY . /app
WORKDIR /app

RUN pecl install apcu ev \
    && docker-php-ext-enable apcu ev \
    && apt-get update && apt-get upgrade -y \
    && apt-get install git zip vim nano -y \
    && apt-get autoremove -y \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer global require hirak/prestissimo \
    && composer install -o --no-dev

VOLUME ["/app/sessions"]

EXPOSE 9503

ENTRYPOINT php server.php --docker -s=*