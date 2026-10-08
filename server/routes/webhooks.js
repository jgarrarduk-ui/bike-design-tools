'use strict';

/**
 * WooCommerce webhook handler
 *
 * Listens for `order.updated` events from WooCommerce.
 * On receipt: validates HMAC signature, checks order status is paid,
 * finds matching design, and sends the payment email. The same Order updated
 * topic also moves a paid design to cancelled, refunded, or failed. No second
 * webhook or secret is required.
 *
 * WooCommerce setup (WordPress admin):
 *   WooCommerce → Settings → Advanced → Webhooks → Add webhook
 *   Name:   Order paid
 *   Status: Active
 *   Topic:  Order updated
 *   URL:    {BASE_URL}/api/webhooks/woocommerce/order-updated
 *   Secret: WC_WEBHOOK_SECRET
 *   With NODE_ENV=production, a missing secret rejects the webhook.
 *
 * An order can contain several line items for one design. Payment status is
 * on the order, so one paid event covers every line. The design is found by
 * wc_order_id, then by design_id on the order meta, then on each line item.
 */

const express  = require('express');
const crypto   = require('crypto');
const db         = require('../db');
const email      = require('../services/email');
const woocommerce = require('../services/woocommerce');

const router = express.Router();

// Statuses that indicate a completed payment in WooCommerce
const PAID_STATUSES = new Set(['processing', 'completed']);

// Woo statuses that take a design out of the paid review queue.
// `failed` only applies before payment, so a later processing event can
// still mark the design paid. cancelled and refunded apply after payment.
const CLOSED_STATUSES = {
  cancelled: 'cancelled',
  refunded: 'refunded',
  failed: 'failed',
};

const PRE_PAYMENT = ['pending', 'checkout_created', 'failed'];
const AFTER_PAYMENT = ['paid', 'in_review', 'accepted', 'delivered', 'failed', 'cancelled'];

// ── HMAC validation middleware ───────────────────────────────────────────────
function verifyWooCommerceSignature(req, res, next) {
  const secret = process.env.WC_WEBHOOK_SECRET;
  if (!secret) {
    // Dev can run without a secret. Production must fail closed: an unsigned
    // order.updated would mark a design paid.
    if (process.env.NODE_ENV === 'production') {
      console.error('[webhook] WC_WEBHOOK_SECRET not set — rejecting request');
      return res.status(401).json({ error: 'Webhook secret is not configured' });
    }
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

    const design = await findDesign(order, wcOrderId);
    if (!design) {
      console.warn(`[webhook] No design found for WooCommerce order ${wcOrderId}`);
      return;
    }

    if (Object.prototype.hasOwnProperty.call(CLOSED_STATUSES, status)) {
      applyClosedStatus(design, status);
      return;
    }

    // Only process paid statuses
    if (!PAID_STATUSES.has(status)) {
      console.info(`[webhook] Order ${wcOrderId} status '${status}' — not a paid status, ignoring`);
      return;
    }

    if (!PRE_PAYMENT.includes(design.status)) {
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

async function findDesign(order, wcOrderId) {
  let design = db.prepare('SELECT * FROM designs WHERE wc_order_id = ?').get(wcOrderId);
  if (design) return design;
  const designId = woocommerce.designIdFromOrder(order)
    || await woocommerce.getDesignIdFromOrder(wcOrderId);
  if (!designId) return null;
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(designId) || null;
}

function applyClosedStatus(design, wooStatus) {
  const next = CLOSED_STATUSES[wooStatus];
  if (!next || design.status === next) {
    console.info(`[webhook] Design ${design.id} status '${design.status}' — already ${next}, skipping`);
    return;
  }
  if (wooStatus === 'failed') {
    if (!['pending', 'checkout_created'].includes(design.status)) {
      console.info(`[webhook] Design ${design.id} status '${design.status}' — not moving a paid design to failed`);
      return;
    }
  } else if (!AFTER_PAYMENT.includes(design.status) || (wooStatus === 'cancelled' && design.status === 'refunded')) {
    console.info(`[webhook] Design ${design.id} status '${design.status}' — leaving it on Woo status '${wooStatus}'`);
    return;
  }
  db.prepare('UPDATE designs SET status = ? WHERE id = ?').run(next, design.id);
  console.info(`[webhook] Design ${design.id} status '${design.status}' → '${next}'`);
}

module.exports = router;
