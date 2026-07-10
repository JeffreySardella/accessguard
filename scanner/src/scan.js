import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolveAndAssert } from './urlGuard.js';
import { fetchPinned } from './pinnedFetch.js';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core');
const axeSource = readFileSync(axePath, 'utf8');

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

export async function runScan(url) {
  const args = ['--no-sandbox', '--disable-setuid-sandbox'];

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
      args.push(`--host-resolver-rules=MAP ${hostname} ${ip}`);
    }
  }

  const browser = await puppeteer.launch({
    headless: true,
    args,
  });
  try {
    const page = await browser.newPage();

    // Guard every request the page makes (the top-level navigation, any
    // redirects it follows, and every subresource) against the SSRF policy —
    // not just the URL we were handed. Each hostname is resolved and
    // validated exactly once per scan, and the request is then fulfilled by
    // fetchPinned() connecting to that vetted IP — Chromium never re-resolves
    // the name, so a DNS-rebinding answer after validation has nothing to
    // swap. Local schemes (file:, data:, about:, blob:) are not network SSRF
    // vectors and pass through.
    const pinnedIps = new Map();
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
        await req.respond({ status: res.status, headers: res.headers, body: res.body });
      } catch {
        await req.abort('blockedbyclient').catch(() => {});
      }
    });

    const response = await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
    // For http(s), refuse to "scan" an error page (403/404/500). Otherwise an
    // error page's markup would be recorded against the target and could, e.g.,
    // wrongly clear the publish gate. file:// navigations return no response
    // object, so only enforce this when a response is present.
    if (response && !response.ok()) {
      throw new Error(`Target returned HTTP ${response.status()}`);
    }
    await page.evaluate(axeSource);
    const raw = await page.evaluate(async (tags) => {
      const results = await window.axe.run(document, { runOnly: { type: 'tag', values: tags } });
      return results.violations;
    }, TAGS);

    const violations = [];
    for (const v of raw) {
      const wcag = v.tags.find((t) => /^wcag\d{3,}$/.test(t))
        || v.tags.find((t) => /^wcag/.test(t))
        || null;
      for (const node of v.nodes) {
        violations.push({
          ruleId: v.id,
          impact: v.impact,
          wcagCriterion: wcag,
          selector: Array.isArray(node.target) ? node.target.join(' ') : String(node.target),
          html: node.html,
          helpUrl: v.helpUrl,
        });
      }
    }
    return { url, violations };
  } finally {
    await browser.close();
  }
}
