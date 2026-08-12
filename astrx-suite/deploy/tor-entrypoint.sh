#!/bin/sh
# Prepare the persisted key volume, then hand off to Tor.
#
# The named volume (or a bind mount) can come up owned by root with loose
# permissions; Tor refuses to use a HiddenServiceDir tree that is not owned by
# its user and mode 0700. We fix that here as root, then exec Tor, which drops
# to the unprivileged `tor` user via the `User` directive in torrc.
set -eu

TOR_DATA=/var/lib/tor
mkdir -p "$TOR_DATA"
chown -R tor:tor "$TOR_DATA"
chmod 700 "$TOR_DATA"

# `exec` so Tor is PID 1 and receives signals; "$@" lets callers append flags
# such as `--verify-config` (see: docker compose run --rm tor --verify-config).
exec tor -f /etc/tor/torrc "$@"
