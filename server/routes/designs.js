'use strict';

/**
 * POST /api/designs
 *
 * Called by the frontend when the user clicks "Order Bespoke Files".
 * Saves the design + PDF to SQLite, creates a WooCommerce order,
 * and returns the WooCommerce checkout URL.
 *
 * Body: {
 *   customerName: string,
 *   customerEmail: string,
 *   params: object,       // bike geometry
 *   pdfBase64: string,    // PDF generated client-side, base64-encoded
 * }
 *
 * Response: {
 *   designId: string,
 *   checkoutUrl: string,  // WooCommerce order-pay URL or placeholder
 *   message: string,
 * }
 */

const express  = require('express');
const { v4: uuidv4 } = require('uuid');
const db           = require('../db');
const woocommerce  = require('../services/woocommerce');
const email        = require('../services/email');

const router = express.Router();

router.post('/', async (req, res) => {
  const { customerName, customerEmail, params, pdfBase64 } = req.body || {};

  // ── Validation ──────────────────────────────────────────────────────────────
  if (!customerName || typeof customerName !== 'string' || !customerName.trim()) {
    return res.status(400).json({ error: 'customerName is required.' });
  }
  if (!customerEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerEmail)) {
    return res.status(400).json({ error: 'A valid customerEmail is required.' });
  }
  if (!params || typeof params !== 'object') {
    return res.status(400).json({ error: 'params (bike geometry object) is required.' });
  }

  const designId = uuidv4();

  // ── Save design to DB ────────────────────────────────────────────────────────
  try {
    db.prepare(`
      INSERT INTO designs (id, customer_name, customer_email, params, pdf_base64, status)
      VALUES (?, ?, ?, ?, ?, 'pending')
    `).run(
      designId,
      customerName.trim(),
      customerEmail.toLowerCase().trim(),
      JSON.stringify(params),
      pdfBase64 || null,
    );
  } catch (err) {
    console.error('[designs] DB insert error:', err.message);
    return res.status(500).json({ error: 'Failed to save design. Please try again.' });
  }

  // ── Create WooCommerce order ──────────────────────────────────────────────────
  let checkoutUrl;
  let wcOrderId;

  if (woocommerce.isConfigured()) {
    try {
      const result = await woocommerce.createOrder({
        designId,
        customerName: customerName.trim(),
        customerEmail: customerEmail.toLowerCase().trim(),
        params,
      });
      checkoutUrl = result.checkoutUrl;
      wcOrderId   = result.wcOrderId;

      db.prepare(`
        UPDATE designs SET status = 'checkout_created',
          wc_order_id = ?, wc_checkout_url = ?
        WHERE id = ?
      `).run(wcOrderId, checkoutUrl, designId);
    } catch (err) {
      console.error('[designs] WooCommerce error:', err.message);
      // Don't fail the request — log and fall through to placeholder
      checkoutUrl = null;
    }
  }

  // ── Fallback when WooCommerce isn't configured ────────────────────────────────
  if (!checkoutUrl) {
    const base = process.env.BASE_URL || `http://localhost:${process.env.PORT || 3001}`;
    checkoutUrl = `${base}/order-confirmation?design=${designId}&status=pending`;
    console.warn('[designs] WooCommerce not configured — using placeholder checkout URL:', checkoutUrl);
  }

  // ── Send confirmation email ──────────────────────────────────────────────────
  try {
    await email.sendOrderConfirmation({
      to:           customerEmail.toLowerCase().trim(),
      customerName: customerName.trim(),
      designId,
    });
  } catch (err) {
    // Non-fatal — log and continue
    console.warn('[designs] Confirmation email failed:', err.message);
  }

  return res.json({
    designId,
    checkoutUrl,
    message: 'Design saved. Proceed to checkout.',
  });
});

module.exports = router;
