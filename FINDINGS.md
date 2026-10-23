# php2shell on end-of-life PHP 5.6: is HHVM a way out?

An investigation into whether applications pinned to PHP 5.6 can be protected from
CVE-2026-109618 and CVE-2026-109619 by swapping the runtime for HHVM 3.30, and what the
available PHP 5.6 backport actually does.

All findings below were measured in the lab in this repository, not inferred.

---

## Summary

**A patched PHP 5.6 exists.** Ondřej Surý published `php5.6` version `5.6.40-104` on
21 October 2026, 45 minutes after upstream's release, with `-105` the next day. It covers
Ubuntu 22.04 and 24.04 on amd64 and arm64.

**It is a vendor backport, not an official PHP fix.** PHP's security team published
minimal source backports for 7.4, 8.0 and 8.1 only. Nothing official exists for 5.6, and
nothing will. Section 2 traces the provenance, because the distinction matters.

**HHVM is not a viable alternative.** It genuinely avoids these two CVEs, but it ships a
published unpatched critical RCE of its own, silently ignores its own memory limit, and
uses a process model that makes any memory-safety bug worse rather than better.

**The backport removes a feature, and that is the upgrade risk.** Session upload progress
stops working entirely, silently, and that breaks both progress bars and upload
cancellation. One grep tells you whether an application is affected.

---

## 1. What php2shell is

Two CVEs, disclosed by the PHP project on **21 October 2026 at 14:00 UTC** after details
leaked on 20 October.

| | |
|---|---|
| CVEs | CVE-2026-109618, CVE-2026-109619 |
| Severity | CVSS v4.0 **9.2** (PHP), 9.8 (NVD v3) |
| Impact | Unauthenticated remote code execution, plus remote memory disclosure |
| Fixed in | 8.2.35, 8.3.36, 8.4.27, 8.5.12 |
| 7.4 – 8.1 | No release. Minimal source backports only |
| 5.4 – 7.3 | End of life. **No upstream fix** |

### Root cause

When a request hits a fatal out-of-memory error, PHP tears down the request heap, but
several engine globals still point into that freed memory and survive into the *next*
request handled by the same worker. The named culprits are `BG(unserialize)`, `PS(id)`,
`EG(last_fatal_error_backtrace)` and `CG(map_ptr_last)`.

The memory-disclosure variant is the worst. After an out-of-memory abort, upload-progress
cleanup trusts a stale `PS(id)` pointer and URL-encodes raw heap contents into the
`PHPSESSID` cookie. That works **even if the application never calls `session_start()`**.

Three conditions must hold, and they drive everything that follows:

1. a worker process reused across requests,
2. engine globals living outside the request heap but pointing into it,
3. a fatal-error path that frees that heap while leaving those globals published.

### Exposure

Amazon's MadPot telemetry reported exploitation attempts on **17 October, four days before
disclosure**. Palo Alto put the exposed population at roughly 22.6 million instances.

---

## 2. The PHP 5.6 backport, and how official it is

### Provenance

**The underlying fix is official.** Upstream commit `31c0ea7d7f`, "Avoid state corruption
via bailout", authored by **Arnaud Le Blanc** on 18 October and committed by **Ilija
Tovilo**, both PHP core developers. It shipped in 8.2.35, 8.3.36, 8.4.27 and 8.5.12.

**PHP's official end-of-life backports stop at 7.4.** Given the severity, the security
team published minimal source patches for **7.4, 8.0 and 8.1** for people building from
source. **They published nothing for 5.6.**

**The 5.6 patch is a vendor backport of the official 7.4 patch.** Confirmed by fetching
both source packages and comparing the same patch. The first nine header lines are
**byte-identical**, down to the same `Date: Sun, 18 Oct 2026 20:09:54 +0200` timestamp.
The 5.6 version then appends a block headed "Backported from the PHP 7.4 version" listing
its deviations.

So the `From: Arnaud Le Blanc` line on the 5.6 patch is **inherited from the 7.4 patch**,
not evidence that PHP core produced the 5.6 port. That is the normal convention in this
packaging: across its 264 patches the `From:` field carries upstream authors such as Remi
Collet, Christoph Becker, Stanislav Malyshev and Nikita Popov. No DEP-3 `Origin:` or
`Author:` field records who did the 5.6 adaptation.

**The adaptation is substantial, not a mechanical rebase.** PHP 5.6 predates the PHP 7
zval refactor, hash table rewrite and VM changes, so the two patches touch different
files:

| | Files |
|---|---|
| Only in the official 7.4 patch | `zend_hash.c`, `zend_hash.h`, `zend_multiply.h`, `zend_types.h`, `zend_vm_def.h`, `zend_vm_execute.h` |
| Only in the 5.6 patch | `zend_API.c`, `zend_operators.c` |

The 5.6 header documents those decisions: keeping the 5.6 way of locating the script file
and line via TSRM and `EG(in_execution)`, starting GPC arrays with two buckets because
`nTableMask == 0` marks an uninitialised hash table in 5.6, resetting `SG(callback_func)`
in `sapi_deactivate()`, and using `|| 1` rather than `|| true`.

### How much confidence that justifies

**In its favour:** it derives from the official fix rather than being independently
invented; the deviations are documented rather than silent; the packager is the Debian PHP
maintainer, whose archive is what most of the PHP ecosystem already uses; and the
behavioural testing below shows both attack routes closed.

**Against:** no PHP core review of the 5.6 port exists, the adaptation crosses the largest
internal rewrite in PHP's history, and two files in it have no counterpart in the official
patch, meaning genuinely new code on a branch nobody upstream supports.

**Net:** for anyone who must remain on 5.6 it is the strongest option available and far
better than nothing, but it should be described as a well-sourced vendor backport, not as
an official PHP fix.

### What the patch does

Two changes, both deliberately blunt, which suits an end-of-life branch.

**On out-of-memory, kill the process immediately.** A new `zend_mm_safe_error_ex()` prints
the fatal error to stderr and calls `_exit(1)`. No bailout, no `longjmp`, no shutdown
handlers, no cleanup. Nothing gets a chance to touch the corrupted state. `zend_mm_panic()`
also moved from `exit(1)` to `_exit(1)`.

**Disable session upload progress outright.** In `session.c` the RFC1867 callback guard
becomes `if (!PS(rfc1867_enabled) || 1)`. The `|| 1` unconditionally short-circuits the
feature the memory-disclosure variant routes through.

The patch is `0261-Avoid-state-corruption-via-bailout.patch`, 530 lines, applied at
position 261 in the series. It touches `Zend/zend_alloc.c`, `Zend/zend_execute_API.c`,
`Zend/zend_operators.c`, `ext/session/session.c`, `main/rfc1867.c`, `main/SAPI.c`,
`main/php_variables.c` and others.

### Availability

| Series | Version | Architectures |
|---|---|---|
| Ubuntu 24.04 noble | `5.6.40-105+ubuntu24.04.1+deb.sury.org+1` | amd64, arm64 |
| Ubuntu 22.04 jammy | `5.6.40-105+ubuntu22.04.1+deb.sury.org+1` | amd64, arm64 |

`-105`, published 22 October, adds a CVE-2023-0662 multipart denial-of-service backport
and a new `max_multipart_body_parts` setting. `-103` from 3 July is the last
pre-disclosure build, useful as a vulnerable reference point.

### Verified working

Two distinct attack routes, both closed:

Both images are Apache with mod_php and identical ini settings, so the only variable is
the patch. Reproduce with `lab/Dockerfile.php56` and `lab/Dockerfile.php56-patched`.

| Route | Unpatched 5.6.40 | Patched `-105` |
|---|---|---|
| Out-of-memory, then reuse of stale state | HTTP 500, **shutdown handler ran**, all five workers still serving afterwards | Connection dropped, **no shutdown handler**, the worker that took the request is gone and replaced |
| Attacker bytes reaching a session key | `upload_progress_MARKER\|a:5:{...` | **No session file written at all** |

The second route is worth dwelling on. On the unpatched build an attacker-supplied string
lands *inside the session key name*, immediately before the separator the session decoder
acts on. The backport removes the feature, so that primitive does not exist. PHP 8.5.12
kept the feature and repaired the corruption instead. On this particular route the patched
end-of-life build has the **smaller** attack surface than the supported branch.

---

## 3. What the upgrade changes

It is not accurate to say nothing can break.

### It removes session upload progress, which has two uses

**Progress display stops working.** Same request to both builds, read the way a progress
bar would:

| Build | Result |
|---|---|
| Unpatched 5.6.40 | `PROGRESS KEY PRESENT`, `bytes_processed=2097490 content_length=2097490` |
| Patched `-105` | `NO PROGRESS KEY IN SESSION`, session empty |

**Upload cancellation stops working too**, which is easy to miss. In PHP 5.6's
`php_session_rfc1867_callback` the guard the patch modifies sits at the top, above the
entire event switch, so everything below is bypassed, including the cancellation path:

```c
if (progress && progress->cancel_upload) {   /* never reached after the patch */
```

An application that aborts an in-flight upload by setting
`$_SESSION['upload_progress_<name>']['cancel_upload'] = true` loses that ability.

### It fails silently, which is the real hazard

No error, no warning, no deprecation notice, and an empty FPM log after exercising the
feature. Worse, `session.upload_progress.enabled` still reports `'1'`, because the patch
disables the feature *below* the ini layer. `phpinfo()` will say the feature is on. Anyone
debugging a dead progress bar will check the setting, see it enabled, and look elsewhere.

The user-visible symptom reads worse than it is: the upload completes correctly, but the
progress bar never moves, so users report that uploads are broken.

### Likelihood is higher on old code

Server-side upload progress was the standard technique in the PHP 5.6 era, before
browser-side upload progress events were dependable. An application still requiring PHP
5.6 is precisely the vintage that would have used it.

### What does not break

**File uploads themselves are unaffected.** Verified end to end on the patched build
through the lab application: login, multipart POST, MIME validation, `move_uploaded_file`,
database record.

**Shutdown handlers are only lost on out-of-memory:**

| Fatal condition | Unpatched | Patched `-105` |
|---|---|---|
| Call to undefined function | handler runs | **handler runs** |
| Uncaught exception | handler runs | **handler runs** |
| Out of memory | handler runs | **does not run**, worker terminated |

Error trackers hooking `register_shutdown_function` keep working for everything except
memory exhaustion. The operational signature changes though: a logged PHP fatal becomes a
dropped connection plus worker churn, seen as a 502 behind a reverse proxy, so alerting
tuned to the old pattern needs adjusting.

**The new multipart setting is a non-issue.** `max_multipart_body_parts` defaults to `-1`,
which the patch resolves to `max_input_vars + max_file_uploads`, so 1020 by default. Forms
of 900 and 1500 fields behaved identically on both builds, because `max_input_vars`
already caps registration at 1000. It only bites if set explicitly low.

### The check to run before upgrading

```bash
grep -rniE 'upload_progress|PHP_SESSION_UPLOAD_PROGRESS' /path/to/app
```

No matches means the upgrade is functionally transparent. Matches mean two things need
review: any progress UI, straightforward to replace with client-side progress from the
browser, which needs no server support; and any cancel-upload path, which has no drop-in
equivalent.

---

## 4. Why HHVM is not the answer

HHVM 3.30 was the last series supporting PHP 5.6, and being a complete reimplementation it
genuinely does not share PHP's vulnerable internals. It fails for other reasons.

### It does avoid the two CVEs

Confirmed by reading the HHVM 3.30.13 source:

- No `longjmp`, `setjmp` or bailout anywhere in the memory manager.
- Out-of-memory is a cooperative surprise flag (`MemExceededFlag`) with an explicit
  request-level abort, not an unwind into cleanup.
- **`session.upload_progress` is not implemented at all.** Zero occurrences in
  `ext_session.cpp`. The memory-disclosure variant has nowhere to land.
- None of `BG(unserialize)`, `PS(id)`, `EG(last_fatal_error_backtrace)` or
  `CG(map_ptr_last)` exist. HHVM uses its own Request Data Segment.

The mechanism-level reasoning holds. The problems are elsewhere.

### It silently ignores its own memory limit

`Server.RequestMemoryMaxBytes` defaults to **16 GiB** (`16LL << 30` in
`runtime-option.cpp`), which on any real host is no protection. Worse, setting it does not
help. Configured explicitly to 32MB, HHVM reported the value back correctly, tracked usage
correctly, then allowed the request to reach **220MB**. It also passed
`RequestMemoryOOMKillBytes`, which defaults to 128MB.

A control that is accepted, echoed back and ignored is worse than an absent one.

Reproduced identically on native `linux/amd64` with jemalloc linked, so it is not an
architecture artifact. The cause is visible in the source: exceeding the limit only calls
`setSurpriseFlag(MemExceededFlag)`, and `handle_request_surprise()` handles
`EventHookFlag`, `DebuggerHookFlag`, `XenonSignalFlag`, `MemThresholdFlag` and
`IntervalTimerFlag`. It does not handle `MemExceededFlag`. Command-line mode does enforce
the limit, which is why the same probe dies there and survives in server mode.

**Scope, stated fairly.** php2shell-style traffic does **not** trigger this. Twelve
concurrent 20MB multipart POSTs moved HHVM from 128.6MiB to 135.1MiB, because HHVM streams
uploads to disk rather than buffering them in request memory. The two issues are
independent. The memory bug needs an *application* path an attacker can drive into heavy
allocation, such as an unbounded search or image processing on user input. On PHP such a
path fails one request with a 500. On HHVM it can take the whole instance down.

### Crashes recover, but not under sustained pressure

| | |
|---|---|
| Cold start to first response | 2s |
| Outage after a single out-of-memory kill | 1s |
| Restarts during a 90s sustained attack | 8 |
| **Availability during that attack** | **82%** |

Outages clustered rather than spreading evenly, consistent with container restart backoff
compounding. Concurrency would push this well below 82%, and every restart drops the
in-flight requests of every concurrent user.

### "Crashes but is not breached" does not hold

**A known unpatched critical RCE already exists.** CVE-2021-24036 is an integer overflow
in folly causing an out-of-bounds heap write, CVSS **9.8**, with the description
explicitly naming the possibility of remote code execution. It affects HHVM versions prior
to 4.80.5, which includes 3.30.12. Disclosed roughly two years after HHVM 3.30 went end of
life, so no fix exists for that line and none is coming.

**The image is a large unpatchable surface.** `hhvm/hhvm:3.30-lts-latest` is built on
Ubuntu 16.04. Scanned with grype against a current maintained image for contrast:

| Image | Findings | Unique CVEs | Fix exists but not applied | No fix will ever come |
|---|---|---|---|---|
| `hhvm/hhvm:3.30-lts-latest` | 3,623 | 1,557 | **474** | **3,131** |
| `php:8.4-cli` | 939 | 219 | **0** | 260 |

Raw counts are the less interesting number, since both images carry a full distribution.
The meaningful columns are the last two. The HHVM image is **474 available fixes behind**,
and **3,131** of its findings are on an end-of-life distribution that will never receive
patches. The maintained image is zero fixes behind. Named examples in the HHVM image
include OpenSSL 1.0.2g carrying CVE-2022-0778 and CVE-2023-0286.

**The process model makes any memory bug worse.** Asking each runtime which process served
each request:

| Runtime | Distinct PIDs over 8 requests |
|---|---|
| HHVM 3.30.12 | **PID=1 only** |
| PHP 5.6 via FPM | PID=54, PID=55 |

HHVM is a single multi-threaded process sharing one address space across all concurrent
users. An FPM worker handles one request at a time. For the *same class* of
memory-disclosure bug, PHP leaks that one request's data while HHVM can leak whatever
other users have in memory concurrently: session tokens, credentials, application data.

### Three further practical obstacles

- **It is 3.30.12, not 3.30.13.** The official image stops at 3.30.12. The 3.30.13 tag
  exists in git with no published build.
- **PHP 5.6 compatibility is partial.** The lab logs show
  `preg_replace(): Support for the /e modifier has been removed`. That modifier still
  works in PHP 5.6, so code using it breaks silently.
- **The distribution infrastructure is abandoned.** `dl.hhvm.com` serves a TLS certificate
  that expired on 26 September 2026.

Compatibility was not the blocker, for the record. The lab application ran fine on HHVM:
login, session regeneration, PDO SQLite, role gating and the staff view all worked. The
security and availability properties are what rule it out.

---

## 5. Why application code cannot fix this

A multipart POST carrying a `PHP_SESSION_UPLOAD_PROGRESS` field and an attacker-chosen
`PHPSESSID` cookie, aimed at a file containing nothing but:

```php
<?php
// deliberately empty: no session_start(), no upload handling, nothing.
exit;
```

The engine created session state on disk anyway:

```
-rw------- 1 www-data www-data 0 /tmp/sess_labprobe0000000000000000000001
```

The application never started a session, never touched `$_FILES`, never ran a meaningful
statement. The engine opened and wrote session state keyed on an attacker-supplied session
id, purely because of the shape of the request body. This is what the advisory means when
it says the highest-risk configuration is mod_php, where exploitation happens **before any
application code runs**.

The defaults make this near-universal. On stock PHP 5.6, unmodified, both
`session.upload_progress.enabled` and `file_uploads` are **On**.

What works is configuration, not code. Same no-op script, same request, one ini setting
changed:

| `session.upload_progress.enabled` | Session files the engine created |
|---|---|
| On, the stock default | **1** |
| Off | **0** |

That is the PHP security team's recommended interim mitigation for CVE-2026-109619, and it
works precisely because it removes the engine feature rather than asking the application to
behave differently. The companion interim mitigation for CVE-2026-109618 is setting
`memory_limit=-1` so applications cannot lower it into the fatal path.

**Scope caveat.** The "before any app code" property is specific to **mod_php**, which is
what was tested here. The advisory describes PHP-FPM as reachable through
application-stage paths, so on FPM the application matters more, though still not in a way
that can be reliably fixed by editing it. Both interim mitigations are partial. PHP states
that updating is the only complete fix.

---

## 6. Recommendations

**If you must stay on PHP 5.6.** Move to `5.6.40-104` or later from `deb.sury.org`, having
first run the grep in section 3 to find out whether you depend on session upload progress.
Plan for the out-of-memory behaviour change: worker termination and 502s where you
previously saw catchable fatals and 500s. Understand that you are relying on a vendor
backport that no upstream body has reviewed.

**Do not move to HHVM 3.30 for security reasons.** It avoids these two CVEs and will run a
typical PHP 5.6 application, but section 4 lists what you take on instead.

**The real fix is leaving 5.6.** Everything above buys time. PHP 5.6 reached end of life in
December 2018, and this incident will not be the last one where the supported branches get
a release and 5.6 gets whatever a volunteer adapts.

---

## 7. Method, and what it does not prove

### The method

The probes measure whether the engine keeps executing PHP after a fatal out-of-memory
error, and whether the worker survives to serve the next request. That is precondition 1
and 3 from section 1. They allocate ordinary strings until the limit is reached. There is
no exploit content in this repository, by design.

That target is the right one for these CVEs, and the patch confirms it. The backport is
named "Avoid state corruption via bailout", its single largest change is **10 hunks in
`Zend/zend_alloc.c`**, and the globals it explicitly resets are the bailout survivors:
`SG(request_info)` with 19 references, plus `SG(callback_func)`, `EG(opline_ptr)`,
`EG(active_op_array)`, `EG(in_execution)`, `EG(exception)` and `EG(error_handling)`.

### Three honest limits

**This measured a precondition, not the vulnerability.** The probe answers whether the
engine keeps executing after out-of-memory, which is the enabling condition for the bug
class rather than the bug itself. That was deliberate, to avoid producing exploit code for
a live, actively exploited vulnerability with no fix for the affected branch. It is a
strong proxy because the patch was read and the mechanism confirmed, but the right
description is "the behaviour the fix introduces was verified", not "the CVE was proven
fixed".

**An out-of-memory-centric method has a blind spot.** A probe built around fatal-error
handling cannot see a request-startup parsing bug, and such routes exist. The session-key
test in section 2 covers that second route, but it was written while investigating upgrade
breakage, not as part of the original design. Runtime comparisons should treat
request-startup parsing as a first-class case.

**Completeness is not established.** Two properties were verified. The patch carries
roughly 31 hunks across 13 files, including string-overflow exits in `zend_operators.c`,
GPC array initialisation in `php_variables.c` and the SAPI callback reset in `SAPI.c`,
none exercised individually.

### A verification caveat worth carrying

Grype's database was current, built 2026-10-22 18:51 UTC with 1,345 `CVE-2026-*` entries,
yet it contained **zero** occurrences of CVE-2026-109618 or CVE-2026-109619, and scored a
PHP 8.5.11 image at **0 findings** despite 8.5.11 being an affected version.

> For a CVE this new, a clean scan is not evidence of safety. Compare versions.

Any tooling that gates on scanner output alone will report these two CVEs as absent until
the database catches up. Reading the installed version and comparing it against the fixed
versions is the reliable check in the first days after disclosure.
