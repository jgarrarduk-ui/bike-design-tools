#!/bin/sh
# Start as root only long enough to make the /data volume writable, then
# run the server as the unprivileged node user.
set -eu

mkdir -p /data

if [ "$(id -u)" = "0" ]; then
  chown node:node /data
  exec gosu node "$@"
fi

exec "$@"
