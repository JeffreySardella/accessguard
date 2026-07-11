import { isBlockedIp, assertUrlAllowed, resolveAndAssert } from '../src/urlGuard.js';

test('resolveAndAssert returns hostname and ip for an allowed host', async () => {
  const r = await resolveAndAssert('http://8.8.8.8/');
  expect(r.hostname).toBe('8.8.8.8');
  expect(r.ip).toBe('8.8.8.8');
});

test('resolveAndAssert throws for a blocked host', async () => {
  await expect(resolveAndAssert('http://127.0.0.1/')).rejects.toThrow('blocked_host');
});

test('blocks loopback, private, link-local, metadata addresses', () => {
  for (const ip of ['127.0.0.1', '10.0.0.5', '172.16.0.1', '192.168.1.1', '169.254.169.254', '0.0.0.0', '::1', '198.18.0.1', '240.0.0.1']) {
    expect(isBlockedIp(ip)).toBe(true);
  }
});

test('allows normal public addresses', () => {
  for (const ip of ['8.8.8.8', '93.184.216.34', '1.1.1.1', '2606:4700:4700::1111']) {
    expect(isBlockedIp(ip)).toBe(false);
  }
});

test('blocks IPv4-mapped IPv6 in both dotted and hex spellings', () => {
  for (const ip of [
    '::ffff:127.0.0.1',
    '::ffff:169.254.169.254',
    '::ffff:7f00:1',      // 127.0.0.1 in hex
    '::ffff:a9fe:a9fe',   // 169.254.169.254 in hex
    '::FFFF:A9FE:A9FE',   // case-insensitive
  ]) {
    expect(isBlockedIp(ip)).toBe(true);
  }
});

test('blocks IPv6 loopback/ULA/link-local literals', () => {
  for (const ip of ['::1', 'fe80::1', 'fc00::1', 'fd12:3456::1']) {
    expect(isBlockedIp(ip)).toBe(true);
  }
});

test('resolveAndAssert accepts a public IPv6 literal without DNS', async () => {
  const r = await resolveAndAssert('http://[2606:4700:4700::1111]/');
  expect(r.hostname).toBe('2606:4700:4700::1111');
  expect(r.ip).toBe('2606:4700:4700::1111');
});

test('resolveAndAssert blocks an IPv6 loopback literal', async () => {
  await expect(resolveAndAssert('http://[::1]/')).rejects.toThrow('blocked_host');
});

test('assertUrlAllowed blocks a private host by default', async () => {
  delete process.env.SCANNER_ALLOW_PRIVATE;
  await expect(assertUrlAllowed('http://127.0.0.1/')).rejects.toThrow('blocked_host');
});

test('assertUrlAllowed permits a private host when SCANNER_ALLOW_PRIVATE is set', async () => {
  process.env.SCANNER_ALLOW_PRIVATE = '1';
  await expect(assertUrlAllowed('http://127.0.0.1/')).resolves.toBeUndefined();
  delete process.env.SCANNER_ALLOW_PRIVATE;
});

test('assertUrlAllowed still rejects a non-http scheme even with the flag', async () => {
  process.env.SCANNER_ALLOW_PRIVATE = '1';
  await expect(assertUrlAllowed('file:///etc/passwd')).rejects.toThrow('invalid_url_scheme');
  delete process.env.SCANNER_ALLOW_PRIVATE;
});
