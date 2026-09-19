'use strict';

/**
 * Review routes — customer-facing endpoints for design review.
 *
 *   GET /api/review/preview/:token  — download the review PDF
 *   GET /api/review/accept/:token   — accept the design, trigger final file delivery
 */

const express  = require('express');
const { v4: uuidv4 } = require('uuid');
const db       = require('../db');
const email    = require('../services/email');

const router = express.Router();

// ── GET /api/review/preview/:token ────────────────────────────────────────────
router.get('/preview/:token', (req, res) => {
  const design = db.prepare('SELECT * FROM designs WHERE review_token = ?').get(req.params.token);

  if (!design || !design.review_pdf_base64) {
    return res.status(404).send('Review files not found.');
  }

  if (!['in_review', 'accepted', 'delivered'].includes(design.status)) {
    return res.status(400).send('Design is not currently in review.');
  }

  const shortId  = design.id.slice(0, 8).toUpperCase();
  const pdfBuffer = Buffer.from(design.review_pdf_base64, 'base64');

  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `inline; filename="creature-cycles-review-${shortId}.pdf"`);
  res.send(pdfBuffer);
});

// ── GET /api/review/accept/:token ─────────────────────────────────────────────
router.get('/accept/:token', async (req, res) => {
  const design = db.prepare('SELECT * FROM designs WHERE review_token = ?').get(req.params.token);

  if (!design) {
    return res.status(404).send(acceptPage('Link not found', 'This review link was not found. Please contact us if you believe this is an error.', false));
  }

  if (design.status === 'accepted' || design.status === 'delivered') {
    return res.send(acceptPage(
      'Already accepted',
      'You have already accepted this design. Your final files were emailed to you. Please check your inbox (and spam folder). If you need the files resent, please contact us.',
      false,
    ));
  }

  if (design.status !== 'in_review') {
    return res.status(400).send(acceptPage('Not ready', 'This design is not currently in the review stage. Please contact us for help.', false));
  }

  // ── Generate final download token ─────────────────────────────────────────
  const downloadToken = uuidv4().replace(/-/g, '');
  const expiryHours   = Number(process.env.DOWNLOAD_TOKEN_EXPIRES_HOURS) || 72;
  const expiresAt     = new Date(Date.now() + expiryHours * 60 * 60 * 1000).toISOString();
  const base          = process.env.BASE_URL || 'http://localhost:3001';
  const downloadUrl   = `${base}/api/download/${downloadToken}`;

  db.prepare(`
    UPDATE designs
    SET status = 'accepted',
        accepted_at = CURRENT_TIMESTAMP,
        download_token = ?,
        download_expires_at = ?
    WHERE id = ?
  `).run(downloadToken, expiresAt, design.id);

  // ── Send final files email ────────────────────────────────────────────────
  try {
    await email.sendDesignAccepted({
      to:           design.customer_email,
      customerName: design.customer_name,
      designId:     design.id,
      downloadUrl,
      expiresAt:    `${expiryHours} hours`,
    });

    db.prepare(`UPDATE designs SET status = 'delivered', delivered_at = CURRENT_TIMESTAMP WHERE id = ?`)
      .run(design.id);

    console.log(`[review] Design ${design.id} accepted — sent final files to ${design.customer_email}`);
  } catch (err) {
    console.error(`[review] Failed to send final files for design ${design.id}:`, err.message);
    db.prepare(`UPDATE designs SET status = 'failed' WHERE id = ?`).run(design.id);
  }

  res.send(acceptPage(
    'Design accepted!',
    `Thank you! Your final design files have been emailed to <strong>${design.customer_email}</strong>. Please check your inbox — the download link is valid for ${expiryHours} hours.`,
    true,
  ));
});

// ── Simple HTML response pages ────────────────────────────────────────────────
function acceptPage(title, message, success) {
  const colour = success ? '#2d6a2d' : '#8b3a3a';
  const bg     = success ? '#f0faf0' : '#fff5f5';
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${title} — Creature Cycles</title>
  <style>
    body { font-family: monospace; background: #f4f4f4; margin: 0; padding: 40px 20px; }
    .card { max-width: 540px; margin: 60px auto; background: #fff; border-radius: 8px;
            padding: 40px; border: 1px solid #ddd; text-align: center; }
    h1 { font-size: 20px; color: #111; margin-bottom: 8px; }
    .brand { font-size: 13px; color: #999; margin-bottom: 32px; }
    .msg { font-size: 15px; line-height: 1.7; color: #333;
           background: ${bg}; border-left: 3px solid ${colour};
           padding: 16px 20px; border-radius: 4px; text-align: left; }
    a { color: #111; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Creature Cycles</h1>
    <p class="brand">Bespoke Frame Design Files</p>
    <p class="msg">${message}</p>
  </div>
</body>
</html>`;
}

module.exports = router;
