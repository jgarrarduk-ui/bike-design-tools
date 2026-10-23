'use strict';

/**
 * Admin routes — password-protected endpoints for managing orders.
 *
 * Authentication: Bearer token in Authorization header.
 *   Authorization: Bearer <ADMIN_PASSWORD>
 *
 * Endpoints:
 *   GET  /api/admin/orders                    — list all orders
 *   POST /api/admin/orders/:id/send-review    — upload reviewed PDF and send review email
 *   POST /api/admin/cleanup-unpaid            — expire resume tokens past TTL and cancel scrapped pending Woo orders
 *   POST /api/admin/retention-purge           — anonymise designs past the retention window
 */

const crypto   = require('crypto');
const express  = require('express');
const multer   = require('multer');
const { v4: uuidv4 } = require('uuid');
const db       = require('../db');
const email    = require('../services/email');
const cleanup  = require('../services/unpaid-cleanup');
const retention = require('../services/retention-purge');
const { maskEmail } = require('../services/redact');

const router = express.Router();

// Null when ADMIN_PASSWORD is unset, empty, or whitespace-only.
// A value that contains any other character is kept exactly.
function configuredAdminPassword() {
  const raw = process.env.ADMIN_PASSWORD;
  if (typeof raw !== 'string' || raw.trim() === '') return null;
  return raw;
}

function passwordsMatch(provided, expected) {
  const a = crypto.createHash('sha256').update(String(provided), 'utf8').digest();
  const b = crypto.createHash('sha256').update(String(expected), 'utf8').digest();
  return crypto.timingSafeEqual(a, b);
}

function bearerToken(req) {
  const header = req.headers.authorization || '';
  if (typeof header !== 'string' || !header.startsWith('Bearer ')) return '';
  return header.slice('Bearer '.length);
}

// One line at process startup. The value is never written.
function warnIfAdminUnconfigured() {
  if (configuredAdminPassword() !== null) return;
  console.warn('[admin] ADMIN_PASSWORD is unset — admin routes return 503');
}

// ── Auth middleware ───────────────────────────────────────────────────────────
// Every route on this router (orders, send-review, cleanup-unpaid,
// retention-purge) passes through here. GET /admin is only the login page.
function requireAdminAuth(req, res, next) {
  const password = configuredAdminPassword();
  if (password === null) {
    return res.status(503).json({ error: 'admin_not_configured' });
  }

  if (!passwordsMatch(bearerToken(req), password)) {
    return res.status(401).json({ error: 'Unauthorised' });
  }

  next();
}

// ── Multer — PDF upload stored in memory ─────────────────────────────────────
const upload = multer({
  storage: multer.memoryStorage(),
  limits:  { fileSize: 20 * 1024 * 1024 }, // 20 MB max
  fileFilter(_req, file, cb) {
    if (file.mimetype === 'application/pdf') return cb(null, true);
    cb(new Error('Only PDF files are accepted'));
  },
});

router.use(requireAdminAuth);

// ── GET /api/admin/orders ─────────────────────────────────────────────────────
router.get('/orders', (req, res) => {
  const { status } = req.query;

  let query = `
    SELECT id, created_at, customer_name, customer_email, status,
           wc_order_id, review_sent_at, accepted_at, delivered_at
    FROM designs
  `;
  const params = [];

  if (status) {
    query += ' WHERE status = ?';
    params.push(status);
  }

  query += ' ORDER BY created_at DESC';

  const orders = db.prepare(query).all(...params);
  res.json({ orders });
});

// ── POST /api/admin/orders/:id/send-review ────────────────────────────────────
router.post('/orders/:id/send-review', upload.single('pdf'), async (req, res) => {
  const { id } = req.params;

  const design = db.prepare('SELECT * FROM designs WHERE id = ?').get(id);
  if (!design) {
    return res.status(404).json({ error: 'Design not found' });
  }

  if (!['paid', 'in_review'].includes(design.status)) {
    return res.status(400).json({
      error: `Design status is '${design.status}' — expected 'paid' or 'in_review'`,
    });
  }

  if (!req.file) {
    return res.status(400).json({ error: 'No PDF file provided' });
  }

  // Store review PDF as base64 and generate review token
  const reviewToken    = uuidv4().replace(/-/g, '');
  const reviewPdfB64   = req.file.buffer.toString('base64');
  const base           = process.env.BASE_URL || 'http://localhost:3001';
  const previewUrl     = `${base}/api/review/preview/${reviewToken}`;
  const acceptUrl      = `${base}/api/review/accept/${reviewToken}`;

  db.prepare(`
    UPDATE designs
    SET status = 'in_review',
        review_token = ?,
        review_pdf_base64 = ?,
        review_sent_at = CURRENT_TIMESTAMP
    WHERE id = ?
  `).run(reviewToken, reviewPdfB64, id);

  try {
    await email.sendDesignReview({
      to:           design.customer_email,
      customerName: design.customer_name,
      designId:     design.id,
      previewUrl,
      acceptUrl,
    });

    console.log(`[admin] Sent design review for ${id} to ${maskEmail(design.customer_email)}`);
    res.json({ ok: true, message: 'Review email sent' });
  } catch (err) {
    console.error(`[admin] Failed to send review email for ${id}:`, err.message);
    res.status(500).json({ error: 'Failed to send review email', detail: err.message });
  }
});

// POST /api/admin/cleanup-unpaid
// Hook for a scheduler. Also runs inside the process when
// UNPAID_CLEANUP_INTERVAL_HOURS is set. Does not touch paid designs.
router.post('/cleanup-unpaid', async (_req, res) => {
  try {
    const result = await cleanup.run();
    res.json({ ok: true, ...result });
  } catch (err) {
    console.error('[admin] unpaid cleanup failed:', err.message);
    res.status(500).json({ error: 'Cleanup failed', detail: err.message });
  }
});

// POST /api/admin/retention-purge
// Same job as the in-process timer. The purge runs live only when
// RETENTION_PURGE_DRY_RUN is exactly "0". The DB lock is shared with the timer.
router.post('/retention-purge', async (_req, res) => {
  try {
    const result = await retention.run();
    if (result.reason === 'overlap') {
      return res.status(409).json({ ok: false, ...result });
    }
    res.json({ ok: !result.aborted, ...result });
  } catch (err) {
    console.error('[admin] retention purge failed:', err.message);
    res.status(500).json({ error: 'Retention purge failed' });
  }
});

router.warnIfAdminUnconfigured = warnIfAdminUnconfigured;
router.passwordsMatch = passwordsMatch;
module.exports = router;
