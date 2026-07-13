# Scanner Browser Pooling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse one long-lived Chromium across `/scan` and `/pdf` requests (per-request isolated BrowserContexts) instead of paying a 1–3 s launch per request.

**Architecture:** A new `scanner/src/browserPool.js` owns a single shared browser behind a memoized launch promise with crash recovery and idle teardown. `scan.js` and `pdf.js` swap `puppeteer.launch()…browser.close()` for `withBrowserContext(fn)`; all page-level behavior (interception, CSP bypass, JS-off for PDFs, timeouts) is unchanged. The browser launches with the blanket `--host-resolver-rules=MAP * ~NOTFOUND` (already `/pdf`'s posture), replacing `scan.js`'s per-target DNS-pin launch arg — safe because every http(s) request is already fulfilled Node-side by `fetchPinned()` at a vetted IP, and stronger because hostname-based WebSockets from scanned pages can no longer resolve.

**Tech Stack:** Node 22+, Puppeteer 25, Express 4, Jest 29 (ESM via `node --experimental-vm-modules`).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-13-browser-pooling-design.md`.
- Run scanner tests from `scanner/` with `npm test -- <file>`; the full suite (44 existing tests) must stay green.
- Env knob names are exact: `SCANNER_BROWSER_IDLE_MS` (default `300000`, `0` = never close). Concurrency stays on the existing `SCANNER_MAX_CONCURRENCY` gate — this plan does not touch it.
- Every commit message ends with `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.
- Known residual (do not "fix"; pre-existing): a page can still attempt WebSockets to **IP-literal** addresses; resolver rules only govern hostnames. Same as before this change.

---

### Task 1: `browserPool.js` — shared browser with reuse, crash recovery, idle teardown

**Files:**
- Create: `scanner/src/browserPool.js`
- Test: `scanner/test/browserPool.test.js`

**Interfaces:**
- Consumes: nothing project-internal (only `puppeteer`).
- Produces: `withBrowserContext(fn: (context: BrowserContext) => Promise<T>): Promise<T>` — creates an isolated context on the shared browser, always closes it after `fn`; and `closeSharedBrowser(): Promise<void>` — closes the browser and cancels the idle timer (used by shutdown and test teardown). Tasks 2–4 rely on both names exactly.

- [ ] **Step 1: Write the failing tests**

Create `scanner/test/browserPool.test.js`:

```js
import { withBrowserContext, closeSharedBrowser } from '../src/browserPool.js';

afterEach(async () => {
  await closeSharedBrowser();
  delete process.env.SCANNER_BROWSER_IDLE_MS;
});

test('sequential requests reuse one Chromium process', async () => {
  const pid1 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  const pid2 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid2).toBe(pid1);
}, 30000);

test('the context is closed after the callback, even on error', async () => {
  let saved;
  await expect(withBrowserContext(async (ctx) => {
    saved = ctx;
    throw new Error('handler exploded');
  })).rejects.toThrow('handler exploded');
  // A closed context cannot create pages.
  await expect(saved.newPage()).rejects.toThrow();
}, 30000);

test('a crashed browser is replaced on the next request', async () => {
  const proc = await withBrowserContext(async (ctx) => ctx.browser().process());
  proc.kill('SIGKILL');
  // Let the disconnect event propagate.
  await new Promise((r) => setTimeout(r, 300));
  const pid = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid).not.toBe(proc.pid);
}, 30000);

test('the browser closes after the idle timeout and relaunches on demand', async () => {
  process.env.SCANNER_BROWSER_IDLE_MS = '100';
  const pid1 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  await new Promise((r) => setTimeout(r, 500));
  const pid2 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid2).not.toBe(pid1);
}, 30000);
```

- [ ] **Step 2: Run tests to verify they fail**

Run (from `scanner/`): `npm test -- test/browserPool.test.js`
Expected: FAIL — `Cannot find module '../src/browserPool.js'`.

- [ ] **Step 3: Implement the pool**

Create `scanner/src/browserPool.js`:

```js
import puppeteer from 'puppeteer';

/**
 * One shared Chromium for all scanner work.
 *
 * Every /scan and /pdf request used to launch (and tear down) a full browser
 * — a 1-3s cold start and ~300MB RSS per request. Instead, requests borrow an
 * isolated BrowserContext (own cookies/cache) from a single long-lived
 * browser; the existing withBrowserSlot gate still bounds concurrency.
 *
 * DNS posture: the shared browser maps every hostname to NOTFOUND. All
 * legitimate http(s) traffic is fulfilled Node-side by fetchPinned() (scan)
 * or aborted outright (pdf), so Chromium's own resolver is never needed —
 * and anything that would bypass request interception (e.g. a scanned page
 * opening a hostname-based WebSocket) cannot resolve at all.
 */
const LAUNCH_ARGS = [
  '--no-sandbox',
  '--disable-setuid-sandbox',
  // Containers default to a 64MB /dev/shm; Chromium exhausts it on
  // non-trivial pages and the renderer crashes. Route shared memory to /tmp.
  '--disable-dev-shm-usage',
  '--host-resolver-rules=MAP * ~NOTFOUND',
];

let browserPromise = null;
let inFlight = 0;
let idleTimer = null;

// Read per call so deployments override via env without a rebuild and tests
// can vary it. 0 disables idle teardown.
function idleMs() {
  const raw = process.env.SCANNER_BROWSER_IDLE_MS;
  if (raw === undefined || raw === '') return 300000;
  const n = Number(raw);
  return Number.isFinite(n) && n >= 0 ? n : 300000;
}

function ensureBrowser() {
  if (!browserPromise) {
    const p = puppeteer.launch({ headless: true, args: LAUNCH_ARGS }).then((browser) => {
      browser.on('disconnected', () => {
        // Only forget OUR promise: a newer browser may already be launching.
        if (browserPromise === p) browserPromise = null;
      });
      return browser;
    });
    // A failed launch must not wedge the pool with a rejected promise.
    p.catch(() => {
      if (browserPromise === p) browserPromise = null;
    });
    browserPromise = p;
  }
  return browserPromise;
}

async function acquireContext() {
  const attempt = ensureBrowser();
  try {
    const browser = await attempt;
    return await browser.createBrowserContext();
  } catch {
    // The browser died between requests (or launch failed). Forget it and
    // retry exactly once on a fresh browser; a second failure propagates.
    if (browserPromise === attempt) browserPromise = null;
    const browser = await ensureBrowser();
    return browser.createBrowserContext();
  }
}

function scheduleIdleClose() {
  const ms = idleMs();
  if (ms === 0 || inFlight > 0 || !browserPromise) return;
  idleTimer = setTimeout(() => {
    idleTimer = null;
    if (inFlight > 0) return;
    const p = browserPromise;
    browserPromise = null;
    if (p) p.then((b) => b.close()).catch(() => {});
  }, ms);
  // Never keep the process alive just to close an idle browser.
  idleTimer.unref?.();
}

export async function withBrowserContext(fn) {
  if (idleTimer) {
    clearTimeout(idleTimer);
    idleTimer = null;
  }
  inFlight++;
  try {
    const context = await acquireContext();
    try {
      return await fn(context);
    } finally {
      await context.close().catch(() => {});
    }
  } finally {
    inFlight--;
    scheduleIdleClose();
  }
}

export async function closeSharedBrowser() {
  if (idleTimer) {
    clearTimeout(idleTimer);
    idleTimer = null;
  }
  const p = browserPromise;
  browserPromise = null;
  if (p) {
    await p.then((b) => b.close()).catch(() => {});
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npm test -- test/browserPool.test.js`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add scanner/src/browserPool.js scanner/test/browserPool.test.js
git commit -m "feat(scanner): shared-browser pool with crash recovery and idle teardown

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: `pdf.js` renders through the pool

**Files:**
- Modify: `scanner/src/pdf.js` (whole file shown below)
- Modify: `scanner/test/pdf.test.js` (add teardown hook only)

**Interfaces:**
- Consumes: `withBrowserContext(fn)`, `closeSharedBrowser()` from Task 1.
- Produces: `renderPdf(html)` signature unchanged; callers (`server.js`) untouched.

- [ ] **Step 1: Add the shared-browser teardown hook to the test file**

In `scanner/test/pdf.test.js`, after the imports (`import http from 'node:http';`), add:

```js
import { closeSharedBrowser } from '../src/browserPool.js';

afterAll(async () => {
  await closeSharedBrowser();
});
```

(No new test: this task is a behavior-preserving refactor; the 9 existing
`/pdf` tests — including the iframe canary and the 5mb-limit tests — are the
spec. The hook keeps Jest from hanging on the now long-lived browser.)

- [ ] **Step 2: Replace the per-request launch in `pdf.js`**

Replace the entire file with:

```js
import { withBrowserContext } from './browserPool.js';

/**
 * Renders self-contained HTML to a PDF Buffer.
 *
 * The report HTML is expected to be fully self-contained. As defense in depth
 * (and matching the scanner's SSRF posture), request interception aborts every
 * outbound request except the main frame's about:blank bootstrap and data:
 * URIs — including sub-frame document loads (e.g. an attacker-supplied
 * <iframe src="http://internal/...">), which would otherwise sail through a
 * naive "allow all document requests" check. The shared browser additionally
 * pins DNS to NOTFOUND for every host (see browserPool.js).
 *
 * @param {string} html
 * @returns {Promise<Buffer>}
 */
export async function renderPdf(html) {
  return withBrowserContext(async (context) => {
    const page = await context.newPage();
    // The report is static HTML + inline CSS; no scripts should ever run.
    await page.setJavaScriptEnabled(false);
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      // setContent() injects the main document with no network fetch. The only
      // requests we allow are data: URIs and (defensively) the main frame's
      // about:blank bootstrap. Everything else — sub-frame <iframe> document
      // loads, scripted navigations, subresources — is aborted, so attacker-
      // controlled HTML cannot make the renderer fetch internal resources.
      const isMainBootstrap =
        req.frame() === page.mainFrame() &&
        req.isNavigationRequest() &&
        req.url() === 'about:blank';
      if (isMainBootstrap || req.url().startsWith('data:')) {
        // Guarded like the abort path below: if the context is torn down while
        // this event is in flight, continue() rejects, and an unhandled
        // rejection would kill the whole process.
        req.continue().catch(() => {});
        return;
      }
      req.abort('blockedbyclient').catch(() => {});
    });
    await page.setContent(html, { waitUntil: 'load', timeout: 20000 });
    const pdf = await page.pdf({
      format: 'A4',
      printBackground: true,
      margin: { top: '1cm', bottom: '1cm', left: '1cm', right: '1cm' },
      // Explicit render deadline so a pathological report can't hold a full
      // Chromium beyond it.
      timeout: 60000,
    });
    return Buffer.from(pdf);
  });
}
```

(The `puppeteer` import, the launch-args block, and the `finally
browser.close()` are gone; the pool owns the browser lifecycle and the launch
args now live in `browserPool.js`.)

- [ ] **Step 3: Run the pdf suite**

Run: `npm test -- test/pdf.test.js`
Expected: all 9 tests pass (same behavior, now via the shared browser).

- [ ] **Step 4: Commit**

```bash
git add scanner/src/pdf.js scanner/test/pdf.test.js
git commit -m "feat(scanner): /pdf renders on the shared browser pool

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: `scan.js` scans through the pool; WebSockets can't escape

**Files:**
- Modify: `scanner/src/scan.js` (two regions shown below)
- Test: `scanner/test/scan.test.js` (one new test + teardown hook)

**Interfaces:**
- Consumes: `withBrowserContext(fn)`, `closeSharedBrowser()` from Task 1.
- Produces: `runScan(url)` signature and result shape unchanged.

> **Amendment (2026-07-13):** the WebSocket test cannot go RED→GREEN in this
> harness — a `file://` scan tears the browser down before the WS handshake
> completes, so the assertion also holds under the pre-change per-request
> browser (for a timing reason, not the DNS rule). Kept as a **security
> invariant / forward regression guard**, with an honest comment. The DNS
> posture was verified out of band: with a 2 s window a plain browser lets
> `ws://localhost` connect (`touched=true`); adding `--host-resolver-rules=MAP
> * ~NOTFOUND` makes it fail with `ERR_NAME_NOT_RESOLVED` (`touched=false`).
> The canary uses a dual-stack `listen(0)` (no host) so it hears both `::1`
> and `127.0.0.1` — Windows resolves `localhost` IPv6-first.

- [ ] **Step 1: Write the WebSocket-escape invariant test**

In `scanner/test/scan.test.js`, after the imports add:

```js
import { closeSharedBrowser } from '../src/browserPool.js';

afterAll(async () => {
  await closeSharedBrowser();
});
```

and append this test at the end of the file:

```js
test('a scanned page cannot open a hostname-based WebSocket to the network', async () => {
  // WebSockets bypass page request interception entirely, so the only thing
  // standing between a scanned page and ws://internal-host is the browser's
  // DNS posture. The pooled browser maps every hostname to NOTFOUND.
  // (IP-literal WebSockets skip DNS and remain possible — unchanged from the
  // per-request-launch era; resolver rules only govern hostnames.)
  let touched = false;
  const canary = http.createServer(() => {});
  canary.on('connection', () => { touched = true; });
  // Dual-stack listen (no host): on Windows `localhost` resolves IPv6-first,
  // so an IPv4-only canary would miss the connection and the test would pass
  // for the wrong reason even without the DNS posture.
  await new Promise((resolve) => canary.listen(0, resolve));
  const port = canary.address().port;

  const wsFixture = path.join(os.tmpdir(), `accessguard-ws-${Date.now()}.html`);
  writeFileSync(wsFixture, `<!doctype html><html><body><h1>x</h1>
    <script>try { new WebSocket('ws://localhost:${port}/'); } catch (e) {}</script>
    </body></html>`);
  try {
    await runScan('file://' + wsFixture.replace(/\\/g, '/'));
    // Give a would-be connection time to land on the canary.
    await new Promise((r) => setTimeout(r, 500));
    expect(touched).toBe(false);
  } finally {
    unlinkSync(wsFixture);
    canary.close();
  }
}, 30000);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm test -- test/scan.test.js -t 'hostname-based WebSocket'`
Expected: FAIL — `expect(touched).toBe(false)` receives `true`: today's
per-request browser (launched with no resolver rule for file:// targets)
resolves `localhost` and connects to the canary.

- [ ] **Step 3: Switch `scan.js` to the pool**

In `scanner/src/scan.js`:

(a) Replace the import of puppeteer:

```js
import puppeteer from 'puppeteer';
```

with:

```js
import { withBrowserContext } from './browserPool.js';
```

(b) Inside `runScan`, delete this whole block (launch args and per-target
DNS pin — superseded by the pool's blanket NOTFOUND rule; the interception
layer below already resolves-and-vets every http(s) request Node-side):

```js
  const args = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    // Containers default to a 64MB /dev/shm; Chromium exhausts it on
    // non-trivial pages and the renderer crashes (SIGBUS / "Target closed").
    // Routing shared memory to /tmp is the standard headless-in-Docker fix.
    '--disable-dev-shm-usage',
  ];

  // Validate the target and pin its resolved IP into the browser, so Chromium
  // connects to the exact address we vetted rather than re-resolving the host
  // (which a DNS-rebinding attack could answer differently). Skipped for
  // non-http targets such as file:// (used by tests and the benchmark).
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    parsed = null;
  }
  if (parsed && (parsed.protocol === 'http:' || parsed.protocol === 'https:')) {
    const { hostname, ip } = await resolveAndAssert(url);
    if (ip) {
      // Chromium's host-resolver-rules parser wants IPv6 replacements
      // bracketed; an unbracketed v6 rule is silently ignored.
      const target = ip.includes(':') ? `[${ip}]` : ip;
      args.push(`--host-resolver-rules=MAP ${hostname} ${target}`);
    }
  }

  const browser = await puppeteer.launch({
    headless: true,
    args,
  });
  try {
    const page = await browser.newPage();
```

and replace it with:

```js
  return withBrowserContext(async (context) => {
    const page = await context.newPage();
```

(c) At the very end of `runScan`, the old teardown:

```js
  } finally {
    await browser.close();
  }
```

becomes:

```js
  });
```

(the function body between (b) and (c) — CSP bypass, interception with
`fetchPinned`, anti-amplification caps, goto/axe/timeouts, `return { url,
violations, needsReview, engineVersion }` — is untouched; only its
indentation now sits inside the `withBrowserContext` callback. Re-indent one
level to keep eslint happy.)

- [ ] **Step 4: Run the scan suite**

Run: `npm test -- test/scan.test.js`
Expected: all 12 tests pass, including the new WebSocket test (the pooled
browser's `MAP * ~NOTFOUND` makes `localhost` unresolvable) and the existing
`http://127.0.0.1` end-to-end test (unaffected: its requests are fulfilled by
`fetchPinned`, which never touches Chromium's resolver).

- [ ] **Step 5: Commit**

```bash
git add scanner/src/scan.js scanner/test/scan.test.js
git commit -m "feat(scanner): /scan runs on the shared browser pool, closing the ws DNS gap

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: graceful shutdown, README, full verification

**Files:**
- Modify: `scanner/src/server.js` (shutdown block at the bottom)
- Modify: `README.md` (roadmap → What's built)

**Interfaces:**
- Consumes: `closeSharedBrowser()` from Task 1.
- Produces: nothing new.

- [ ] **Step 1: Close the shared browser on shutdown**

In `scanner/src/server.js`, add to the imports at the top:

```js
import { closeSharedBrowser } from './browserPool.js';
```

and in the shutdown block at the bottom, replace:

```js
      server.close(() => process.exit(0));
```

with:

```js
      server.close(() => {
        closeSharedBrowser().finally(() => process.exit(0));
      });
```

(The existing 10 s hard-exit timer already bounds a hung close.)

- [ ] **Step 2: Update the README**

In `README.md`, under "What's built", extend the scanner bullet (line ~93,
"**Node scanner** (`scanner/`) — …") with:

```
, with a concurrency-capped shared-browser pool (per-request isolated contexts, crash recovery, idle teardown via `SCANNER_BROWSER_IDLE_MS`)
```

and delete the roadmap entry (and the now-empty section if nothing else
remains):

```
- Concurrency-limited browser pooling in the scanner (reuse instances under load).
```

- [ ] **Step 3: Run the full scanner suite**

Run (from `scanner/`): `npm test`
Expected: 6 suites, 49 tests, all passing (44 existing + 1 WebSocket + 4 pool).

- [ ] **Step 4: Verify the cold start is actually gone**

```bash
node src/server.js &   # or start in a separate terminal
for i in 1 2 3; do curl -s -o /dev/null -w "req $i: %{time_total}s\n" \
  -X POST http://127.0.0.1:3000/pdf -H 'content-type: application/json' \
  -d '{"html":"<!doctype html><html><body><h1>x</h1></body></html>"}'; done
```

Expected: request 1 noticeably slower (includes the one-time launch);
requests 2–3 well under half of request 1. Kill the server afterwards; it
should exit promptly (browser closed by the shutdown hook).

- [ ] **Step 5: Commit**

```bash
git add scanner/src/server.js README.md
git commit -m "feat(scanner): close the pooled browser on shutdown; README pooling docs

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```
