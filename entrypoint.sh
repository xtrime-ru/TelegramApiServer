#!/usr/bin/env sh

docker-compose-wait \
&& nice -n 20 php server.php -e=.env.docker --docker "$@"
