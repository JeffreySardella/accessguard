import { app } from '../src/server.js';
import http from 'node:http';

function listen() {
  const server = http.createServer(app).listen(0);
  return { server, port: server.address().port };
}

test('POST /pdf returns PDF bytes for valid HTML', async () => {
  const { server, port } = listen();
  try {
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html: '<!doctype html><html><body><h1>Audit</h1></body></html>' }),
    });
    expect(res.status).toBe(200);
    expect(res.headers.get('content-type')).toMatch(/application\/pdf/);
    const buf = Buffer.from(await res.arrayBuffer());
    expect(buf.subarray(0, 4).toString('latin1')).toBe('%PDF');
  } finally {
    server.close();
  }
}, 30000);

test('POST /pdf returns 400 on missing html', async () => {
  const { server, port } = listen();
  try {
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({}),
    });
    expect(res.status).toBe(400);
  } finally {
    server.close();
  }
});

test('POST /pdf requires the token when SCANNER_AUTH_TOKEN is set', async () => {
  process.env.SCANNER_AUTH_TOKEN = 'sekret';
  const { server, port } = listen();
  try {
    const noToken = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html: '<h1>x</h1>' }),
    });
    expect(noToken.status).toBe(401);
  } finally {
    delete process.env.SCANNER_AUTH_TOKEN;
    server.close();
  }
});

test('POST /pdf renders even when HTML references an unreachable subresource', async () => {
  const { server, port } = listen();
  try {
    const html = '<!doctype html><html><head>'
      + '<img src="http://127.0.0.1:1/x.png">'
      + '</head><body><h1>Audit</h1></body></html>';
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html }),
    });
    expect(res.status).toBe(200);
    const buf = Buffer.from(await res.arrayBuffer());
    expect(buf.subarray(0, 4).toString('latin1')).toBe('%PDF');
  } finally {
    server.close();
  }
}, 30000);

test('POST /pdf does not let a remote <iframe> reach the network', async () => {
  let hit = false;
  const canary = http.createServer((req, res) => { hit = true; res.end('x'); });
  await new Promise((r) => canary.listen(0, '127.0.0.1', r));
  const canaryPort = canary.address().port;
  const { server, port } = listen();
  try {
    const html = '<!doctype html><html><body><h1>Audit</h1>'
      + `<iframe src="http://127.0.0.1:${canaryPort}/secret"></iframe></body></html>`;
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html }),
    });
    expect(res.status).toBe(200);
    const buf = Buffer.from(await res.arrayBuffer());
    expect(buf.subarray(0, 4).toString('latin1')).toBe('%PDF');
    // The iframe's request must have been aborted by the interception layer;
    // if it had reached the network, the canary would have recorded a hit.
    expect(hit).toBe(false);
  } finally {
    server.close();
    canary.close();
  }
}, 30000);

test('POST /pdf accepts a body larger than the 1mb /scan limit', async () => {
  const { server, port } = listen();
  try {
    const big = '<p>' + 'a'.repeat(1500000) + '</p>';
    const html = `<!doctype html><html><body><h1>Audit</h1>${big}</body></html>`;
    const res = await fetch(`http://127.0.0.1:${port}/pdf`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ html }),
    });
    expect(res.status).toBe(200);
  } finally {
    server.close();
  }
}, 30000);
