#!/usr/bin/env bash
# A disposable PostgreSQL 16 for the Java systems of the next series: container leb-series-pg on the internal network
# leb-series, fictitious credentials, data on tmpfs. The code under evaluation reaches this database and nothing else.
#   usage: dev-db.sh up | ensure | down | status      (up recreates the container; ensure keeps a healthy one)
# The container and network names differ from any other LEB tooling on purpose, so this never touches another evaluation.
set -euo pipefail
IMG="postgres@sha256:57c72fd2a128e416c7fcc499958864df5301e940bca0a56f58fddf30ffc07777"   # postgres:16-alpine
NAME="${LEB_DB_CONTAINER:-leb-series-pg}"
NET="${LEB_DB_NETWORK:-leb-series}"
ready() { docker exec "$NAME" pg_isready -h 127.0.0.1 -U leb_dev -d leb_dev -q 2>/dev/null; }
case "${1:-status}" in
  up)
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    # An --internal network has no route out.
    if docker network inspect "$NET" >/dev/null 2>&1 && [ "$(docker network inspect "$NET" --format '{{.Internal}}')" != "true" ]; then
      docker network rm "$NET" >/dev/null
    fi
    docker network inspect "$NET" >/dev/null 2>&1 || docker network create --internal "$NET" >/dev/null
    docker run -d --name "$NAME" --network "$NET" -e POSTGRES_USER=leb_dev -e POSTGRES_PASSWORD=leb_dev_pw -e POSTGRES_DB=leb_dev \
      --tmpfs /var/lib/postgresql/data --shm-size 256m "$IMG" -c fsync=off -c max_connections=200 >/dev/null
    for _ in $(seq 1 60); do ready && { echo "up"; exit 0; }; sleep 1; done
    echo "PostgreSQL did not start" >&2; exit 1;;
  ensure)
    if [ "$(docker network inspect "$NET" --format '{{.Internal}}' 2>/dev/null)" = "true" ] && ready; then echo "up"; exit 0; fi
    exec "$0" up;;
  down)
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
    echo "down";;
  status)
    docker ps --filter "name=^${NAME}$" --format '{{.Names}} {{.Status}}';;
  *)
    echo "usage: dev-db.sh up | ensure | down | status" >&2; exit 2;;
esac
