FROM php:7.4-cli

COPY . /app
WORKDIR /app

#Remove .git and other dirs and files
RUN rm -rf .git/ .idea/ .DS_Store

RUN pecl install apcu ev \
    && docker-php-ext-enable apcu ev \
    && echo 'memory_limit = 512M' >> /usr/local/etc/php/conf.d/php.ini \
    && apt-get update && apt-get install git zip vim nano -y \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer global require hirak/prestissimo

RUN \
    [ ! -d "vendor" ] && composer install -o --no-dev; \
    \
    [ -f ".env" ] && cat .env || cat .env.example \
    | sed -e 's/SERVER_ADDRESS=127.0.0.1/SERVER_ADDRESS=0.0.0.0/g' \
    | sed -e 's/IP_WHITELIST=127.0.0.1/IP_WHITELIST=/g' \
    > .env;

VOLUME ["/app/sessions", "/app/.env:/app/.env"]

EXPOSE 9503

ENTRYPOINT php server.php -s=*