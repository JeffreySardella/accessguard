import dns from 'node:dns';

const { lookup } = dns.promises;

// Returns true if an IP address is in a range we must never scan (loopback,
// private, link-local, CGNAT, unspecified). Blocks the core SSRF vectors.
export function isBlockedIp(ip) {
  const mapped = ip.match(/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i);
  if (mapped) ip = mapped[1];

  if (ip.includes('.')) {
    const p = ip.split('.').map(Number);
    if (p.length !== 4 || p.some((n) => Number.isNaN(n) || n < 0 || n > 255)) return true;
    const [a, b, c] = p;
    if (a === 0) return true;                          // 0.0.0.0/8
    if (a === 127) return true;                         // loopback
    if (a === 10) return true;                          // private
    if (a === 172 && b >= 16 && b <= 31) return true;   // private
    if (a === 192 && b === 168) return true;            // private
    if (a === 169 && b === 254) return true;            // link-local
    if (a === 100 && b >= 64 && b <= 127) return true;  // CGNAT
    if (a === 198 && (b === 18 || b === 19)) return true; // 198.18.0.0/15 benchmarking
    if (a === 192 && b === 0 && c === 0) return true;      // 192.0.0.0/24 IETF protocol
    if (a >= 224) return true;                            // 224.0.0.0/4 multicast + 240/4 reserved
    return false;
  }

  const low = ip.toLowerCase();
  if (low === '::1' || low === '::') return true;        // loopback / unspecified
  if (low.startsWith('fe80') || low.startsWith('fc') || low.startsWith('fd')) return true; // link-local / ULA
  if (low.startsWith('ff')) return true;                   // ff00::/8 multicast
  if (low.startsWith('64:ff9b')) return true;             // NAT64
  return false;
}

// True when the operator has explicitly opted into scanning private/internal
// hosts. OFF by default (production-safe). Turn on only inside a trusted
// network where the scan targets are your own internal sites — e.g. a Drupal
// site on a private Docker/LAN address.
function allowPrivateTargets() {
  return /^(1|true|yes)$/i.test(process.env.SCANNER_ALLOW_PRIVATE || '');
}

// Validates a URL against the SSRF policy AND returns the resolved address, so
// the caller can pin that exact IP into the browser. Always requires
// http/https. Rejects hosts that resolve to a private/loopback/link-local
// address unless SCANNER_ALLOW_PRIVATE is set. Returns { hostname, ip }.
//
// Pinning the returned ip (scan.js does this via --host-resolver-rules) closes
// the DNS-rebinding race: the browser connects to the same address we checked,
// not one re-resolved a moment later. scan.js also re-runs assertUrlAllowed on
// every request (navigation, redirects, subresources) via request
// interception, covering redirect- and subresource-to-internal vectors.
export async function resolveAndAssert(rawUrl) {
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch {
    throw new Error('invalid_url');
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
    throw new Error('invalid_url_scheme');
  }
  const addrs = await lookup(parsed.hostname, { all: true });
  if (!allowPrivateTargets()) {
    if (addrs.length === 0 || addrs.some((a) => isBlockedIp(a.address))) {
      throw new Error('blocked_host');
    }
  }
  return { hostname: parsed.hostname, ip: addrs[0]?.address ?? null };
}

// Throws if the URL must not be scanned (see resolveAndAssert).
export async function assertUrlAllowed(rawUrl) {
  await resolveAndAssert(rawUrl);
}
