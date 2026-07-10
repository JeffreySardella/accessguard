import http from 'node:http';
import https from 'node:https';
import zlib from 'node:zlib';

const MAX_BODY_BYTES = 10 * 1024 * 1024;
// The wire cap above counts *compressed* bytes; a small gzip/brotli bomb can
// expand to gigabytes, so decompression needs its own output ceiling.
const MAX_DECODED_BYTES = 50 * 1024 * 1024;
// Idle-socket timeout: fires when a connection goes quiet.
const REQUEST_TIMEOUT_MS = 20000;
// Total deadline: caps a server that dribbles bytes just often enough to keep
// resetting the idle timeout, which would otherwise hold a scan open for far
// longer than REQUEST_TIMEOUT_MS.
const REQUEST_DEADLINE_MS = 30000;

// Hop-by-hop headers that must not be replayed into the browser's response.
const STRIP_RESPONSE_HEADERS = new Set([
  'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
  'te', 'trailer', 'transfer-encoding', 'upgrade', 'content-length',
]);

function decodeBody(body, encoding) {
  switch ((encoding || '').toLowerCase()) {
    case '':
    case 'identity':
      return body;
    case 'gzip':
      return zlib.gunzipSync(body, { maxOutputLength: MAX_DECODED_BYTES });
    case 'deflate':
      return zlib.inflateSync(body, { maxOutputLength: MAX_DECODED_BYTES });
    case 'br':
      return zlib.brotliDecompressSync(body, { maxOutputLength: MAX_DECODED_BYTES });
    default:
      throw new Error(`unsupported_content_encoding: ${encoding}`);
  }
}

// Fetches a URL over http(s) while connecting to a caller-supplied, already
// validated IP address instead of re-resolving the hostname. This is what
// makes the SSRF guard rebinding-proof for every request: DNS is consulted
// exactly once (by the guard), and the connection provably goes to that
// address. For https, `servername` carries the original hostname so SNI and
// certificate identity checks still validate against the real host.
//
// Redirects are NOT followed: 3xx responses are returned as-is so the browser
// issues the next hop as a fresh request that gets re-validated and re-pinned.
export function fetchPinned(rawUrl, ip, { method = 'GET', headers = {}, body = null } = {}) {
  const url = new URL(rawUrl);
  const isHttps = url.protocol === 'https:';
  const lib = isHttps ? https : http;

  const requestHeaders = { ...headers };
  // The Host header must name the original host; the socket goes to the IP.
  requestHeaders.host = url.host;
  // Only encodings we can decode locally.
  requestHeaders['accept-encoding'] = 'gzip, deflate, br';
  // Never forward the caller's content-length verbatim: the body we actually
  // replay may differ (or be absent — Puppeteer surfaces no postData for some
  // binary/multipart requests), and a stale length makes the upstream hang
  // waiting for bytes that never arrive. Set it from the real body instead.
  for (const name of Object.keys(requestHeaders)) {
    if (name.toLowerCase() === 'content-length') {
      delete requestHeaders[name];
    }
  }
  if (body != null && body !== '') {
    requestHeaders['content-length'] = Buffer.byteLength(body);
  }

  const options = {
    host: ip,
    port: url.port || (isHttps ? 443 : 80),
    path: url.pathname + url.search,
    method,
    headers: requestHeaders,
  };
  if (isHttps) {
    options.servername = url.hostname;
  }

  return new Promise((resolve, reject) => {
    let deadline;
    const done = (fn, arg) => {
      clearTimeout(deadline);
      fn(arg);
    };
    const req = lib.request(options, (res) => {
      const chunks = [];
      let size = 0;
      res.on('data', (chunk) => {
        size += chunk.length;
        if (size > MAX_BODY_BYTES) {
          req.destroy(new Error('body_too_large'));
          return;
        }
        chunks.push(chunk);
      });
      res.on('end', () => {
        try {
          const responseHeaders = {};
          for (const [name, value] of Object.entries(res.headers)) {
            if (!STRIP_RESPONSE_HEADERS.has(name)) {
              responseHeaders[name] = Array.isArray(value) ? value.join(', ') : value;
            }
          }
          const raw = Buffer.concat(chunks);
          const decoded = decodeBody(raw, responseHeaders['content-encoding']);
          delete responseHeaders['content-encoding'];
          done(resolve, { status: res.statusCode, headers: responseHeaders, body: decoded });
        } catch (err) {
          done(reject, err);
        }
      });
      res.on('error', (err) => done(reject, err));
    });
    deadline = setTimeout(() => req.destroy(new Error('deadline_exceeded')), REQUEST_DEADLINE_MS);
    req.setTimeout(REQUEST_TIMEOUT_MS, () => req.destroy(new Error('timeout')));
    req.on('error', (err) => done(reject, err));
    if (body) {
      req.write(body);
    }
    req.end();
  });
}
