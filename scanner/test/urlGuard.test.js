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
  for (const ip of ['8.8.8.8', '93.184.216.34', '1.1.1.1']) {
    expect(isBlockedIp(ip)).toBe(false);
  }
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
