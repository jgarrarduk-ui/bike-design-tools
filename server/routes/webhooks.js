'use strict';

/**
 * WooCommerce webhook handler
 *
 * Listens for `order.updated` events from WooCommerce.
 * On receipt: validates HMAC signature, checks order status is paid,
 * finds matching design, generates download token, sends email with download link.
 *
 * WooCommerce setup:
 *   WooCommerce → Settings → Advanced → Webhooks → Add webhook
 *   Name:   Order paid
 *   Status: Active
 *   Topic:  Order updated
 *   URL:    https://your-domain.com/api/webhooks/woocommerce/order-updated
 *   Secret: set as WC_WEBHOOK_SECRET env var
 */

const express  = require('express');
const crypto   = require('crypto');
const db         = require('../db');
const email      = require('../services/email');
const woocommerce = require('../services/woocommerce');

const router = express.Router();

// Statuses that indicate a completed payment in WooCommerce
const PAID_STATUSES = new Set(['processing', 'completed']);

// ── HMAC validation middleware ───────────────────────────────────────────────
function verifyWooCommerceSignature(req, res, next) {
  const secret = process.env.WC_WEBHOOK_SECRET;
  if (!secret) {
    // Webhook secret not configured — allow through in dev, warn loudly
    console.warn('[webhook] WC_WEBHOOK_SECRET not set — skipping signature check (dev mode only)');
    return next();
  }

  const sigHeader = req.headers['x-wc-webhook-signature'];
  if (!sigHeader) {
    return res.status(401).json({ error: 'Missing webhook signature header' });
  }

  // req.rawBody is populated by express.raw() below
  const digest = crypto
    .createHmac('sha256', secret)
    .update(req.rawBody)
    .digest('base64');

  const safe     = Buffer.from(digest);
  const provided = Buffer.from(sigHeader);

  if (safe.length !== provided.length || !crypto.timingSafeEqual(safe, provided)) {
    console.warn('[webhook] Signature mismatch — rejecting request');
    return res.status(401).json({ error: 'Signature verification failed' });
  }

  next();
}

// ── order.updated ─────────────────────────────────────────────────────────────
router.post(
  '/woocommerce/order-updated',
  express.raw({ type: 'application/json' }),  // raw body for HMAC
  (req, res, next) => {
    req.rawBody = req.body;
    try { req.body = JSON.parse(req.rawBody.toString('utf8')); } catch { req.body = {}; }
    next();
  },
  verifyWooCommerceSignature,
  async (req, res) => {
    // Acknowledge WooCommerce immediately
    res.status(200).json({ received: true });

    const order    = req.body;
    const wcOrderId = String(order.id || '');
    const status   = order.status || '';

    console.log(`[webhook] order.updated — WooCommerce order ${wcOrderId}, status: ${status}`);

    if (!wcOrderId) return;

    // Only process paid statuses
    if (!PAID_STATUSES.has(status)) {
      console.info(`[webhook] Order ${wcOrderId} status '${status}' — not a paid status, ignoring`);
      return;
    }

    // ── Find design by WooCommerce order ID ──────────────────────────────────
    let design = db.prepare('SELECT * FROM designs WHERE wc_order_id = ?').get(wcOrderId);

    // Fallback: look up design_id from order meta_data via WooCommerce API
    if (!design) {
      const designId = await woocommerce.getDesignIdFromOrder(wcOrderId);
      if (designId) {
        design = db.prepare('SELECT * FROM designs WHERE id = ?').get(designId);
      }
    }

    if (!design) {
      console.warn(`[webhook] No design found for WooCommerce order ${wcOrderId}`);
      return;
    }

    if (!['pending', 'checkout_created'].includes(design.status)) {
      console.info(`[webhook] Design ${design.id} status '${design.status}' — already past payment, skipping`);
      return;
    }

    // ── Mark as paid ─────────────────────────────────────────────────────────
    db.prepare(`UPDATE designs SET status = 'paid' WHERE id = ?`).run(design.id);

    // ── Send payment confirmation (design now in review queue) ───────────────
    try {
      await email.sendPaymentConfirmation({
        to:           design.customer_email,
        customerName: design.customer_name,
        designId:     design.id,
      });
      console.log(`[webhook] Payment confirmed for design ${design.id} — sent review queue email to ${design.customer_email}`);
    } catch (err) {
      console.error(`[webhook] Failed to send payment confirmation for design ${design.id}:`, err.message);
    }
  },
);

module.exports = router;
