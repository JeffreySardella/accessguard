import express from 'express';
import { runScan } from './scan.js';

export const app = express();
app.use(express.json({ limit: '1mb' }));

app.get('/health', (req, res) => res.json({ ok: true }));

app.post('/scan', async (req, res) => {
  const { url } = req.body || {};
  if (!url || typeof url !== 'string') {
    return res.status(400).json({ error: 'Missing required "url" string.' });
  }
  try {
    const result = await runScan(url);
    res.json(result);
  } catch (err) {
    res.status(500).json({ error: String(err && err.message ? err.message : err) });
  }
});

const PORT = process.env.PORT || 3000;
if (process.env.NODE_ENV !== 'test') {
  app.listen(PORT, () => console.log(`accessguard-scanner listening on ${PORT}`));
}
