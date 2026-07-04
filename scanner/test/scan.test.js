import { runScan } from '../src/scan.js';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { app } from '../src/server.js';
import http from 'node:http';

const dir = path.dirname(fileURLToPath(import.meta.url));
const fixture = 'file://' + path.join(dir, 'fixtures', 'missing-alt.html');

test('flags an image with no alt text', async () => {
  const result = await runScan(fixture);
  const rules = result.violations.map(v => v.ruleId);
  expect(rules).toContain('image-alt');
  const imgAlt = result.violations.find((v) => v.ruleId === 'image-alt');
  expect(imgAlt.wcagCriterion).toMatch(/^wcag\d{3,}$/);
}, 30000);

test('POST /scan returns 400 when url missing', async () => {
  const server = http.createServer(app).listen(0);
  const port = server.address().port;
  const res = await fetch(`http://127.0.0.1:${port}/scan`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({}),
  });
  server.close();
  expect(res.status).toBe(400);
});

test('POST /scan rejects a loopback URL (SSRF guard)', async () => {
  const server = http.createServer(app).listen(0);
  const port = server.address().port;
  const res = await fetch(`http://127.0.0.1:${port}/scan`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'http://127.0.0.1/' }),
  });
  server.close();
  expect(res.status).toBe(400);
});

test('POST /scan rejects a non-http scheme (SSRF guard)', async () => {
  const server = http.createServer(app).listen(0);
  const port = server.address().port;
  const res = await fetch(`http://127.0.0.1:${port}/scan`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: 'file:///etc/passwd' }),
  });
  server.close();
  expect(res.status).toBe(400);
});
