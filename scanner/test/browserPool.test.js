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

test('the browser closes after the idle timeout and relaunches on demand', async () => {
  process.env.SCANNER_BROWSER_IDLE_MS = '100';
  const pid1 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  await new Promise((r) => setTimeout(r, 500));
  const pid2 = await withBrowserContext(async (ctx) => ctx.browser().process().pid);
  expect(pid2).not.toBe(pid1);
}, 30000);
