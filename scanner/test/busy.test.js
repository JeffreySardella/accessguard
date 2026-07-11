import { app } from '../src/server.js';
import http from 'node:http';

// The service caps concurrent browser work (each /scan and /pdf launches a
// full Chromium) and sheds excess requests with 503 scanner_busy so a burst
// cannot OOM the container. Saturate a max-1 service with a slow scan and
// verify the overflow request is rejected immediately, then that capacity
// frees up afterward.
test('sheds requests over the concurrency cap with 503 scanner_busy', async () => {
  process.env.SCANNER_ALLOW_PRIVATE = '1';
  process.env.SCANNER_MAX_CONCURRENCY = '1';

  // A target page slow enough (2s) that the second request reliably arrives
  // while the first still holds the only browser slot.
  const target = http.createServer((req, res) => {
    setTimeout(() => {
      res.setHeader('content-type', 'text/html');
      res.end('<!doctype html><html lang="en"><head><title>slow</title></head><body><p>hi</p></body></html>');
    }, 2000);
  });
  await new Promise((resolve) => target.listen(0, '127.0.0.1', resolve));
  const targetPort = target.address().port;

  const server = http.createServer(app);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  const scan = () => fetch(`http://127.0.0.1:${port}/scan`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ url: `http://127.0.0.1:${targetPort}/` }),
  });

  try {
    const first = scan();
    // Give the first request time to claim the slot before firing the second.
    await new Promise((resolve) => setTimeout(resolve, 500));
    const second = await scan();
    expect(second.status).toBe(503);
    expect((await second.json()).error).toBe('scanner_busy');

    // The slot is released once the first scan completes.
    expect((await first).status).toBe(200);
    const third = await scan();
    expect(third.status).toBe(200);
  } finally {
    delete process.env.SCANNER_ALLOW_PRIVATE;
    delete process.env.SCANNER_MAX_CONCURRENCY;
    server.close();
    target.close();
  }
}, 60000);
