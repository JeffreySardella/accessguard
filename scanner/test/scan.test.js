import { runScan } from '../src/scan.js';
import path from 'node:path';
import os from 'node:os';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
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
  // The axe engine version is reported so the module can detect scans that
  // span an engine upgrade.
  expect(result.engineVersion).toMatch(/^\d+\.\d+\.\d+/);
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

test('re-validates subresources: a subresource to a blocked host is aborted', async () => {
  // The SSRF guard must run on EVERY request, not just the navigation. Load a
  // local file:// page (navigation bypasses the guard for file:) whose
  // subresource points at a blocked loopback host, with SCANNER_ALLOW_PRIVATE
  // unset — the subresource must never reach the canary server.
  let canaryHit = false;
  const canary = http.createServer((req, res) => {
    canaryHit = true;
    res.end('ok');
  });
  await new Promise((resolve) => canary.listen(0, '127.0.0.1', resolve));
  const canaryPort = canary.address().port;

  const tmpFile = path.join(os.tmpdir(), `accessguard-ssrf-${process.pid}.html`);
  writeFileSync(
    tmpFile,
    `<!doctype html><html lang="en"><head><title>t</title></head><body>`
    + `<img src="http://127.0.0.1:${canaryPort}/pwn" alt="x">`
    + `</body></html>`,
  );

  delete process.env.SCANNER_ALLOW_PRIVATE;
  try {
    await runScan('file://' + tmpFile);
    // Give any in-flight (wrongly-allowed) request a moment to land.
    await new Promise((resolve) => setTimeout(resolve, 300));
    expect(canaryHit).toBe(false);
  } finally {
    unlinkSync(tmpFile);
    canary.close();
  }
}, 30000);

test('caps outbound requests so a page cannot become a DDoS amplifier', async () => {
  // A page that fires thousands of subresource requests must not be allowed
  // to flood a target; the scanner caps requests per scan.
  let served = 0;
  const html = readFileSync(fixturePath, 'utf8').replace(
    '</body>',
    '<script>for (let i = 0; i < 3000; i++) { fetch("/ping/" + i).catch(() => {}); }</script></body>',
  );
  const server = http.createServer((req, res) => {
    if (req.url.startsWith('/ping/')) {
      served++;
      res.statusCode = 204;
      res.end();
      return;
    }
    res.setHeader('content-type', 'text/html');
    res.end(html);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  process.env.SCANNER_ALLOW_PRIVATE = '1';
  process.env.SCANNER_MAX_REQUESTS = '50';
  try {
    await runScan(`http://127.0.0.1:${port}/`);
    // Far fewer than the 3000 the page attempted — the cap held. Allow slack
    // for the cap boundary and the navigation/subresource accounting.
    expect(served).toBeLessThan(200);
  } finally {
    delete process.env.SCANNER_ALLOW_PRIVATE;
    delete process.env.SCANNER_MAX_REQUESTS;
    server.close();
  }
}, 45000);

test('scans a busy page that never reaches network idle', async () => {
  // Long-polling/analytics-style pages never settle to networkidle0; the
  // scanner must still scan them once the DOM has loaded.
  const html = readFileSync(fixturePath, 'utf8')
    .replace('</body>', '<script>setInterval(() => fetch("/ping").catch(() => {}), 300);</script></body>');
  const server = http.createServer((req, res) => {
    if (req.url === '/ping') {
      res.statusCode = 204;
      res.end();
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
    expect(result.violations.map(v => v.ruleId)).toContain('image-alt');
  } finally {
    delete process.env.SCANNER_ALLOW_PRIVATE;
    server.close();
  }
}, 45000);

test('refuses to scan a non-2xx target, with a distinguishable error', async () => {
  const server = http.createServer((req, res) => {
    res.statusCode = 404;
    res.setHeader('content-type', 'text/html');
    res.end('<html lang="en"><head><title>404</title></head><body>not here</body></html>');
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  process.env.SCANNER_ALLOW_PRIVATE = '1';
  try {
    await expect(runScan(`http://127.0.0.1:${port}/gone`)).rejects.toMatchObject({
      code: 'target_http_error',
      status: 404,
    });
  } finally {
    delete process.env.SCANNER_ALLOW_PRIVATE;
    server.close();
  }
}, 45000);

test('refuses to scan a non-HTML target, with a distinguishable error', async () => {
  const server = http.createServer((req, res) => {
    res.setHeader('content-type', 'application/json');
    res.end('{"not": "a web page"}');
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  process.env.SCANNER_ALLOW_PRIVATE = '1';
  try {
    await expect(runScan(`http://127.0.0.1:${port}/api`)).rejects.toMatchObject({
      code: 'target_not_html',
    });
  } finally {
    delete process.env.SCANNER_ALLOW_PRIVATE;
    server.close();
  }
}, 45000);

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
