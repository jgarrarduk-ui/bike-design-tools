'use strict';

/**
 * GET /api/download/:token
 *
 * Serves a ZIP archive containing:
 *   design.json  — full geometry parameters
 *   design.pdf   — 2D frame drawing (generated client-side and stored as base64)
 *
 * Token is single-use friendly but we allow multiple downloads until expiry
 * (the customer may need to re-download).
 */

const express  = require('express');
const archiver = require('archiver');
const db       = require('../db');

const router = express.Router();

router.get('/:token', (req, res) => {
  const { token } = req.params;

  // ── Look up token ──────────────────────────────────────────────────────────
  const design = db.prepare('SELECT * FROM designs WHERE download_token = ?').get(token);

  if (!design) {
    return res.status(404).send('Download link not found or has already expired.');
  }

  if (design.download_expires_at && new Date(design.download_expires_at) < new Date()) {
    return res.status(410).send('This download link has expired. Please contact us to request a new one.');
  }

  if (!['accepted', 'delivered'].includes(design.status)) {
    return res.status(402).send('Your design has not yet been accepted. Please accept the design review before downloading your final files.');
  }

  // ── Stream a ZIP ───────────────────────────────────────────────────────────
  const shortId   = design.id.slice(0, 8).toUpperCase();
  const filename  = `creature-cycles-design-${shortId}.zip`;

  res.setHeader('Content-Type', 'application/zip');
  res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);

  const archive = archiver('zip', { zlib: { level: 9 } });
  archive.on('error', (err) => {
    console.error('[download] archiver error:', err.message);
    if (!res.headersSent) res.status(500).end();
  });

  archive.pipe(res);

  // ── design.json ────────────────────────────────────────────────────────────
  const paramsObj = JSON.parse(design.params || '{}');
  const jsonExport = JSON.stringify({
    meta: {
      designId:      design.id,
      createdAt:     design.created_at,
      customerName:  design.customer_name,
      generator:     'Creature Cycles Bike Designer',
      version:       '1.0',
    },
    params: paramsObj,
  }, null, 2);

  archive.append(jsonExport, { name: 'design.json' });

  // ── design.pdf — prefer the reviewed/final PDF from admin, fall back to original ──
  const pdfSource = design.review_pdf_base64 || design.pdf_base64;
  if (pdfSource) {
    const b64 = pdfSource.replace(/^data:[^;]+;base64,/, '');
    archive.append(Buffer.from(b64, 'base64'), { name: 'design.pdf' });
  }

  // ── README ─────────────────────────────────────────────────────────────────
  const readme = [
    'CREATURE CYCLES — BESPOKE DESIGN FILES',
    '======================================',
    '',
    `Design ID   : ${design.id}`,
    `Customer    : ${design.customer_name}`,
    `Created     : ${design.created_at}`,
    '',
    'FILES',
    '-----',
    '  design.json  — Full parameter set (all geometry + tube dimensions)',
    '  design.pdf   — 2D frame drawing with annotations',
    '',
    'The JSON file contains every parameter used to define this bike geometry.',
    'You can reload it in the Creature Cycles designer at any time.',
    '',
    'Questions? Contact hello@creature-cycles.com',
  ].join('\n');

  archive.append(readme, { name: 'README.txt' });

  archive.finalize();
});

module.exports = router;
