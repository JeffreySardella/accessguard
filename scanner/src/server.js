import express from 'express';
import { timingSafeEqual, createHash } from 'node:crypto';
import { runScan } from './scan.js';
import { assertUrlAllowed } from './urlGuard.js';
import { renderPdf } from './pdf.js';

export const app = express();
// Parse JSON globally at 1mb, EXCEPT /pdf, which needs a larger ceiling for
// full-report HTML. A single global parser would win regardless of any
// route-level parser (body-parser marks the body read, and a later parser
// short-circuits), so /pdf is excluded here and gets its own 5mb parser.
const jsonSmall = express.json({ limit: '1mb' });
const jsonPdf = express.json({ limit: '5mb' });
app.use((req, res, next) => (req.path === '/pdf' ? next() : jsonSmall(req, res, next)));

app.get('/health', (req, res) => res.json({ ok: true }));

// Every /scan and /pdf request launches a full Chromium (~hundreds of MB), so
// unbounded concurrency lets a burst of requests OOM the container. Cap
// in-flight browser work and shed the excess with 503 so the client (the
// Drupal queue) backs off and retries instead of piling on.
let inFlight = 0;
function withBrowserSlot(handler) {
  return async (req, res) => {
    const max = Math.max(1, parseInt(process.env.SCANNER_MAX_CONCURRENCY || '3', 10) || 3);
    if (inFlight >= max) {
      return res.status(503).json({ error: 'scanner_busy' });
    }
    inFlight++;
    try {
      await handler(req, res);
    } finally {
      inFlight--;
    }
  };
}

// Optional shared-secret auth: when SCANNER_AUTH_TOKEN is set, /scan requires
// a matching X-Scanner-Token header. Hashing both sides makes the comparison
// timing-safe regardless of length. Unset (the default) keeps the service
// open for setups that rely purely on network isolation.
function isAuthorized(req) {
  const token = process.env.SCANNER_AUTH_TOKEN || '';
  if (!token) return true;
  const presented = String(req.headers['x-scanner-token'] || '');
  const a = createHash('sha256').update(presented).digest();
  const b = createHash('sha256').update(token).digest();
  return timingSafeEqual(a, b);
}

app.post('/scan', withBrowserSlot(async (req, res) => {
  if (!isAuthorized(req)) {
    return res.status(401).json({ error: 'unauthorized' });
  }
  const { url } = req.body || {};
  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'Missing required "url" string.' });
  }
  try {
    await assertUrlAllowed(url);
  } catch (err) {
    // A hostname that simply doesn't resolve is a typo, not a policy block;
    // reporting it as url_not_allowed sends operators down the wrong path.
    const code = err && err.code === 'ENOTFOUND' ? 'host_not_found' : 'url_not_allowed';
    return res.status(400).json({ error: code });
  }
  try {
    const result = await runScan(url);
    res.json(result);
  } catch (err) {
    console.error('[accessguard-scanner] scan failed:', err);
    // Target-side failures (the page is broken/slow/not HTML) get 502 with a
    // specific code, so the client can tell them from scanner-internal
    // failures (500) and shed load (503). "Fix the page" vs "fix the
    // scanner" are different on-call pages.
    const targetCodes = ['target_http_error', 'target_not_html', 'navigation_timeout', 'axe_timeout'];
    if (err && targetCodes.includes(err.code)) {
      return res.status(502).json({ error: err.code, ...(err.status ? { status: err.status } : {}) });
    }
    res.status(500).json({ error: 'scan_failed' });
  }
}));

app.post('/pdf', jsonPdf, withBrowserSlot(async (req, res) => {
  if (!isAuthorized(req)) {
    return res.status(401).json({ error: 'unauthorized' });
  }
  const { html } = req.body || {};
  if (!html || typeof html !== 'string') {
    return res.status(400).json({ error: 'invalid_html' });
  }
  try {
    const pdf = await renderPdf(html);
    res.setHeader('Content-Type', 'application/pdf');
    res.send(pdf);
  } catch (err) {
    console.error('[accessguard-scanner] pdf failed:', err);
    res.status(500).json({ error: 'pdf_failed' });
  }
}));

const PORT = process.env.PORT || 3000;
if (process.env.NODE_ENV !== 'test') {
  // Last-resort guard: a rejection that escapes a request handler (e.g. a
  // Puppeteer event firing after browser teardown) must not kill the whole
  // service and every in-flight scan with it. Disabled under test so real
  // bugs still surface loudly there.
  process.on('unhandledRejection', (reason) => {
    console.error('[accessguard-scanner] unhandled rejection:', reason);
  });
  const server = app.listen(PORT, () => console.log(`accessguard-scanner listening on ${PORT}`));
  // Graceful shutdown: stop accepting new connections and let in-flight scans
  // finish, rather than cutting them off (and orphaning a Chromium) when the
  // orchestrator sends SIGTERM. A short hard-exit timer bounds the wait.
  for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, () => {
      console.log(`[accessguard-scanner] ${signal} received, draining…`);
      server.close(() => process.exit(0));
      setTimeout(() => process.exit(0), 10000).unref();
    });
  }
}
