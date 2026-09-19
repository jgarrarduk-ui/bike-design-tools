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
 */

const express  = require('express');
const multer   = require('multer');
const { v4: uuidv4 } = require('uuid');
const db       = require('../db');
const email    = require('../services/email');

const router = express.Router();

// ── Auth middleware ───────────────────────────────────────────────────────────
function requireAdminAuth(req, res, next) {
  const password = process.env.ADMIN_PASSWORD;
  if (!password) {
    console.warn('[admin] ADMIN_PASSWORD not set — admin routes are unprotected');
    return next();
  }

  const authHeader = req.headers['authorization'] || '';
  const token = authHeader.startsWith('Bearer ') ? authHeader.slice(7) : null;

  if (!token || token !== password) {
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

    console.log(`[admin] Sent design review for ${id} to ${design.customer_email}`);
    res.json({ ok: true, message: 'Review email sent' });
  } catch (err) {
    console.error(`[admin] Failed to send review email for ${id}:`, err.message);
    res.status(500).json({ error: 'Failed to send review email', detail: err.message });
  }
});

module.exports = router;
