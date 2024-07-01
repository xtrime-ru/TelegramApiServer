FROM danog/madelineproto:latest

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest /wait /usr/local/bin/docker-compose-wait

RUN echo 1.0.0 > /tas_version

EXPOSE 9503

ENV UV_USE_IO_URING=0

ENTRYPOINT ["./entrypoint.sh"]
