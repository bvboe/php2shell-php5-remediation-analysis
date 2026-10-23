#!/bin/bash
# Worker-survival test: does a worker keep serving after a request OOMs?
name="$1"; port="$2"; cont="$3"
echo "================= $name  (localhost:$port) ================="
docker exec "$cont" rm -f /tmp/shutdown-ran 2>/dev/null
echo "-- before: worker PIDs over 6 requests --"
b=$(for i in $(seq 6); do curl -s "http://localhost:$port/http-probe.php?a=id"; done | sed 's/ RUNTIME.*//' | sort -u | tr '\n' ' ')
echo "   $b"
curl -s "http://localhost:$port/http-probe.php?a=id" | sed 's/^/   runtime: /'
echo "-- triggering OOM request --"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "http://localhost:$port/http-probe.php?a=oom")
echo "   OOM request returned HTTP $code"
sleep 2
echo "-- after: worker PIDs over 6 requests --"
a=$(for i in $(seq 6); do curl -s "http://localhost:$port/http-probe.php?a=id"; done | sed 's/ RUNTIME.*//' | sort -u | tr '\n' ' ')
echo "   $a"
echo "-- did PHP keep executing after the fatal? --"
if docker exec "$cont" test -f /tmp/shutdown-ran 2>/dev/null; then
  echo "   SHUTDOWN HANDLER RAN  <-- engine continued executing PHP after OOM"
  docker exec "$cont" cat /tmp/shutdown-ran | sed 's/^/     /'
else
  echo "   no shutdown handler output  <-- engine stopped at the fatal"
fi
echo
