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
    const [a, b] = p;
    if (a === 0) return true;                          // 0.0.0.0/8
    if (a === 127) return true;                         // loopback
    if (a === 10) return true;                          // private
    if (a === 172 && b >= 16 && b <= 31) return true;   // private
    if (a === 192 && b === 168) return true;            // private
    if (a === 169 && b === 254) return true;            // link-local
    if (a === 100 && b >= 64 && b <= 127) return true;  // CGNAT
    return false;
  }

  const low = ip.toLowerCase();
  if (low === '::1' || low === '::') return true;        // loopback / unspecified
  if (low.startsWith('fe80') || low.startsWith('fc') || low.startsWith('fd')) return true; // link-local / ULA
  return false;
}

// Throws if the URL must not be scanned. Allows only http/https, and rejects
// hosts that resolve to any blocked address.
// KNOWN LIMITATION: does not pin the resolved IP into Puppeteer, so a DNS
// rebinding attack between this check and page load is not fully closed. A
// follow-up should pass the validated IP to runScan via --host-resolver-rules.
export async function assertUrlAllowed(rawUrl) {
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
  if (addrs.length === 0 || addrs.some((a) => isBlockedIp(a.address))) {
    throw new Error('blocked_host');
  }
}
