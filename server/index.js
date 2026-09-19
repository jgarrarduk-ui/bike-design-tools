'use strict';

require('dotenv').config();

const express = require('express');
const cors    = require('cors');
const path    = require('path');

const designsRouter   = require('./routes/designs');
const webhooksRouter  = require('./routes/webhooks');
const downloadRouter  = require('./routes/download');
const reviewRouter    = require('./routes/review');
const adminRouter     = require('./routes/admin');

const app  = express();
const PORT = process.env.PORT || 3001;

// ── CORS ─────────────────────────────────────────────────────────────────────
const allowedOrigins = [
  process.env.FRONTEND_URL,
  'http://localhost:3000',
  'http://localhost:5173',
  'http://127.0.0.1:5173',
].filter(Boolean);

app.use(cors({
  origin: (origin, cb) => {
    // Allow requests with no origin (same-origin, curl, Postman)
    if (!origin) return cb(null, true);
    if (allowedOrigins.some(o => origin.startsWith(o))) return cb(null, true);
    cb(new Error(`CORS: origin ${origin} not allowed`));
  },
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'X-WC-Webhook-Signature', 'Authorization'],
}));

// ── Body parsing ──────────────────────────────────────────────────────────────
// Webhooks route uses express.raw() inline (to preserve raw body for HMAC)
app.use('/api/webhooks', webhooksRouter);

// All other routes use JSON
app.use(express.json({ limit: '10mb' })); // 10 MB for PDF base64 payloads

// ── API routes ────────────────────────────────────────────────────────────────
app.use('/api/designs',  designsRouter);
app.use('/api/download', downloadRouter);
app.use('/api/review',   reviewRouter);
app.use('/api/admin',    adminRouter);

// ── Health check ──────────────────────────────────────────────────────────────
app.get('/api/health', (_req, res) => {
  res.json({
    status:      'ok',
    woocommerce: !!(process.env.WC_URL && process.env.WC_CONSUMER_KEY),
    email:       !!(process.env.SMTP_HOST && process.env.SMTP_USER),
    ts:          new Date().toISOString(),
  });
});

// ── Admin panel ───────────────────────────────────────────────────────────────
app.get('/admin', (_req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'admin.html'));
});

// ── Serve static frontend ─────────────────────────────────────────────────────
// In production, serve the index.html from the project root.
// In development, you can run a separate static server (e.g. `npx serve ..`).
const frontendDir = path.resolve(__dirname, '..');
app.use(express.static(frontendDir, { index: 'index.html' }));

// SPA fallback — send index.html for any unmatched GET
app.get('*', (_req, res) => {
  res.sendFile(path.join(frontendDir, 'index.html'));
});

// ── Error handler ─────────────────────────────────────────────────────────────
app.use((err, _req, res, _next) => {
  console.error('[server] Unhandled error:', err.message);
  res.status(500).json({ error: 'Internal server error.' });
});

// ── Start ─────────────────────────────────────────────────────────────────────
app.listen(PORT, () => {
  console.log(`Creature Cycles backend listening on port ${PORT}`);
  console.log(`  WooCommerce : ${process.env.WC_URL   || '(not configured)'}`);
  console.log(`  Email       : ${process.env.SMTP_HOST || '(not configured)'}`);
  console.log(`  DB          : ${process.env.DB_PATH   || './data/designs.db'}`);
});
