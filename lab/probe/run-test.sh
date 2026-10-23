#!/usr/bin/env bash
# Worker-survival test.
#
# Question: after a request exhausts memory_limit, does the engine keep running
# PHP, and does the worker survive to serve the next request? That is the
# precondition the php2shell bug class depends on.
#
# Usage:  bash lab/probe/run-test.sh <service>
#   where <service> is a docker compose service name:
#     php56-unpatched | php56-patched | hhvm330
#
# Port and container are resolved from compose; nothing to look up by hand.
set -uo pipefail

SVC="${1:-}"
if [ -z "$SVC" ]; then
  echo "usage: bash lab/probe/run-test.sh <php56-unpatched|php56-patched|hhvm330>" >&2
  exit 2
fi

cd "$(dirname "$0")/../.."          # repo root, so compose finds its file

CONT=$(docker compose ps -q "$SVC" 2>/dev/null)
if [ -z "$CONT" ]; then
  echo "error: service '$SVC' is not running. Start it with:  docker compose up -d" >&2
  exit 1
fi
PORT=$(docker compose port "$SVC" 80 2>/dev/null | sed 's/.*://')
if [ -z "$PORT" ]; then
  echo "error: could not determine the published port for '$SVC'." >&2
  exit 1
fi

URL="http://localhost:$PORT/probe/http-probe.php"
pids() { for _ in $(seq 6); do curl -s --max-time 5 "$URL?a=id"; done \
           | sed 's/ RUNTIME.*//' | sort -u | tr '\n' ' '; }

echo "================= $SVC  (localhost:$PORT) ================="
docker exec "$CONT" rm -f /tmp/shutdown-ran 2>/dev/null

RUNTIME=$(curl -s --max-time 5 "$URL?a=id" | sed 's/.*RUNTIME=//')
if [ -z "$RUNTIME" ]; then
  echo "  error: no response from $URL"
  echo "         is the service healthy?  docker compose ps"
  exit 1
fi
echo "  runtime: $RUNTIME"

echo "  before: worker PIDs over 6 requests"
echo "     $(pids)"

echo "  triggering a request that exhausts memory_limit..."
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 40 "$URL?a=oom" 2>/dev/null)
echo "     that request returned HTTP ${CODE:-000}  (000 = connection dropped)"
sleep 2

echo "  after: worker PIDs over 6 requests"
AFTER="$(pids)"
echo "     ${AFTER:-(no response - the server itself is gone)}"

echo "  did PHP keep executing after the fatal?"
if docker exec "$CONT" test -f /tmp/shutdown-ran 2>/dev/null; then
  echo "     YES - shutdown handler ran. The engine continued executing PHP"
  echo "           after the fatal. This is the vulnerable shape."
else
  echo "     NO - the engine stopped at the fatal. Vulnerable shape absent."
fi
echo
