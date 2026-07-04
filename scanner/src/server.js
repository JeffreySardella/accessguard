import express from 'express';
import { runScan } from './scan.js';
import { assertUrlAllowed } from './urlGuard.js';

export const app = express();
app.use(express.json({ limit: '1mb' }));

app.get('/health', (req, res) => res.json({ ok: true }));

app.post('/scan', async (req, res) => {
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

const PORT = process.env.PORT || 3000;
if (process.env.NODE_ENV !== 'test') {
  app.listen(PORT, () => console.log(`accessguard-scanner listening on ${PORT}`));
}
