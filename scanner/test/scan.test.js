import { runScan } from '../src/scan.js';
import path from 'node:path';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { app } from '../src/server.js';
import http from 'node:http';

const dir = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(dir, 'fixtures', 'missing-alt.html');
const fixture = 'file://' + fixturePath;

test('flags an image with no alt text', async () => {
  const result = await runScan(fixture);
  const rules = result.violations.map(v => v.ruleId);
  expect(rules).toContain('image-alt');
  const imgAlt = result.violations.find((v) => v.ruleId === 'image-alt');
  expect(imgAlt.wcagCriterion).toMatch(/^wcag\d{3,}$/);
}, 30000);

test('scans an http target end-to-end, fetching subresources through the pinned path', async () => {
  // Serve the fixture over real http with a stylesheet subresource, so the
  // whole interception/fulfillment pipeline is exercised, not just file://.
  let cssRequested = false;
  const html = readFileSync(fixturePath, 'utf8')
    .replace('</head>', '<link rel="stylesheet" href="/style.css"></head>');
  const server = http.createServer((req, res) => {
    if (req.url === '/style.css') {
      cssRequested = true;
      res.setHeader('content-type', 'text/css');
      res.end('body { color: #000; }');
      return;
    }
    res.setHeader('content-type', 'text/html');
    res.end(html);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  process.env.SCANNER_ALLOW_PRIVATE = '1';
  try {
    const result = await runScan(`http://127.0.0.1:${port}/`);
    const rules = result.violations.map(v => v.ruleId);
    expect(rules).toContain('image-alt');
    expect(cssRequested).toBe(true);
  } finally {
    delete process.env.SCANNER_ALLOW_PRIVATE;
    server.close();
  }
}, 30000);

test('POST /scan requires the token when SCANNER_AUTH_TOKEN is set', async () => {
  process.env.SCANNER_AUTH_TOKEN = 'sekret';
  const server = http.createServer(app).listen(0);
  const port = server.address().port;
  try {
    const noToken = await fetch(`http://127.0.0.1:${port}/scan`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({}),
    });
    expect(noToken.status).toBe(401);

    const wrongToken = await fetch(`http://127.0.0.1:${port}/scan`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-scanner-token': 'nope' },
      body: JSON.stringify({}),
    });
    expect(wrongToken.status).toBe(401);

    // A correct token passes auth and reaches normal validation (400: no url).
    const goodToken = await fetch(`http://127.0.0.1:${port}/scan`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-scanner-token': 'sekret' },
      body: JSON.stringify({}),
    });
    expect(goodToken.status).toBe(400);
  } finally {
    delete process.env.SCANNER_AUTH_TOKEN;
    server.close();
  }
});

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
