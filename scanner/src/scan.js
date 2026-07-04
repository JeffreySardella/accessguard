import puppeteer from 'puppeteer';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const axePath = require.resolve('axe-core');
const axeSource = readFileSync(axePath, 'utf8');

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

export async function runScan(url) {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox'],
  });
  try {
    const page = await browser.newPage();
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 20000 });
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
