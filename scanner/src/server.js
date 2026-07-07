import express from 'express';
import { timingSafeEqual, createHash } from 'node:crypto';
import { runScan } from './scan.js';
import { assertUrlAllowed } from './urlGuard.js';
import { renderPdf } from './pdf.js';

export const app = express();
app.use(express.json({ limit: '1mb' }));

app.get('/health', (req, res) => res.json({ ok: true }));

// Optional shared-secret auth: when SCANNER_AUTH_TOKEN is set, /scan requires
// a matching X-Scanner-Token header. Hashing both sides makes the comparison
// timing-safe regardless of length. Unset (the default) keeps the service
// open for setups that rely purely on network isolation.
function isAuthorized(req) {
  const token = process.env.SCANNER_AUTH_TOKEN || '';
  if (!token) return true;
  const presented = String(req.headers['x-scanner-token'] || '');
  const a = createHash('sha256').update(presented).digest();
  const b = createHash('sha256').update(token).digest();
  return timingSafeEqual(a, b);
}

app.post('/scan', async (req, res) => {
  if (!isAuthorized(req)) {
    return res.status(401).json({ error: 'unauthorized' });
  }
  const { url } = req.body || {};
  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'Missing required "url" string.' });
  }
  try {
    await assertUrlAllowed(url);
  } catch {
    return res.status(400).json({ error: 'url_not_allowed' });
  }
  try {
    const result = await runScan(url);
    res.json(result);
  } catch (err) {
    console.error('[accessguard-scanner] scan failed:', err);
    res.status(500).json({ error: 'scan_failed' });
  }
});

app.post('/pdf', express.json({ limit: '5mb' }), async (req, res) => {
  if (!isAuthorized(req)) {
    return res.status(401).json({ error: 'unauthorized' });
  }
  const { html } = req.body || {};
  if (!html || typeof html !== 'string') {
    return res.status(400).json({ error: 'invalid_html' });
  }
  try {
    const pdf = await renderPdf(html);
    res.setHeader('Content-Type', 'application/pdf');
    res.send(pdf);
  } catch (err) {
    console.error('[accessguard-scanner] pdf failed:', err);
    res.status(500).json({ error: 'pdf_failed' });
  }
});

const PORT = process.env.PORT || 3000;
if (process.env.NODE_ENV !== 'test') {
  app.listen(PORT, () => console.log(`accessguard-scanner listening on ${PORT}`));
}
