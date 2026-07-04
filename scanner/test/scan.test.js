import { runScan } from '../src/scan.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const fixture = 'file://' + path.join(dir, 'fixtures', 'missing-alt.html');

test('flags an image with no alt text', async () => {
  const result = await runScan(fixture);
  const rules = result.violations.map(v => v.ruleId);
  expect(rules).toContain('image-alt');
}, 30000);
