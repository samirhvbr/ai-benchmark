#!/usr/bin/env bash
# Runs Maven in a pinned container against a project directory, with a local Maven repository used as the offline cache.
#   usage: mvn.sh PROJECT_DIR [maven args...]          e.g. mvn.sh systems/300-A/reference -o -q test
# LEB_M2       the local repository (default ~/.cache/leb/m2). It must hold the system's dependencies for -o to work.
# LEB_M2_MODE  ro mounts it read-only, as an evaluation does, so the code under test cannot change it.
# LEB_DB=1     joins the disposable database's network (dev-db.sh) and points LEB_DB_URL at it.
# DOCKER_ARGS  extra docker options.
set -euo pipefail
PROJ="$(cd "$1" && pwd)"; shift
IMG="maven@sha256:99e61abcff91a9b1333463bd8451fb18495d6eba9250ac66a338b518f8278320"   # maven:3.9-eclipse-temurin-21
M2="${LEB_M2:-${XDG_CACHE_HOME:-$HOME/.cache}/leb/m2}"
mkdir -p "$M2"
ARGS=(--rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$M2:/m2${LEB_M2_MODE:+:$LEB_M2_MODE}" -v "$PROJ:/work" -w /work)
if [ "${LEB_DB:-}" = 1 ]; then
  ARGS+=(--network "${LEB_DB_NETWORK:-leb-series}" --cap-drop ALL --security-opt no-new-privileges:true --pids-limit 2048 --memory 8g
         -e "LEB_DB_URL=jdbc:postgresql://${LEB_DB_CONTAINER:-leb-series-pg}:5432/leb_dev" -e LEB_DB_USER=leb_dev -e LEB_DB_PASSWORD=leb_dev_pw)
fi
# shellcheck disable=SC2086
exec docker run "${ARGS[@]}" ${DOCKER_ARGS:-} --entrypoint mvn "$IMG" -Dmaven.repo.local=/m2 -B -ntp "$@"
