FROM danog/madelineproto:latest

STOPSIGNAL SIGTERM

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.1 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0
STOPSIGNAL SIGTERM

ENTRYPOINT ["./entrypoint.sh"]
