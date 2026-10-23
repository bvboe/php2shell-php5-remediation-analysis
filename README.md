# php2shell on EOL PHP 5.6, and whether HHVM helps

A reproducible lab investigating two questions about CVE-2026-109618 and
CVE-2026-109619 ("php2shell") for applications that cannot leave PHP 5.6:

1. Does the PHP 5.6 backport from `deb.sury.org` actually fix it, and what does it cost?
2. Does running the application on HHVM 3.30 avoid the problem instead?

**Short answers:** the backport works, but it is a vendor backport rather than an official
PHP fix, and it silently removes session upload progress. HHVM avoids these two CVEs and
introduces worse problems. Full writeup in **[FINDINGS.md](FINDINGS.md)**.

---

## ⚠️ Safety

One of these containers **deliberately runs an unpatched, end-of-life PHP 5.6** with known
unauthenticated remote code execution, actively exploited in the wild. Run it on a local
machine, on a private network, and never expose it. Stop the containers when finished.

This repository contains **no exploit code**. The probes measure engine *behaviour*, such
as whether PHP keeps executing after a fatal out-of-memory error. That was a deliberate
choice, explained in FINDINGS.md section 7.

## Quick start

```bash
docker compose up -d --build
```

| Service | Port | Runtime |
|---|---|---|
| `php56-unpatched` | 8059 | PHP 5.6.40, Jan 2019, Apache + mod_php |
| `php56-patched` | 8056 | php5.6 5.6.40-105 from deb.sury.org, Apache + mod_php |
| `hhvm330` | 8057 | HHVM 3.30.12, built-in server (amd64 only) |

The two PHP images are identical except for the patch, so comparisons between them isolate
it. HHVM is x86-64 only and runs under emulation on Apple silicon.

## The application

A small mortgage portal, `lab/app/`, written in PHP 5.6-era idioms: no scalar type hints,
no return types, no null coalescing. It exists so the runtimes are compared on something
with realistic surface area rather than a single script.

- Session-based auth with two roles, customer and adviser
- SQLite via PDO, seeded on first run
- A multipart document upload path, which is the surface these CVEs route through

Seeded logins are in `lab/app/lib/db.php`. Advisers `a.shah`, `d.okafor`; customers
`j.whitlow`, `m.ferreira`, `p.lindqvist`.

## Reproducing the findings

```bash
docker compose up -d --build
bash scripts/compare.sh
```

That is the whole proof. It takes about a minute and prints a verdict for both
attack routes, telling you whether each reproduced.

**Route 1, out-of-memory.** The unpatched build keeps executing PHP after the fatal and
the same workers carry on serving. The patched build runs nothing after the fatal and the
worker that took the request is gone, replaced by a new one. That is the precondition
these CVEs depend on, present and then absent.

**Route 2, session keys.** On the unpatched build a string the attacker put in the
multipart body appears inside a session *key name*, with the application never involved:

```
upload_progress_INJECTED-MARKER|a:5:{s:10:"start_time";i:...
```

On the patched build no session file is written at all.

### Running one runtime at a time

```bash
bash lab/probe/run-test.sh php56-unpatched
bash lab/probe/run-test.sh php56-patched
bash lab/probe/run-test.sh hhvm330
```

The service name is all you need; the port and container are resolved from compose.

> **Careful with `hhvm330`.** HHVM does not enforce `memory_limit` in server mode, so this
> test drives it until the kernel out-of-memory killer takes the whole process. That is
> the finding, not a mistake, but it will stop the container. Bring it back with
> `docker compose start hhvm330`. See FINDINGS.md section 4.

### The probes individually

Each isolates one behaviour. None contains exploit code. They are mounted at `/probe/`
inside each container, so for example `http://localhost:8059/probe/limit-probe.php`.

| Probe | Question it answers |
|---|---|
| `oom-probe.php` | Does the engine keep running PHP after a fatal out-of-memory error? |
| `http-probe.php` | Same question over HTTP, plus which worker served the request |
| `limit-probe.php` | Is `memory_limit` actually enforced? |
| `progress-read.php` | Does attacker-supplied data reach the session? |
| `fatal-kinds.php` | Which kinds of fatal still run shutdown handlers? |
| `count-parts.php` | How many multipart body parts are accepted? |

## Verifying the source claims

FINDINGS.md makes claims about patch provenance and HHVM internals. To check them:

```bash
./scripts/fetch-sources.sh
```

This downloads the `php5.6` and `php7.4` Debian source packages and the HHVM 3.30.13 files
referenced, into `sources/`, which is gitignored. Nothing third-party is vendored here.

The provenance claim is quickest to check directly:

```bash
diff <(sed -n 1,9p sources/php5.6/debian/patches/0261-Avoid-state-corruption-via-bailout.patch) \
     <(sed -n 1,9p sources/php7.4/debian/patches/*Avoid-state-corruption-via-bailout.patch)
```

Identical output, including the timestamp, is the evidence that the PHP core attribution on
the 5.6 patch is inherited from the official 7.4 patch rather than indicating who produced
the 5.6 port.

## If you only read one thing

Run this against your codebase before upgrading PHP 5.6:

```bash
grep -rniE 'upload_progress|PHP_SESSION_UPLOAD_PROGRESS' /path/to/app
```

The backport disables session upload progress unconditionally, which breaks both progress
bars and upload cancellation, with no error and with the setting still reporting as
enabled. No matches means the upgrade is functionally transparent.

## Caveats

Findings were measured on the setup above and state what was observed. FINDINGS.md section
7 sets out what the method does **not** establish, in particular that verifying the
behaviour a patch introduces is not the same as proving a CVE is fixed.

## Licence

The lab application and probes in `lab/` are original work in this repository; add whatever
licence you prefer before publishing. Material fetched by `scripts/fetch-sources.sh`
belongs to its respective upstreams, under their own licences, and is not redistributed
here.
