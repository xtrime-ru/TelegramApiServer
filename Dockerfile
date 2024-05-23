FROM hub.madelineproto.xyz/danog/madelineproto:latest

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

ADD docker/php/conf.d/. "$PHP_INI_DIR/conf.d/"

EXPOSE 9503

ENTRYPOINT ["./entrypoint.sh"]