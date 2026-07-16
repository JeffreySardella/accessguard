import { withBrowserContext } from './browserPool.js';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolveAndAssert } from './urlGuard.js';
import { fetchPinned } from './pinnedFetch.js';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core');
const axeSource = readFileSync(axePath, 'utf8');

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

// Tunables, read per-call so deployments can override via env without a
// rebuild (and so tests can set them). Defaults match the prior hardcoded
// values. The nav+axe budget should stay under the Drupal client's Guzzle
// timeout so a slow-but-valid scan doesn't get abandoned client-side.
function limits() {
  return {
    navTimeoutMs: Number(process.env.SCANNER_NAV_TIMEOUT_MS) || 20000,
    axeTimeoutMs: Number(process.env.SCANNER_AXE_TIMEOUT_MS) || 60000,
    // Per-scan caps on outbound traffic. Without these, an author-controlled
    // page (a draft scanned with JS enabled) could issue unbounded
    // fetch()/subresource requests, turning the scanner into a DDoS
    // amplifier/reflector aimed at any public host from the scanner's IP. axe
    // needs the rendered DOM, not a thousand beacons, so a generous ceiling is
    // invisible to real pages.
    maxRequests: Number(process.env.SCANNER_MAX_REQUESTS) || 500,
    maxResponseBytes: Number(process.env.SCANNER_MAX_TOTAL_BYTES) || 100 * 1024 * 1024,
  };
}

// Errors that describe the *target page* (as opposed to the scanner itself)
// carry a machine-readable code so the HTTP layer can report them
// distinguishably — the Drupal side needs to tell "this author's page is
// broken" apart from "the scanner is broken".
function targetError(message, code, extra = {}) {
  const err = new Error(message);
  err.code = code;
  Object.assign(err, extra);
  return err;
}

export async function runScan(url) {
  const { navTimeoutMs, axeTimeoutMs, maxRequests, maxResponseBytes } = limits();
  return withBrowserContext(async (context) => {
    const page = await context.newPage();
    // The scanner injects axe-core with page.evaluate(); a target whose CSP
    // lacks 'unsafe-eval' would otherwise fail injection — penalizing exactly
    // the security-conscious sites. Safe here: every response is fetched and
    // fulfilled by the scanner itself (below), never by an uncontrolled page.
    await page.setBypassCSP(true);

    // Guard every request the page makes (the top-level navigation, any
    // redirects it follows, and every subresource) against the SSRF policy —
    // not just the URL we were handed. Each hostname is resolved and
    // validated exactly once per scan, and the request is then fulfilled by
    // fetchPinned() connecting to that vetted IP — Chromium never re-resolves
    // the name, so a DNS-rebinding answer after validation has nothing to
    // swap. Local schemes (file:, data:, about:, blob:) are not network SSRF
    // vectors and pass through.
    const pinnedIps = new Map();
    let httpRequestCount = 0;
    let totalResponseBytes = 0;
    await page.setRequestInterception(true);
    page.on('request', async (req) => {
      const reqUrl = req.url();
      if (!/^https?:/i.test(reqUrl)) {
        // Guarded like the abort path: if the browser is torn down (e.g. a
        // navigation timeout fired) while this event is in flight, continue()
        // rejects, and an unhandled rejection would kill the whole process.
        req.continue().catch(() => {});
        return;
      }
      // Anti-amplification: once a scan has issued too many outbound requests
      // or pulled too many total bytes, abort the rest. A legitimate page is
      // far under these ceilings; a malicious page trying to flood a third
      // party is cut off.
      httpRequestCount++;
      if (httpRequestCount > maxRequests || totalResponseBytes > maxResponseBytes) {
        await req.abort('blockedbyclient').catch(() => {});
        return;
      }
      try {
        const { hostname } = new URL(reqUrl);
        let ip = pinnedIps.get(hostname);
        if (!ip) {
          ({ ip } = await resolveAndAssert(reqUrl));
          if (!ip) {
            throw new Error('unresolved_host');
          }
          pinnedIps.set(hostname, ip);
        }
        const res = await fetchPinned(reqUrl, ip, {
          method: req.method(),
          headers: req.headers(),
          body: req.postData(),
        });
        totalResponseBytes += res.body ? res.body.length : 0;
        await req.respond({ status: res.status, headers: res.headers, body: res.body });
      } catch (err) {
        // Subresource failures abort quietly (the page still scans), but a
        // failed top-level navigation kills the whole scan and reaches the
        // caller only as ERR_BLOCKED_BY_CLIENT — log the real cause (TLS
        // failure, connection refused, guard rejection) or it's gone.
        if (req.isNavigationRequest()) {
          console.error('[accessguard-scanner] navigation fetch failed:', reqUrl, err && err.message ? err.message : err);
        }
        await req.abort('blockedbyclient').catch(() => {});
      }
    });

    // Wait for 'load', not networkidle0: pages with long-polling, websockets,
    // or analytics beacons never reach full network idle, but their DOM is
    // perfectly scannable. After load, give late subresources a short
    // best-effort quiet window.
    let response;
    try {
      response = await page.goto(url, { waitUntil: 'load', timeout: navTimeoutMs });
    } catch (err) {
      if (err.name === 'TimeoutError') {
        throw targetError(`Target did not finish loading within ${navTimeoutMs}ms`, 'navigation_timeout');
      }
      throw err;
    }
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 5000 }).catch(() => {});

    // For http(s), refuse to "scan" an error page (403/404/500). Otherwise an
    // error page's markup would be recorded against the target and could, e.g.,
    // wrongly clear the publish gate. file:// navigations return no response
    // object, so only enforce this when a response is present.
    if (response && !response.ok()) {
      throw targetError(`Target returned HTTP ${response.status()}`, 'target_http_error', { status: response.status() });
    }
    // Refuse non-HTML targets: axe against Chromium's synthesized viewer
    // document for JSON/images/PDFs yields bogus violations that would be
    // recorded as the page's compliance state. A missing content-type header
    // is allowed (the browser sniffs; common on static file servers).
    if (response) {
      const contentType = (response.headers()['content-type'] || '').toLowerCase();
      if (contentType && !contentType.includes('text/html') && !contentType.includes('application/xhtml+xml')) {
        throw targetError(`Target is not an HTML document (${contentType})`, 'target_not_html');
      }
    }

    await page.evaluate(axeSource);
    // Bound the axe run: on a pathological DOM it can otherwise hold a full
    // Chromium for minutes (the only backstop being the 180s protocol
    // timeout), long after the Drupal client has given up.
    const axeRun = page.evaluate(async (tags) => {
      const results = await window.axe.run(document, { runOnly: { type: 'tag', values: tags } });
      // Capture `incomplete` ("needs review") alongside `violations`. axe puts
      // genuine potential failures it can't decide automatically here — e.g.
      // color-contrast over a background image/gradient, or a control named
      // only via title — so dropping them silently lets real issues pass.
      // Report the engine version too so the module can detect when scans
      // (and thus waiver/regression continuity) span an axe-core upgrade.
      return {
        violations: results.violations,
        incomplete: results.incomplete,
        engineVersion: window.axe.version,
      };
    }, TAGS);
    // If the deadline wins, the evaluate rejects later during browser
    // teardown; the no-op catch keeps that from becoming an unhandled
    // rejection (it does not affect the race's own result).
    axeRun.catch(() => {});
    let timer;
    const deadline = new Promise((unusedResolve, reject) => {
      timer = setTimeout(() => reject(targetError(`axe-core did not finish within ${axeTimeoutMs}ms`, 'axe_timeout')), axeTimeoutMs);
    });
    let axeResult;
    try {
      axeResult = await Promise.race([axeRun, deadline]);
    } finally {
      clearTimeout(timer);
    }

    const flatten = (results) => {
      const out = [];
      for (const v of results || []) {
        const wcag = v.tags.find((t) => /^wcag\d{3,}$/.test(t))
          || v.tags.find((t) => /^wcag/.test(t))
          || null;
        for (const node of v.nodes) {
          out.push({
            ruleId: v.id,
            impact: v.impact,
            wcagCriterion: wcag,
            selector: Array.isArray(node.target) ? node.target.join(' ') : String(node.target),
            html: node.html,
            helpUrl: v.helpUrl,
          });
        }
      }
      return out;
    };

    return {
      url,
      violations: flatten(axeResult.violations),
      needsReview: flatten(axeResult.incomplete),
      engineVersion: axeResult.engineVersion,
    };
  });
}
