#!/usr/bin/env sh

VERSION=1.0.0
CURRENT_VERSION=$(cat /tas_version)

if [ "$VERSION" != "$CURRENT_VERSION" ]; then
    echo "Wrong docker image version, expected $VERSION, got $CURRENT_VERSION, please run docker compose pull!"
    exit 1
fi

composer install

docker-compose-wait \
&& nice -n 20 php server.php -e=.env.docker --docker "$@"
