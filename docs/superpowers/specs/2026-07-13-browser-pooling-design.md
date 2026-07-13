# Scanner browser pooling — design

**Date:** 2026-07-13
**Status:** Approved
**Roadmap item:** "Concurrency-limited browser pooling in the scanner (reuse instances under load)."

## Problem

Every `/scan` and `/pdf` request launches a fresh Chromium (1–3 s cold start,
~300 MB RSS) and tears it down afterwards. The concurrency side of the roadmap
item already exists (`withBrowserSlot`, `SCANNER_MAX_CONCURRENCY`, 503
shedding); what is missing is reuse, so sustained load pays the cold start on
every request.

## Approach (chosen)

**One shared browser, per-request `BrowserContext`.** A single long-lived
Chromium serves both routes; each request gets its own isolated
`BrowserContext` (fresh cookie jar/cache), closed after the request. The
existing slot gate keeps bounding concurrency. Alternatives considered and
rejected: a pool of N full browsers (~3x memory, more bookkeeping, for
process-level isolation the threat model doesn't require) and a warm-spare
launcher (smallest win under sustained load, two idle browsers during bursts).

## Components

### `scanner/src/browserPool.js` (new)

- `withBrowserContext(fn)` — ensures the shared browser exists, creates a
  `BrowserContext`, awaits `fn(context)`, and always closes the context.
- Launch is a **memoized promise**, so concurrent cold-start requests share
  one launch instead of racing.
- `browser.on('disconnected')` clears the memo. If creating a context fails
  because the browser died, the pool relaunches **once** and retries; further
  failures propagate to the caller.
- **Idle teardown:** when in-flight work drops to zero, a timer
  (`SCANNER_BROWSER_IDLE_MS`, default 300000, `0` = never) closes the browser
  so an idle container doesn't pin ~300 MB. Any new request cancels the timer
  or relaunches.
- `closeSharedBrowser()` — used by the graceful-shutdown path in `server.js`
  and by Jest `afterAll` so workers don't hang on an open browser.

### Launch configuration (constant, shared by both routes)

```
--no-sandbox --disable-setuid-sandbox --disable-dev-shm-usage
--host-resolver-rules=MAP * ~NOTFOUND
```

The per-target `MAP <hostname> <ip>` launch arg in `scan.js` is **removed**.
It was belt-and-suspenders: since the SSRF hardening, every http(s) request —
navigation included — is intercepted and fulfilled Node-side by
`fetchPinned()` at a vetted IP, so Chromium's resolver is never on the request
path. The blanket `MAP * ~NOTFOUND` (already used by `/pdf`) is strictly
stronger: anything that bypasses request interception (e.g. a scanned page
opening a WebSocket) cannot resolve DNS at all. Net security posture improves.

### Call-site changes

- `scan.js`: replace `puppeteer.launch()` / `finally browser.close()` with
  `withBrowserContext()`; create the page from the context; drop the
  per-target resolver-rule block (the up-front `resolveAndAssert` in
  `server.js`'s `assertUrlAllowed` and the interception layer already cover
  validation). All page-level behavior (CSP bypass, interception policy,
  anti-amplification caps, timeouts) is unchanged.
- `pdf.js`: same swap; JS-disabled page and abort-everything interception
  unchanged.
- `server.js`: graceful shutdown also awaits `closeSharedBrowser()`.

## Error handling

- Browser crash mid-request: in-flight requests fail with today's error
  mapping (500/502); the next request relaunches. Accepted trade-off of the
  single-browser design.
- Context creation on a dead browser: one relaunch + retry inside the pool,
  then propagate.
- Interception verbs already tolerate teardown races (`.catch(() => {})`).

## Testing

Jest, `scanner/test/browserPool.test.js`:

1. Two sequential `withBrowserContext` calls observe the same Chromium pid
   (reuse).
2. Kill the browser process; the next call succeeds on a fresh browser
   (crash recovery).
3. With a short `SCANNER_BROWSER_IDLE_MS`, the browser closes after idle and
   the next call relaunches (idle teardown).

New security test in `pdf.test.js` or `scan.test.js`: a scanned page that
opens a WebSocket to a 127.0.0.1 canary never connects (blanket NOTFOUND).

Regression: the full existing scanner suite (44 tests, incl. SSRF canaries
and 5 MB-limit tests) passes unchanged. Manual verification: consecutive
`/pdf` requests via curl show the cold start eliminated (first request slow,
subsequent requests fast).

## Docs

README: move the roadmap line into "What's built" with a one-line description
of the pooling behavior and the `SCANNER_BROWSER_IDLE_MS` knob.
