#!/bin/bash
ROOT=$(dirname $0)"/.."

docker-compose -f "$ROOT/docker-compose.yml" -f "$ROOT/docker-compose-link-dir.yml" up -d