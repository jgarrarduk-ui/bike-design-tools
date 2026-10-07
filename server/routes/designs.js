'use strict';

/**
 * Design routes.
 *
 * POST /api/designs
 *   Saves the design, mints a resume_token, stores product_ids, creates a
 *   WooCommerce pending order, and emails edit + checkout links.
 *
 * GET /api/designs/:id?resume=
 *   Hydrate for Frame Designer. Token must match. 404 otherwise.
 *   A bare design id is not enough.
 *
 * GET /api/designs/:id/checkout?resume=
 *   302 to the stored Woo order-pay URL. Recreates the pending order when
 *   that URL is missing or the Woo order is cancelled / gone.
 *
 * Resume tokens last 90 days (RESUME_TOKEN_TTL_DAYS) while status is pending
 * or checkout_created. A later status, or an expiry in the past, is 404.
 * Editing geometry or parts in Frame Designer posts a new design and a new
 * pending order. The scrapped unpaid order is left for unpaid-cleanup.
 *
 * Body (POST): {
 *   customerName: string,
 *   customerEmail: string,
 *   params: object,
 *   pdfBase64: string,
 *   productIds?: number[]
 * }
 *
 * Response (POST): { designId, checkoutUrl, message }
 * The resume token is only emailed. It is not returned to the browser.
 */

const crypto = require('crypto');
const express = require('express');
const { v4: uuidv4 } = require('uuid');
const db = require('../db');
const woocommerce = require('../services/woocommerce');
const email = require('../services/email');
const cleanup = require('../services/unpaid-cleanup');

const router = express.Router();

const RESUME_STATUSES = new Set(['pending', 'checkout_created']);
const DEAD_ORDER_STATUSES = new Set(['cancelled', 'canceled', 'failed', 'refunded', 'trash']);
const DUMMY_RESUME = crypto.randomBytes(32).toString('hex');

const FRAME_DESIGNER_DEFAULT = 'https://creaturecycles.co.uk/apps/frame-designer.html';

function publicBase() {
  const raw = process.env.BASE_URL || `http://localhost:${process.env.PORT || 3001}`;
  return String(raw).replace(/\/$/, '');
}

function tokensMatch(stored, given) {
  const left = Buffer.from(typeof stored === 'string' && stored ? stored : DUMMY_RESUME);
  const right = Buffer.from(typeof given === 'string' ? given : '');
  if (left.length !== right.length) {
    crypto.timingSafeEqual(left, left);
    return false;
  }
  return crypto.timingSafeEqual(left, right);
}

function resumeFromQuery(req) {
  const value = req.query && req.query.resume;
  return typeof value === 'string' ? value : '';
}

function loadResumableDesign(designId, resumeToken) {
  const design = designId
    ? db.prepare('SELECT * FROM designs WHERE id = ?').get(designId)
    : null;
  const matches = tokensMatch(design && design.resume_token, resumeToken);
  if (!design || !matches || !RESUME_STATUSES.has(design.status)) return null;
  if (cleanup.resumeExpired(design)) return null;
  return design;
}

function cleanDesignName(value) {
  if (typeof value !== 'string') return null;
  const name = value.trim().replace(/\s+/g, ' ').slice(0, 120);
  return name || null;
}

function parseJson(raw, fallback) {
  try {
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function storedProductSelection(raw) {
  const parsed = parseJson(raw, null);
  if (Array.isArray(parsed) && parsed.length) {
    return { resolvedIds: parsed, implicitDefault: false };
  }
  return { resolvedIds: undefined, implicitDefault: true };
}

function hydrateBody(design) {
  const params = parseJson(design.params, {});
  const parsedIds = parseJson(design.product_ids, []);
  return {
    designId: design.id,
    params: params && typeof params === 'object' ? params : {},
    productIds: Array.isArray(parsedIds) ? parsedIds : [],
    customerName: design.customer_name,
    customerEmail: design.customer_email,
    checkoutUrl: design.wc_checkout_url || null,
  };
}

function frameDesignerEditUrl(designId, resumeToken) {
  const url = new URL(process.env.FRAME_DESIGNER_URL || FRAME_DESIGNER_DEFAULT);
  url.searchParams.set('design', designId);
  url.searchParams.set('resume', resumeToken);
  return url.toString();
}

function apiCheckoutUrl(designId, resumeToken) {
  const url = new URL(`${publicBase()}/api/designs/${designId}/checkout`);
  url.searchParams.set('resume', resumeToken);
  return url.toString();
}

function isSafeRedirect(url) {
  let parsed;
  try { parsed = new URL(url); } catch { return false; }
  if (parsed.username || parsed.password) return false;
  if (parsed.protocol === 'https:') return true;
  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1';
  return parsed.protocol === 'http:' && local;
}

async function recreatePendingOrder(design) {
  const params = parseJson(design.params, {});
  const selection = storedProductSelection(design.product_ids);
  const result = await woocommerce.createOrder({
    designId: design.id,
    customerName: design.customer_name,
    customerEmail: design.customer_email,
    params: params && typeof params === 'object' ? params : {},
    resolvedIds: selection.resolvedIds,
    implicitDefault: selection.implicitDefault,
  });

  db.prepare(`
    UPDATE designs SET status = 'checkout_created',
      wc_order_id = ?, wc_checkout_url = ?
    WHERE id = ?
  `).run(result.wcOrderId, result.checkoutUrl, design.id);

  return result.checkoutUrl;
}

router.post('/', async (req, res) => {
  const { customerName, customerEmail, params, pdfBase64, productIds } = req.body || {};
  const designName = cleanDesignName(req.body && req.body.designName);

  if (!customerName || typeof customerName !== 'string' || !customerName.trim()) {
    return res.status(400).json({ error: 'customerName is required.' });
  }
  if (!customerEmail || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(customerEmail)) {
    return res.status(400).json({ error: 'A valid customerEmail is required.' });
  }
  if (!params || typeof params !== 'object') {
    return res.status(400).json({ error: 'params (bike geometry object) is required.' });
  }

  let selectedProductIds;
  let implicitDefault = false;
  if (woocommerce.isConfigured()) {
    try {
      implicitDefault = productIds == null;
      selectedProductIds = woocommerce.resolveProductIds(productIds);
    } catch (err) {
      const status = err.status || 500;
      if (status >= 500) console.error('[designs] product selection error:', err.message);
      return res.status(status).json({
        error: status === 400 ? err.message : 'Failed to resolve products.',
      });
    }
  }

  const designId = uuidv4();
  const resumeToken = crypto.randomBytes(32).toString('hex');
  const productIdsJson = selectedProductIds ? JSON.stringify(selectedProductIds) : null;
  const ttlModifier = `+${cleanup.resumeTtlDays()} days`;

  try {
    db.prepare(`
      INSERT INTO designs (
        id, customer_name, customer_email, params, pdf_base64, status,
        resume_token, product_ids, design_name, resume_expires_at
      )
      VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, datetime('now', ?))
    `).run(
      designId,
      customerName.trim(),
      customerEmail.toLowerCase().trim(),
      JSON.stringify(params),
      pdfBase64 || null,
      resumeToken,
      productIdsJson,
      designName,
      ttlModifier,
    );
  } catch (err) {
    console.error('[designs] DB insert error:', err.message);
    return res.status(500).json({ error: 'Failed to save design. Please try again.' });
  }

  let checkoutUrl;
  let wcOrderId;

  if (woocommerce.isConfigured()) {
    try {
      const result = await woocommerce.createOrder({
        designId,
        customerName: customerName.trim(),
        customerEmail: customerEmail.toLowerCase().trim(),
        params,
        resolvedIds: selectedProductIds,
        implicitDefault,
      });
      checkoutUrl = result.checkoutUrl;
      wcOrderId = result.wcOrderId;

      db.prepare(`
        UPDATE designs SET status = 'checkout_created',
          wc_order_id = ?, wc_checkout_url = ?
        WHERE id = ?
      `).run(wcOrderId, checkoutUrl, designId);
    } catch (err) {
      console.error('[designs] WooCommerce error:', err.message);
      checkoutUrl = null;
    }
  }

  if (!checkoutUrl) {
    const base = publicBase();
    checkoutUrl = `${base}/order-confirmation?design=${designId}&status=pending`;
    const reason = woocommerce.isConfigured()
      ? 'WooCommerce order was not created'
      : 'WooCommerce not configured';
    console.warn(`[designs] ${reason} — using placeholder checkout URL:`, checkoutUrl);
  }

  try {
    await email.sendOrderConfirmation({
      to: customerEmail.toLowerCase().trim(),
      customerName: customerName.trim(),
      designName,
      designId,
      editUrl: frameDesignerEditUrl(designId, resumeToken),
      checkoutUrl: apiCheckoutUrl(designId, resumeToken),
    });
  } catch (err) {
    console.warn('[designs] Confirmation email failed:', err.message);
  }

  return res.json({
    designId,
    checkoutUrl,
    message: 'Design saved. Proceed to checkout.',
  });
});

router.get('/:id/checkout', async (req, res) => {
  const design = loadResumableDesign(req.params.id, resumeFromQuery(req));
  if (!design) return res.status(404).type('text/plain').send('Design not found.');

  let checkoutUrl = design.wc_checkout_url || '';
  let replace = !checkoutUrl;

  if (!replace && design.wc_order_id && woocommerce.isConfigured()) {
    try {
      const order = await woocommerce.getOrder(design.wc_order_id);
      replace = !order || DEAD_ORDER_STATUSES.has(String(order.status || '').toLowerCase());
    } catch (err) {
      console.warn('[designs] Could not read Woo order', design.wc_order_id + ':', err.message);
    }
  }

  if (replace && woocommerce.isConfigured()) {
    try {
      checkoutUrl = await recreatePendingOrder(design);
    } catch (err) {
      console.error('[designs] Could not recreate pending order:', err.message);
      if (!checkoutUrl) {
        return res.status(502).type('text/plain').send('Checkout is unavailable. Save the design again from Frame Designer.');
      }
    }
  }

  if (!checkoutUrl) {
    checkoutUrl = `${publicBase()}/order-confirmation?design=${encodeURIComponent(design.id)}&status=pending`;
  }

  if (!isSafeRedirect(checkoutUrl)) {
    return res.status(502).type('text/plain').send('Checkout is unavailable.');
  }

  return res.redirect(302, checkoutUrl);
});

router.get('/:id', (req, res) => {
  const design = loadResumableDesign(req.params.id, resumeFromQuery(req));
  if (!design) return res.status(404).json({ error: 'Design not found.' });
  return res.json(hydrateBody(design));
});

module.exports = router;
