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
