/**
 * AccessGuard benchmark.
 *
 * Runs a set of fixtures with KNOWN planted accessibility violations through
 * multiple tools and reports what each catches, as a Markdown table.
 *
 * Tools:
 *  - AccessGuard = axe-core with the full WCAG 2.0/2.1/2.2 A/AA ruleset
 *    (the exact configuration the AccessGuard scanner uses).
 *  - pa11y = HTML_CodeSniffer runner (a genuinely different engine).
 *  - Lighthouse = OPTIONAL. Lighthouse's accessibility audit uses a *subset*
 *    of axe-core, so it is only run if the `lighthouse` package is installed
 *    (`npm i lighthouse chrome-launcher`); otherwise its column reads "n/a".
 *
 * Honest framing: this is not a claim that AccessGuard has a better detection
 * engine than Lighthouse (Lighthouse uses axe too). It shows (a) coverage
 * differences from running the FULL ruleset vs a subset / a different engine,
 * and (b) that detection is the commodity — AccessGuard's value is the
 * governance layer (tracking, gating, attribution, audit export), which none
 * of these single-page tools provide.
 */

import puppeteer from 'puppeteer';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import path from 'node:path';

const require = createRequire(import.meta.url);
const axeSource = readFileSync(require.resolve('axe-core'), 'utf8');
const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

// Fixtures: [name, expected axe rule, HTML body].
const FIXTURES = [
  ['Missing image alt', 'image-alt', '<h1>x</h1><img src="https://example.com/a.png">'],
  ['Nameless button', 'button-name', '<h1>x</h1><button type="button"></button>'],
  ['Empty link', 'link-name', '<h1>x</h1><a href="/x"></a>'],
  ['Low contrast text', 'color-contrast', '<h1>x</h1><p style="color:#bcbcbc;background:#cccccc">grey on grey</p>'],
  ['Untitled iframe', 'frame-title', '<h1>x</h1><iframe src="about:blank" width="200" height="100"></iframe>'],
  ['Unlabeled input', 'label', '<h1>x</h1><form><input type="text" name="q"></form>'],
];

function fixtureHtml(body) {
  return `<!doctype html><html lang="en"><head><title>fixture</title></head><body>${body}</body></html>`;
}

async function runAxe(browser, url) {
  const page = await browser.newPage();
  await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
  await page.evaluate(axeSource);
  const violations = await page.evaluate(async (tags) => {
    const r = await window.axe.run(document, { runOnly: { type: 'tag', values: tags } });
    return r.violations.map((v) => v.id);
  }, AXE_TAGS);
  await page.close();
  return violations;
}

async function runPa11y(url) {
  try {
    const pa11y = (await import('pa11y')).default;
    const result = await pa11y(url, { runners: ['htmlcs'], timeout: 30000 });
    return result.issues.filter((i) => i.type === 'error').length;
  } catch (e) {
    return `err: ${e.message}`;
  }
}

async function runLighthouse(url) {
  let lighthouse, chromeLauncher;
  try {
    lighthouse = (await import('lighthouse')).default;
    chromeLauncher = await import('chrome-launcher');
  } catch {
    return 'n/a';
  }
  try {
    const chrome = await chromeLauncher.launch({ chromeFlags: ['--headless=new', '--no-sandbox'] });
    const res = await lighthouse(url, { onlyCategories: ['accessibility'], port: chrome.port });
    await chrome.kill();
    return Math.round((res.lhr.categories.accessibility.score || 0) * 100) + '/100';
  } catch (e) {
    return `err: ${e.message}`;
  }
}

async function main() {
  const dir = mkdtempSync(path.join(tmpdir(), 'ag-bench-'));
  const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] });

  const rows = [];
  let axeHits = 0;
  for (const [name, expected, body] of FIXTURES) {
    const file = path.join(dir, `${expected}.html`);
    writeFileSync(file, fixtureHtml(body));
    const url = 'file://' + file.replace(/\\/g, '/');

    const axeRules = await runAxe(browser, url);
    const axeCaught = axeRules.includes(expected);
    if (axeCaught) axeHits++;
    const pa11yErrors = await runPa11y(url);
    const lh = await runLighthouse(url);

    rows.push({ name, expected, axeCaught, axeCount: axeRules.length, pa11yErrors, lh });
  }
  await browser.close();

  // Build Markdown.
  let md = '# AccessGuard benchmark results\n\n';
  md += `Fixtures: ${FIXTURES.length} pages, each with one planted WCAG violation.\n\n`;
  md += '| Fixture | Planted rule | AccessGuard (axe, WCAG 2.2 AA) | pa11y (HTMLCS) errors | Lighthouse a11y |\n';
  md += '|---|---|:---:|:---:|:---:|\n';
  for (const r of rows) {
    md += `| ${r.name} | \`${r.expected}\` | ${r.axeCaught ? '✅ caught' : '❌ missed'} (${r.axeCount} total) | ${r.pa11yErrors} | ${r.lh} |\n`;
  }
  md += `\n**AccessGuard caught ${axeHits}/${FIXTURES.length} planted violations by exact rule id.**\n\n`;
  md += 'Notes: pa11y (HTML_CodeSniffer) is a different engine and reports its own error taxonomy, so its column is an error count, not a per-rule match. Lighthouse reads "n/a" unless `lighthouse` + `chrome-launcher` are installed; its accessibility audit uses a subset of axe-core. Detection is a commodity across these tools — AccessGuard\'s differentiator is the governance layer (historical tracking, publish-gating, author attribution, audit export), which none of these single-page tools provide.\n';

  const outDir = path.dirname(new URL(import.meta.url).pathname).replace(/^\/([A-Za-z]:)/, '$1');
  writeFileSync(path.join(outDir, 'RESULTS.md'), md);
  console.log(md);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
