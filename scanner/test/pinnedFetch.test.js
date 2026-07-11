import { fetchPinned } from '../src/pinnedFetch.js';
import http from 'node:http';

// The hostname below is never resolvable (.invalid is reserved), so the
// request can only succeed if fetchPinned really connects to the pinned IP
// instead of doing its own DNS lookup.
test('connects to the pinned IP, not DNS', async () => {
  const server = http.createServer((req, res) => {
    res.end(`hello ${req.headers.host}`);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  const res = await fetchPinned(`http://accessguard-pin-test.invalid:${port}/x`, '127.0.0.1');
  server.close();

  expect(res.status).toBe(200);
  // The Host header carries the original hostname, not the IP.
  expect(res.body.toString()).toBe(`hello accessguard-pin-test.invalid:${port}`);
});

test('forwards a POST body with a corrected content-length', async () => {
  let received = '';
  let receivedLength;
  const server = http.createServer((req, res) => {
    receivedLength = req.headers['content-length'];
    req.on('data', (c) => { received += c; });
    req.on('end', () => { res.end('ok'); });
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  const res = await fetchPinned(`http://accessguard-pin-test.invalid:${port}/p`, '127.0.0.1', {
    method: 'POST',
    // A deliberately wrong inbound content-length must not be forwarded.
    headers: { 'content-length': '9999' },
    body: 'hello body',
  });
  server.close();

  expect(res.status).toBe(200);
  expect(received).toBe('hello body');
  expect(receivedLength).toBe(String(Buffer.byteLength('hello body')));
});

test('returns redirects without following them', async () => {
  const server = http.createServer((req, res) => {
    res.statusCode = 302;
    res.setHeader('location', 'http://127.0.0.1/elsewhere');
    res.end();
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  const res = await fetchPinned(`http://accessguard-pin-test.invalid:${port}/r`, '127.0.0.1');
  server.close();

  // The redirect goes back to the browser so the next hop is re-validated
  // and re-pinned like any other request.
  expect(res.status).toBe(302);
  expect(res.headers.location).toBe('http://127.0.0.1/elsewhere');
});

test('drops Set-Cookie rather than corrupting multiple cookies', async () => {
  // Two cookies, one carrying a comma inside an Expires date. A naive comma
  // join would merge them into one malformed header; we drop them instead.
  const server = http.createServer((req, res) => {
    res.setHeader('set-cookie', [
      'a=1; Path=/',
      'b=2; Expires=Wed, 09 Jun 2027 10:18:14 GMT',
    ]);
    res.end('ok');
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  const res = await fetchPinned(`http://accessguard-pin-test.invalid:${port}/c`, '127.0.0.1');
  server.close();

  expect(res.status).toBe(200);
  expect(res.headers['set-cookie']).toBeUndefined();
});

test('decompresses a gzip response so the browser gets plain bytes', async () => {
  const { gzipSync } = await import('node:zlib');
  const server = http.createServer((req, res) => {
    res.setHeader('content-encoding', 'gzip');
    res.end(gzipSync('compressed payload'));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  const res = await fetchPinned(`http://accessguard-pin-test.invalid:${port}/z`, '127.0.0.1');
  server.close();

  expect(res.body.toString()).toBe('compressed payload');
  expect(res.headers['content-encoding']).toBeUndefined();
});

test('rejects a decompression bomb even when the compressed body is tiny', async () => {
  const { gzipSync } = await import('node:zlib');
  // 60 MB of zeros gzips to ~60 KB — far under the 10 MB wire cap, far over
  // the decoded-output ceiling. Without that ceiling this expands in memory
  // and can OOM the service.
  const bomb = gzipSync(Buffer.alloc(60 * 1024 * 1024));
  expect(bomb.length).toBeLessThan(10 * 1024 * 1024);
  const server = http.createServer((req, res) => {
    res.setHeader('content-encoding', 'gzip');
    res.end(bomb);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  try {
    await expect(fetchPinned(`http://accessguard-pin-test.invalid:${port}/bomb`, '127.0.0.1')).rejects.toThrow();
  } finally {
    server.close();
  }
});

test('caps a slow-drip response with the total deadline', async () => {
  // Send a byte every 2s and never end: this keeps resetting the 20s idle
  // socket timeout, so only the total deadline can stop it. The deadline is
  // 30s; assert it rejects well before an unbounded stream would (the test
  // timeout is 40s).
  let timer;
  const server = http.createServer((req, res) => {
    res.writeHead(200, { 'content-type': 'text/plain', 'transfer-encoding': 'chunked' });
    timer = setInterval(() => res.write('.'), 2000);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;

  try {
    await expect(fetchPinned(`http://accessguard-pin-test.invalid:${port}/drip`, '127.0.0.1')).rejects.toThrow('deadline_exceeded');
  } finally {
    clearInterval(timer);
    server.close();
  }
}, 40000);
