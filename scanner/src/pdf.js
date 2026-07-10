import puppeteer from 'puppeteer';

/**
 * Renders self-contained HTML to a PDF Buffer.
 *
 * The report HTML is expected to be fully self-contained. As defense in depth
 * (and matching the scanner's SSRF posture), request interception aborts every
 * outbound request except the main frame's about:blank bootstrap and data:
 * URIs — including sub-frame document loads (e.g. an attacker-supplied
 * <iframe src="http://internal/...">), which would otherwise sail through a
 * naive "allow all document requests" check. DNS is additionally pinned to
 * NOTFOUND for every host as a belt-and-suspenders measure.
 *
 * @param {string} html
 * @returns {Promise<Buffer>}
 */
export async function renderPdf(html) {
  const browser = await puppeteer.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      // Belt-and-suspenders: the report needs zero network, so make DNS fail
      // for every host. With the request interception below, this closes the
      // SSRF surface even if a request were to slip past interception.
      '--host-resolver-rules=MAP * ~NOTFOUND',
    ],
  });
  try {
    const page = await browser.newPage();
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
        // Guarded like the abort path below: if the browser is torn down while
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
    });
    return Buffer.from(pdf);
  } finally {
    await browser.close();
  }
}
