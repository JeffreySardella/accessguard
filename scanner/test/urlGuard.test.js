import { isBlockedIp } from '../src/urlGuard.js';

test('blocks loopback, private, link-local, metadata addresses', () => {
  for (const ip of ['127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '::1']) {
    expect(isBlockedIp(ip)).toBe(true);
  }
});

test('allows normal public addresses', () => {
  for (const ip of ['8.8.8.8', '93.184.216.34', '1.1.1.1']) {
    expect(isBlockedIp(ip)).toBe(false);
  }
});
