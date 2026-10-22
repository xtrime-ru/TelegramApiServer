FROM danog/madelineproto:latest

# Install system files and keep the existing root-based runtime behavior.
USER root

STOPSIGNAL SIGTERM

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.0 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0
STOPSIGNAL SIGTERM

ENTRYPOINT ["./entrypoint.sh"]
