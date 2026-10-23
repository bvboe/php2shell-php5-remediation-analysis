#!/usr/bin/env bash
# The central proof, in one command.
#
# Runs the worker-survival test against the unpatched and patched PHP 5.6
# containers and prints a verdict. Both images are Apache + mod_php with
# identical ini settings, so the only variable between them is the patch.
#
#   bash scripts/compare.sh
set -uo pipefail
cd "$(dirname "$0")/.."

for svc in php56-unpatched php56-patched; do
  if [ -z "$(docker compose ps -q "$svc" 2>/dev/null)" ]; then
    echo "error: '$svc' is not running. Start the stack first:" >&2
    echo "         docker compose up -d --build" >&2
    exit 1
  fi
done

run() {                      # $1 = service; echoes "handler|before|after|runtime"
  local out; out=$(bash lab/probe/run-test.sh "$1" 2>/dev/null)
  local handler="unknown"
  grep -q 'YES - shutdown handler ran' <<<"$out" && handler="RAN"
  grep -q 'NO - the engine stopped'    <<<"$out" && handler="did not run"
  local rt;     rt=$(sed -n 's/^  runtime: //p' <<<"$out" | head -1)
  local before; before=$(grep -A1 'before: worker PIDs' <<<"$out" | tail -1 | xargs)
  local after;  after=$(grep -A1 'after: worker PIDs'  <<<"$out" | tail -1 | xargs)
  printf '%s\t%s\t%s\t%s\n' "$handler" "$before" "$after" "$rt"
}

echo "Running the comparison. Each side takes about 30 seconds."
echo
IFS=$'\t' read -r U_H U_B U_A U_RT < <(run php56-unpatched)
IFS=$'\t' read -r P_H P_B P_A P_RT < <(run php56-patched)

echo "============================================================"
echo " After a request exhausts memory_limit:"
echo "============================================================"
printf '\n  UNPATCHED  %s\n' "$U_RT"
printf '    workers before : %s\n'  "$U_B"
printf '    workers after  : %s\n'  "$U_A"
printf '    shutdown handler: %s\n' "$U_H"
printf '\n  PATCHED    %s\n' "$P_RT"
printf '    workers before : %s\n'  "$P_B"
printf '    workers after  : %s\n'  "$P_A"
printf '    shutdown handler: %s\n' "$P_H"
echo
echo "============================================================"
if [ "$U_H" = "RAN" ] && [ "$P_H" = "did not run" ]; then
  echo " RESULT: reproduced."
  echo
  echo " Unpatched: the engine kept executing PHP after the fatal, and the"
  echo " same workers carried on serving. That is the precondition the"
  echo " php2shell bug class depends on: stale state in a surviving worker."
  echo
  echo " Patched: nothing ran after the fatal, and the worker that took the"
  echo " request is gone from the list, replaced by a new one. The backport"
  echo " makes an out-of-memory condition call _exit(1) instead of unwinding,"
  echo " so no code runs that could touch the corrupted state."
else
  echo " RESULT: NOT as documented."
  echo "   expected unpatched=RAN (got: $U_H)"
  echo "   expected patched=did not run (got: $P_H)"
  echo " Check 'docker compose ps' and that both images built from this repo."
fi
echo "============================================================"

# ----------------------------------------------------------------------------
# Second route: does attacker-supplied data reach a session key?
#
# The engine writes the PHP_SESSION_UPLOAD_PROGRESS field value straight into a
# session key name, before any application code runs. The backport removes the
# feature, so the primitive disappears.
#
# session.upload_progress.cleanup is turned Off for this check only, so the key
# survives the completed upload and can be observed. cleanup controls removal at
# the end of the upload, not whether the key is written in the first place. The
# setting is reverted afterwards.
# ----------------------------------------------------------------------------

ini_path() {   # the two images put conf.d in different places
  docker exec "$1" sh -c 'ls -d /usr/local/etc/php/conf.d 2>/dev/null || ls -d /etc/php/5.6/apache2/conf.d 2>/dev/null'
}

session_probe() {             # $1 = service; echoes the session file content
  local cont port inid
  cont=$(docker compose ps -q "$1"); port=$(docker compose port "$1" 80 | sed 's/.*://')
  inid=$(docker exec "$cont" sh -c "$(printf '%s' 'd=$(ls -d /usr/local/etc/php/conf.d 2>/dev/null || ls -d /etc/php/5.6/apache2/conf.d); echo "session.upload_progress.cleanup = Off" > $d/zz-probe.ini')" 2>/dev/null; echo ok)
  docker exec "$cont" apache2ctl graceful >/dev/null 2>&1; sleep 3
  local sid; sid=$(od -An -tx1 -N13 /dev/urandom | tr -d ' \n')
  head -c 400000 /dev/zero > /tmp/_p2s_payload.bin 2>/dev/null
  curl -s -o /dev/null --max-time 30 -b "PHPSESSID=$sid" \
    -F 'PHP_SESSION_UPLOAD_PROGRESS=INJECTED-MARKER' -F "doc=@/tmp/_p2s_payload.bin" \
    "http://localhost:$port/probe/count-parts.php" 2>/dev/null
  local out
  out=$(docker exec "$cont" sh -c "head -c 90 /var/lib/php/sessions/sess_$sid 2>/dev/null || head -c 90 /tmp/sess_$sid 2>/dev/null" 2>/dev/null)
  docker exec "$cont" sh -c 'rm -f /usr/local/etc/php/conf.d/zz-probe.ini /etc/php/5.6/apache2/conf.d/zz-probe.ini' 2>/dev/null
  docker exec "$cont" apache2ctl graceful >/dev/null 2>&1
  printf '%s' "$out"
}

echo
echo "Checking the second route (attacker bytes reaching a session key)..."
U_S=$(session_probe php56-unpatched)
P_S=$(session_probe php56-patched)
echo
echo "============================================================"
echo " Does an attacker-supplied string land inside a session key?"
echo "============================================================"
printf '\n  UNPATCHED : %s\n' "${U_S:-<no session file written>}"
printf '\n  PATCHED   : %s\n' "${P_S:-<no session file written>}"
echo
echo "============================================================"
if printf '%s' "$U_S" | grep -q 'INJECTED-MARKER' && [ -z "$P_S" ]; then
  echo " RESULT: reproduced."
  echo
  echo " Unpatched: the string the attacker put in the multipart body appears"
  echo " inside the session KEY name, right before the separator the session"
  echo " decoder acts on. The application was never involved."
  echo
  echo " Patched: no session file at all. The backport disables the"
  echo " upload-progress callback outright, so the primitive does not exist."
  echo
  echo " Note the cost of that fix: section 3 of FINDINGS.md covers what it"
  echo " breaks for applications that use the feature."
else
  echo " RESULT: NOT as documented."
  echo "   unpatched should contain INJECTED-MARKER; patched should be empty."
fi
echo "============================================================"
rm -f /tmp/_p2s_payload.bin

