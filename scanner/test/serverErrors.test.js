import { app } from '../src/server.js';
import http from 'node:http';

// The Drupal client parses every scanner response as JSON, so body-parser
// failures must return JSON too (not Express's default HTML error page), and
// the /pdf body limit must hold for the trailing-slash spelling.

function listen() {
  return new Promise((resolve) => {
    const server = http.createServer(app).listen(0, '127.0.0.1', () => {
      resolve({ server, port: server.address().port });
    });
  });
}

test('malformed JSON returns a JSON 400, not an HTML error page', async () => {
  const { server, port } = await listen();
  try {
    const res = await fetch(`http://127.0.0.1:${port}/scan`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: '{ not valid json',
    });
    expect(res.status).toBe(400);
    expect(res.headers.get('content-type')).toMatch(/application\/json/);
    expect((await res.json()).error).toBe('invalid_json');
  } finally {
    server.close();
  }
});

test('an over-limit /scan body returns a JSON 413', async () => {
  const { server, port } = await listen();
  try {
    // >1mb payload against the /scan parser's 1mb ceiling.
    const big = JSON.stringify({ url: 'x'.repeat(1024 * 1024 + 100) });
    const res = await fetch(`http://127.0.0.1:${port}/scan`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: big,
    });
    expect(res.status).toBe(413);
    expect(res.headers.get('content-type')).toMatch(/application\/json/);
    expect((await res.json()).error).toBe('payload_too_large');
  } finally {
    server.close();
  }
});

test('POST /pdf/ (trailing slash) still gets the 5mb parser', async () => {
  const { server, port } = await listen();
  try {
    // ~2mb body: over the 1mb /scan limit, under the 5mb /pdf limit. `html` is
    // a non-string so the handler rejects it with 400 invalid_html *after*
    // parsing — proving the 5mb parser accepted the oversized body without
    // launching a real render. If the trailing-slash path had fallen through
    // to the 1mb parser, this would be 413 instead.
    const body = JSON.stringify({ html: 123, filler: 'a'.repeat(2 * 1024 * 1024) });
    const res = await fetch(`http://127.0.0.1:${port}/pdf/`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body,
    });
    expect(res.status).toBe(400);
    expect((await res.json()).error).toBe('invalid_html');
  } finally {
    server.close();
  }
});
