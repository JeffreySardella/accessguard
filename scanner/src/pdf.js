import puppeteer from 'puppeteer';

/**
 * Renders self-contained HTML to a PDF Buffer.
 *
 * The report HTML is expected to be fully self-contained. As defense in depth
 * (and matching the scanner's SSRF posture), every outbound request the page
 * attempts during rendering is aborted — nothing is fetched from the network.
 *
 * @param {string} html
 * @returns {Promise<Buffer>}
 */
export async function renderPdf(html) {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  try {
    const page = await browser.newPage();
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      // Allow only the initial document set via setContent; block all network.
      if (req.url().startsWith('data:') || req.resourceType() === 'document') {
        req.continue();
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
