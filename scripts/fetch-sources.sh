#!/usr/bin/env bash
# Fetch the third-party sources this investigation reads. They are not vendored
# here: they belong to their upstreams and total ~30MB extracted.
#
# Everything lands under ./sources/, which is gitignored.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p sources
PPA="https://api.launchpad.net/1.0/~ondrej/+archive/ubuntu/php"

fetch_debian_source() {           # $1 = source package name
  local pkg="$1"
  local dir="sources/$pkg"
  echo "==> $pkg"
  mkdir -p "$dir"
  local url
  url=$(python3 -I - "$pkg" <<'PY'
import json, sys, urllib.request
pkg = sys.argv[1]
PPA = "https://api.launchpad.net/1.0/~ondrej/+archive/ubuntu/php"
def get(u):
    with urllib.request.urlopen(u) as r: return json.load(r)
e = get(f"{PPA}?ws.op=getPublishedSources&source_name={pkg}&exact_match=true")["entries"]
pub = [x for x in e if x["status"] == "Published"]
pub.sort(key=lambda x: x["date_published"], reverse=True)
for u in get(pub[0]["self_link"] + "?ws.op=sourceFileUrls"):
    if u.endswith("debian.tar.xz"):
        print(u); break
PY
)
  curl -fsSL -o "$dir/debian.tar.xz" "$url"
  tar -xf "$dir/debian.tar.xz" -C "$dir"
  echo "    patches: $(ls "$dir"/debian/patches/*.patch 2>/dev/null | wc -l | tr -d ' ')"
}

# The PHP 5.6 vendor backport, and the official 7.4 patch it derives from.
fetch_debian_source php5.6
fetch_debian_source php7.4

echo "==> HHVM 3.30.13 sources used in the analysis"
mkdir -p sources/hhvm
B="https://raw.githubusercontent.com/facebook/hhvm/HHVM-3.30.13"
for f in hphp/runtime/base/memory-manager.cpp hphp/runtime/base/memory-manager.h \
         hphp/runtime/base/runtime-option.cpp hphp/runtime/base/request-injection-data.h \
         hphp/runtime/vm/event-hook.cpp hphp/runtime/ext/session/ext_session.cpp \
         hphp/runtime/base/program-functions.cpp; do
  curl -fsSL -o "sources/hhvm/$(basename "$f")" "$B/$f"
done
echo "    $(ls sources/hhvm | wc -l | tr -d ' ') files"

cat <<'TXT'

Done. Useful starting points:

  sources/php5.6/debian/patches/0261-Avoid-state-corruption-via-bailout.patch
  sources/php7.4/debian/patches/*Avoid-state-corruption-via-bailout.patch
  sources/hhvm/runtime-option.cpp        (Server.RequestMemoryMaxBytes default)
  sources/hhvm/ext_session.cpp           (grep upload_progress: no matches)

Compare the two patch headers; the first nine lines are byte-identical.
TXT
