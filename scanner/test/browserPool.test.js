import { withBrowserContext, closeSharedBrowser } from '../src/browserPool.js';

afterEach(async () => {
  await closeSharedBrowser();
  delete process.env.SCANNER_BROWSER_IDLE_MS;
});

test('sequential requests reuse one Chromium process', async () => {
  const pid1 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  const pid2 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid2).toBe(pid1);
}, 30000);

test('the context is closed after the callback, even on error', async () => {
  let saved;
  await expect(withBrowserContext(async (ctx) => {
    saved = ctx;
    throw new Error('handler exploded');
  })).rejects.toThrow('handler exploded');
  // A closed context cannot create pages.
  await expect(saved.newPage()).rejects.toThrow();
}, 30000);

test('a crashed browser is replaced on the next request', async () => {
  const proc = await withBrowserContext(async (ctx) => ctx.browser().process());
  proc.kill('SIGKILL');
  // Let the disconnect event propagate.
  await new Promise((r) => setTimeout(r, 300));
  const pid = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid).not.toBe(proc.pid);
}, 30000);

test('a transient context failure on a live browser propagates instead of launching a second browser', async () => {
  const browser = await withBrowserContext(async (ctx) => ctx.browser());
  const original = browser.createBrowserContext;
  browser.createBrowserContext = async () => {
    throw new Error('transient context failure');
  };
  try {
    await expect(withBrowserContext(async () => {})).rejects.toThrow('transient context failure');
  } finally {
    browser.createBrowserContext = original;
  }
  // The live browser must still be the pool's browser — not orphaned
  // behind a freshly launched replacement.
  const pid = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  const originalPid = browser.process().pid;
  if (browser.connected && pid !== originalPid) await browser.close();
  expect(pid).toBe(originalPid);
}, 30000);

test('the shared browser disables Chromium HTTPS-First upgrades, on every launch', async () => {
  // Chromium (Puppeteer 24+) silently upgrades hostname-based http://
  // navigations to https://. The scanner must fetch the URL it was given:
  // the upgraded request gets fulfilled Node-side against the target's TLS
  // endpoint, so an http-only or dev-cert site fails as ERR_BLOCKED_BY_CLIENT
  // instead of being scanned — and the abort also suppresses Chromium's own
  // fallback to http. Exercising the upgrade needs a real public hostname
  // (IP-literal and localhost targets are exempt), which a hermetic test
  // cannot use, so this pins the disabling launch flag directly.
  // Puppeteer merges custom --disable-features values into its own default
  // flag, so parse every instance rather than matching one literal argument.
  const getDisabled = () => withBrowserContext(async (ctx) =>
    ctx.browser().process().spawnargs
      .filter((a) => a.startsWith('--disable-features='))
      .flatMap((a) => a.slice('--disable-features='.length).split(',')));
  const wanted = ['HttpsUpgrades', 'HttpsFirstBalancedModeAutoEnable'];
  expect(await getDisabled()).toEqual(expect.arrayContaining(wanted));
  // puppeteer.launch() MUTATES the caller's args array while merging (the
  // --disable-features entry is removed from it), so a relaunch — crash
  // recovery or idle teardown — must prove the flag survives, not just the
  // first launch of the process.
  await closeSharedBrowser();
  expect(await getDisabled()).toEqual(expect.arrayContaining(wanted));
}, 30000);

test('the browser closes after the idle timeout and relaunches on demand', async () => {
  process.env.SCANNER_BROWSER_IDLE_MS = '100';
  const pid1 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  await new Promise((r) => setTimeout(r, 500));
  const pid2 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid2).not.toBe(pid1);
}, 30000);
