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
  // Scan the URL as given. Chromium otherwise silently upgrades hostname
  // http:// navigations to https:// (HTTPS-First); the upgraded request is
  // fulfilled Node-side against the target's TLS endpoint, so an http-only
  // or dev-cert site fails (ERR_BLOCKED_BY_CLIENT) instead of being scanned,
  // and the abort suppresses Chromium's own fallback to http.
  '--disable-features=HttpsUpgrades,HttpsFirstBalancedModeAutoEnable',
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
    // Copy the args: puppeteer.launch() mutates the array it's given (it
    // folds --disable-features into its default flag and REMOVES the entry),
    // so passing LAUNCH_ARGS itself would strip that flag from every
    // relaunch after the first.
    const p = puppeteer.launch({ headless: true, args: [...LAUNCH_ARGS] }).then((browser) => {
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
  let browser = null;
  try {
    browser = await attempt;
    return await browser.createBrowserContext();
  } catch (e) {
    // A transient failure on a still-connected browser must propagate:
    // replacing a live browser here would orphan it (a leaked second
    // Chromium that nothing ever closes).
    if (browser?.connected) throw e;
    // The browser died between requests (or launch failed). Forget it and
    // retry exactly once on a fresh browser; a second failure propagates.
    if (browserPromise === attempt) browserPromise = null;
    const fresh = await ensureBrowser();
    return fresh.createBrowserContext();
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
