import http from 'node:http';
import https from 'node:https';
import zlib from 'node:zlib';

const MAX_BODY_BYTES = 10 * 1024 * 1024;
const REQUEST_TIMEOUT_MS = 20000;

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
      return zlib.gunzipSync(body);
    case 'deflate':
      return zlib.inflateSync(body);
    case 'br':
      return zlib.brotliDecompressSync(body);
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
          resolve({ status: res.statusCode, headers: responseHeaders, body: decoded });
        } catch (err) {
          reject(err);
        }
      });
      res.on('error', reject);
    });
    req.setTimeout(REQUEST_TIMEOUT_MS, () => req.destroy(new Error('timeout')));
    req.on('error', reject);
    if (body) {
      req.write(body);
    }
    req.end();
  });
}
