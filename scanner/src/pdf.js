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
