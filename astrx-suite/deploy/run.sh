#!/bin/sh
# astrx-suite — minimal deploy wrapper for environments without `make`.
#
#   ./deploy/run.sh up          # build + start the published stack
#   ./deploy/run.sh onions      # print the generated .onion hostnames
#   ./deploy/run.sh config      # validate the compose file
#   ./deploy/run.sh verify-tor  # parse-check deploy/torrc in the tor image
#   ./deploy/run.sh down        # stop the stack (volumes kept)
#   ./deploy/run.sh logs [svc]  # follow logs
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
ROOT=$(cd -- "$HERE/.." && pwd)
COMPOSE="docker compose -f $ROOT/docker-compose.yml"

cmd=${1:-up}; [ $# -gt 0 ] && shift || true
case "$cmd" in
  up)         exec $COMPOSE up -d "$@" ;;
  down)       exec $COMPOSE down "$@" ;;
  build)      exec $COMPOSE build "$@" ;;
  config)     exec $COMPOSE config "$@" ;;
  ps)         exec $COMPOSE ps "$@" ;;
  logs)       exec $COMPOSE logs -f "$@" ;;
  verify-tor) exec $COMPOSE run --rm tor --verify-config ;;
  onions)
    exec $COMPOSE exec tor sh -c \
      'for d in /var/lib/tor/*/; do [ -f "$d/hostname" ] && \
        printf "%-22s %s\n" "$(basename "$d")" "$(cat "$d/hostname")"; done' ;;
  *)
    echo "usage: $0 {up|down|build|config|ps|logs|verify-tor|onions}" >&2
    exit 2 ;;
esac
