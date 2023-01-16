#!/usr/bin/env bash

docker-compose-wait \
&& nice -n 20 php server.php -e=.env.docker --docker "$@"