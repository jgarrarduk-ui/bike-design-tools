'use strict';

/**
 * WooCommerce REST API v3
 *
 * Creates a pending order for one design. The design may be several catalogue
 * products (one line item each). design_id and geometry_summary are stored on
 * the order and on every line item so a paid order.updated webhook can find
 * the design, and so each line still identifies the design on its own.
 *
 * This module only creates and reads orders. It does not publish or update
 * products, so catalogue entries can stay draft.
 *
 * Requires environment variables:
 *   WC_URL, WC_CONSUMER_KEY, WC_CONSUMER_SECRET,
 *   and WC_PRODUCT_IDS and/or WC_PRODUCT_ID.
 *   WC_PRODUCT_PRICE is optional. It applies only when the request omits
 *   productIds and the order falls through to WC_PRODUCT_ID. An explicit
 *   productIds array, including a single id, uses each product's own price.
 *
 * WordPress webhook (topic: Order updated):
 *   {BASE_URL}/api/webhooks/woocommerce/order-updated
 */

const WC_API_VERSION = process.env.WC_API_VERSION || 'v3';
let loggedConfigError = '';

function env(name) {
  const value = process.env[name];
  return typeof value === 'string' ? value.trim() : '';
}

function isConfigured() {
  if (!(env('WC_URL') && env('WC_CONSUMER_KEY') && env('WC_CONSUMER_SECRET'))) return false;
  const { ids, error } = productConfig();
  if (error) {
    if (loggedConfigError !== error.message) {
      loggedConfigError = error.message;
      console.error('[woocommerce]', error.message);
    }
    return false;
  }
  loggedConfigError = '';
  return ids.length > 0;
}

function apiUrl(path) {
  return `${env('WC_URL').replace(/\/$/, '')}/wp-json/wc/${WC_API_VERSION}${path}`;
}

function authHeader() {
  const credentials = Buffer.from(`${env('WC_CONSUMER_KEY')}:${env('WC_CONSUMER_SECRET')}`).toString('base64');
  return `Basic ${credentials}`;
}

async function wcFetch(path, method = 'GET', body = null) {
  const opts = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': authHeader(),
    },
  };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(apiUrl(path), opts);
  const data = await res.json();
  if (!res.ok) {
    const msg = JSON.stringify(data.message || data);
    throw new Error(`WooCommerce ${method} ${path} → ${res.status}: ${msg}`);
  }
  return data;
}

function clientError(message) {
  const err = new Error(message);
  err.status = 400;
  return err;
}

/**
 * Parse a comma-separated product id list. Empty tokens are ignored.
 * @param {string} raw
 * @param {string} label  env var name, used in errors
 * @returns {number[]}
 */
function parseProductIdList(raw, label) {
  if (!raw) return [];
  const ids = [];
  const seen = new Set();
  for (const part of String(raw).split(',')) {
    const token = part.trim();
    if (!token) continue;
    const n = Number(token);
    if (!Number.isSafeInteger(n) || n <= 0) {
      throw new Error(`Invalid product id "${token}" in ${label}.`);
    }
    if (!seen.has(n)) {
      seen.add(n);
      ids.push(n);
    }
  }
  return ids;
}

/**
 * Catalogue allow-list. WC_PRODUCT_IDS is the multi-product list.
 * WC_PRODUCT_ID is included too so the historical single-product env still works.
 * @returns {number[]}
 */
function configuredProductIds() {
  const ids = parseProductIdList(env('WC_PRODUCT_IDS'), 'WC_PRODUCT_IDS');
  const single = env('WC_PRODUCT_ID');
  if (single) {
    const n = Number(single);
    if (!Number.isSafeInteger(n) || n <= 0) {
      throw new Error('WC_PRODUCT_ID must be a positive integer.');
    }
    if (!ids.includes(n)) ids.push(n);
  }
  return ids;
}

function productConfig() {
  try {
    return { ids: configuredProductIds(), error: null };
  } catch (err) {
    return { ids: [], error: err };
  }
}

/**
 * Line items for this order.
 * - productIds, when sent, must be a non-empty subset of the configured catalogue.
 * - when omitted, WC_PRODUCT_ID is the order (single-product / smoke path).
 * - when WC_PRODUCT_ID is unset and exactly one catalogue id is configured, that id is used.
 *
 * @param {number[]|string[]|undefined|null} requested
 * @returns {number[]}
 */
function resolveProductIds(requested) {
  const { ids: allowed, error } = productConfig();
  if (error) throw error;
  if (!allowed.length) {
    throw new Error('WooCommerce is not configured. Set WC_PRODUCT_IDS or WC_PRODUCT_ID.');
  }
  const allow = new Set(allowed);

  if (requested == null) {
    const single = env('WC_PRODUCT_ID');
    if (single) {
      const n = Number(single);
      if (!allow.has(n)) {
        throw clientError(`WC_PRODUCT_ID ${n} is not in the configured product list.`);
      }
      return [n];
    }
    if (allowed.length === 1) return allowed.slice();
    throw clientError('productIds is required when more than one WooCommerce product is configured.');
  }

  if (!Array.isArray(requested) || requested.length === 0) {
    throw clientError('productIds must be a non-empty array of WooCommerce product IDs.');
  }

  const ids = [];
  const seen = new Set();
  for (const raw of requested) {
    if (typeof raw !== 'number' && typeof raw !== 'string') {
      throw clientError('productIds must contain positive integer product IDs.');
    }
    const token = typeof raw === 'string' ? raw.trim() : raw;
    const n = Number(token);
    if (!Number.isSafeInteger(n) || n <= 0) {
      throw clientError('productIds must contain positive integer product IDs.');
    }
    if (!allow.has(n)) {
      console.info(`[woocommerce] rejected product ${n}; configured ids: ${allowed.join(', ')}`);
      throw clientError(`Product ${n} is not available.`);
    }
    if (!seen.has(n)) {
      seen.add(n);
      ids.push(n);
    }
  }
  return ids;
}

function geometrySummaryFrom(params) {
  const p = params || {};
  return [
    p.reach            && `Reach: ${p.reach}mm`,
    p.chainstay_length && `CS: ${p.chainstay_length}mm`,
    p.ht_angle         && `HTA: ${p.ht_angle}°`,
    p.st_angle         && `STA: ${p.st_angle}°`,
    p.bb_drop          && `BB Drop: ${p.bb_drop}mm`,
  ].filter(Boolean).join(', ');
}

function designMeta(designId, geometrySummary) {
  const id = String(designId);
  return [
    { key: 'design_id',           value: id },
    { key: 'creature_design_id',  value: id },
    { key: 'geometry_summary',    value: geometrySummary },
  ];
}

/**
 * Build the WooCommerce order create body. Exported for tests.
 *
 * @param {object} opts
 * @param {string} opts.designId
 * @param {string} opts.customerName
 * @param {string} opts.customerEmail
 * @param {object} opts.params
 * @param {number[]|string[]|undefined} [opts.productIds]
 *        Omitted means the legacy WC_PRODUCT_ID default. That is the only
 *        path that may apply WC_PRODUCT_PRICE.
 * @param {number[]|undefined} [opts.resolvedIds]
 *        Already validated by resolveProductIds. Skips a second catalogue check.
 * @param {boolean|undefined} [opts.implicitDefault]
 *        Required with resolvedIds. True only when the client omitted productIds.
 */
function buildOrderPayload({
  designId, customerName, customerEmail, params,
  productIds, resolvedIds, implicitDefault,
}) {
  const ids = resolvedIds != null ? resolvedIds : resolveProductIds(productIds);
  const implicit = resolvedIds != null ? implicitDefault === true : productIds == null;
  const geometrySummary = geometrySummaryFrom(params);
  const meta = designMeta(designId, geometrySummary);
  const singlePrice = implicit && ids.length === 1 ? env('WC_PRODUCT_PRICE') : '';

  const lineItems = ids.map(productId => {
    const lineItem = {
      product_id: productId,
      quantity: 1,
      meta_data: designMeta(designId, geometrySummary),
    };
    if (singlePrice) {
      lineItem.subtotal = singlePrice;
      lineItem.total    = singlePrice;
    }
    return lineItem;
  });

  const nameParts = String(customerName || '').trim().split(' ');
  const firstName = nameParts[0] || customerName;
  const lastName  = nameParts.slice(1).join(' ') || '';

  return {
    payment_method:       'bacs',
    payment_method_title: 'Bank Transfer',
    set_paid:             false,
    billing: {
      first_name: firstName,
      last_name:  lastName,
      email:      customerEmail,
    },
    line_items: lineItems,
    meta_data: meta,
    customer_note: `Bespoke bike design — ID: ${designId}`,
  };
}

/**
 * Create a WooCommerce order for a bespoke design.
 * One design_id, one or more catalogue line items.
 *
 * @param {object} opts
 * @param {string} opts.designId        - Our internal design UUID
 * @param {string} opts.customerName
 * @param {string} opts.customerEmail
 * @param {object} opts.params          - Bike geometry params
 * @param {number[]|string[]|undefined} [opts.productIds]
 * @param {number[]|undefined} [opts.resolvedIds]
 * @param {boolean|undefined} [opts.implicitDefault]
 * @returns {{ checkoutUrl: string, wcOrderId: string }}
 */
async function createOrder({
  designId, customerName, customerEmail, params,
  productIds, resolvedIds, implicitDefault,
}) {
  if (!(env('WC_URL') && env('WC_CONSUMER_KEY') && env('WC_CONSUMER_SECRET'))) {
    throw new Error('WooCommerce is not configured. Check WC_* environment variables.');
  }

  const payload = buildOrderPayload({
    designId, customerName, customerEmail, params,
    productIds, resolvedIds, implicitDefault,
  });
  const order = await wcFetch('/orders', 'POST', payload);

  const checkoutUrl = order.payment_url ||
    `${env('WC_URL').replace(/\/$/, '')}/checkout/order-pay/${order.id}/?pay_for_order=true&key=${order.order_key}`;

  return {
    wcOrderId: String(order.id),
    checkoutUrl,
  };
}

function metaValue(meta, key) {
  const entry = (meta || []).find(m => m && m.key === key && m.value != null && m.value !== '');
  return entry ? String(entry.value) : null;
}

/**
 * design_id from an order payload: order meta first, then each line item.
 * Multi-line orders store the same id in both places.
 *
 * @param {object|null|undefined} order
 * @returns {string|null}
 */
function designIdFromMeta(meta) {
  return metaValue(meta, 'design_id') || metaValue(meta, 'creature_design_id');
}

function designIdFromOrder(order) {
  if (!order) return null;
  const fromOrder = designIdFromMeta(order.meta_data);
  if (fromOrder) return fromOrder;
  for (const item of order.line_items || []) {
    const fromLine = designIdFromMeta(item && item.meta_data);
    if (fromLine) return fromLine;
  }
  return null;
}

/**
 * Given a WooCommerce order ID, return the design_id stored in meta_data.
 *
 * @param {string|number} wcOrderId
 * @returns {Promise<string|null>} designId
 */
async function getDesignIdFromOrder(wcOrderId) {
  if (!isConfigured()) return null;
  try {
    const order = await wcFetch(`/orders/${wcOrderId}`);
    return designIdFromOrder(order);
  } catch (err) {
    console.error('woocommerce.getDesignIdFromOrder error:', err.message);
    return null;
  }
}

/**
 * Read one Woo order. null when Woo has no such order (404).
 * Other failures throw so the caller can keep a stored checkout URL.
 *
 * @param {string|number} wcOrderId
 * @returns {Promise<object|null>}
 */
async function getOrder(wcOrderId) {
  if (!wcOrderId) return null;
  try {
    return await wcFetch(`/orders/${encodeURIComponent(String(wcOrderId))}`);
  } catch (err) {
    if (/→ 404:/.test(err.message)) return null;
    throw err;
  }
}

/**
 * Cancel a Woo order only while it is still pending. Paid and on-hold orders
 * are left alone. Missing orders are reported, not thrown.
 *
 * @param {string|number} wcOrderId
 * @returns {Promise<{cancelled?: boolean, skipped?: string}>}
 */
async function cancelPendingOrder(wcOrderId) {
  const order = await getOrder(wcOrderId);
  if (!order) return { skipped: 'missing' };
  const status = String(order.status || '').toLowerCase();
  if (status !== 'pending') return { skipped: status };
  await wcFetch(`/orders/${encodeURIComponent(String(wcOrderId))}`, 'PUT', { status: 'cancelled' });
  return { cancelled: true };
}

/**
 * Ask the shop whether a paid Frame Designer order can still be changed,
 * and write a geometry revision onto that same order.
 * The change token is the credential. This does not send prices or totals.
 *
 * @param {'GET'|'POST'} method
 * @param {object} payload
 * @returns {Promise<{status: number, body: object}>}
 */
async function requestChange(method, payload) {
  if (!env('WC_URL')) {
    const err = new Error('WooCommerce is not configured.');
    err.status = 503;
    throw err;
  }
  const url = new URL(`${env('WC_URL').replace(/\/$/, '')}/wp-json/creature-fd/v1/change`);
  const opts = {
    method,
    headers: { accept: 'application/json' },
  };
  if (method === 'GET') {
    url.searchParams.set('design', String(payload.designId || ''));
    url.searchParams.set('token', String(payload.token || ''));
  } else {
    opts.headers['content-type'] = 'application/json';
    opts.body = JSON.stringify({
      designId: payload.designId,
      token: payload.token,
      productIds: payload.productIds,
      geometrySummary: payload.geometrySummary,
      idempotencyKey: payload.idempotencyKey,
    });
  }
  const res = await fetch(url, opts);
  let body = {};
  try { body = await res.json(); } catch { body = {}; }
  if (!body || typeof body !== 'object') body = {};
  return { status: res.status, body };
}

module.exports = {
  isConfigured,
  createOrder,
  getDesignIdFromOrder,
  resolveProductIds,
  buildOrderPayload,
  designIdFromOrder,
  geometrySummaryFrom,
  configuredProductIds,
  getOrder,
  cancelPendingOrder,
  requestChange,
};
