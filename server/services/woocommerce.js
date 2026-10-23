'use strict';

/**
 * WooCommerce REST API v3
 *
 * Creates a pending order for one design. The design may be several catalogue
 * products (one line item each). design_id and geometry_summary are stored on
 * the order and on every line item so a paid order.updated webhook can find
 * the design, and so each line still identifies the design on its own.
 *
 * This module creates and reads orders, and stores the design id as a private
 * order note. It does not publish or update products, so catalogue entries
 * can stay draft. The design id is not written into customer_note, because
 * Woo prints that field on customer emails.
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

const crypto = require('crypto');
const { redactText } = require('./redact');

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

async function wcFetch(path, method = 'GET', body = null, options = {}) {
  const opts = {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': authHeader(),
    },
  };
  if (options && options.signal) opts.signal = options.signal;
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(apiUrl(path), opts);
  const text = await res.text();
  let data = null;
  if (text) {
    try { data = JSON.parse(text); } catch { data = text; }
  }
  if (!res.ok) {
    let msg = data && typeof data === 'object' ? (data.message || data.code || 'request failed') : data;
    if (msg && typeof msg === 'object') msg = 'request failed';
    const err = new Error(`WooCommerce ${method} ${path} → ${res.status}: ${redactText(String(msg || 'request failed'))}`);
    err.status = res.status;
    err.code = data && typeof data === 'object' ? data.code : undefined;
    throw err;
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
    // An explicit productIds list leaves subtotal and total unset. WooCommerce
    // REST then sets both from wc_get_price_excluding_tax(), which reads the
    // product `price` (WC_Product::get_price()), and calculate_totals() adds
    // tax. GET /api/prices uses that same charge. WC_PRODUCT_PRICE is not it.
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
    payment_method:       SAVED_PAYMENT_METHOD,
    payment_method_title: SAVED_PAYMENT_TITLE,
    set_paid:             false,
    billing: {
      first_name: firstName,
      last_name:  lastName,
      email:      customerEmail,
    },
    line_items: lineItems,
    meta_data: meta,
  };
}

function designNoteText(designId) {
  return `Bespoke bike design — ID: ${designId}`;
}

// Admin label for a save that has not been paid. Empty payment_method is a
// valid Woo REST string: there is no gateway enum. WC_Order::set_payment_method('')
// also clears the title, and the v2/v3 order schema applies payment_method
// before payment_method_title, so this title is what remains. set_paid stays
// false, so payment_complete does not replace it with a gateway title.
// Order-pay lists every available gateway. It does not filter on the stored
// method. Preselection of a stored method is for admin-created orders only
// (Woo 11.3+), and only when that id is still an available gateway. REST
// creates are created_via rest-api, and '' matches no gateway.
const SAVED_PAYMENT_METHOD = '';
const SAVED_PAYMENT_TITLE = 'Saved design, awaiting card payment';
const SAVED_AWAITING_NOTE = 'Design saved from Frame Designer; no payment attempted yet.';

function savedAwaitingPaymentNote() {
  return SAVED_AWAITING_NOTE;
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
  await addPrivateDesignNote(order.id, designId);
  await addSavedAwaitingNote(order.id);

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
 * Confirm the Woo REST API is this shop. A 404 from a wrong host or a
 * broken permalink must not be treated as a missing order.
 *
 * @returns {Promise<true>}
 */
async function checkApi() {
  if (!isConfigured()) {
    const err = new Error('WooCommerce is not configured');
    err.code = 'WOO_UNCONFIGURED';
    throw err;
  }
  await wcFetch('/system_status');
  return true;
}

/**
 * Read one Woo order. null only when Woo says that order id does not exist
 * (`woocommerce_rest_shop_order_invalid_id`). Any other 404, and every
 * auth or server failure, throws. The retention purge skips one bad order
 * and aborts on auth failures, a run of errors, or too many invalid ids.
 *
 * @param {string|number} wcOrderId
 * @returns {Promise<object|null>}
 */
async function getOrder(wcOrderId) {
  if (!wcOrderId) return null;
  try {
    return await wcFetch(`/orders/${encodeURIComponent(String(wcOrderId))}`);
  } catch (err) {
    if (err.status === 404 && err.code === 'woocommerce_rest_shop_order_invalid_id') return null;
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
 * Cancel a Woo order without checking its current status. The retention
 * purge uses this for a payable order (pending, on-hold, or failed) that
 * has passed the one-year cutoff, then reads the order back. The caller
 * decides whether a cancel is appropriate.
 *
 * @param {string|number} wcOrderId
 * @returns {Promise<object>}
 */
async function cancelOrder(wcOrderId) {
  if (!wcOrderId) {
    const err = new Error('WooCommerce order id is missing');
    err.status = 400;
    throw err;
  }
  return wcFetch(`/orders/${encodeURIComponent(String(wcOrderId))}`, 'PUT', { status: 'cancelled' });
}

async function postPrivateNote(orderId, note) {
  await wcFetch(`/orders/${encodeURIComponent(String(orderId))}/notes`, 'POST', {
    note,
    customer_note: false,
  });
}

async function addPrivateDesignNote(orderId, designId) {
  if (!orderId) return;
  try {
    await postPrivateNote(orderId, designNoteText(designId));
  } catch (err) {
    console.warn('[woocommerce] Could not store the private design note:', err.message);
  }
}

async function addSavedAwaitingNote(orderId) {
  if (!orderId) return;
  try {
    await postPrivateNote(orderId, savedAwaitingPaymentNote());
  } catch (err) {
    console.warn('[woocommerce] Could not store the private saved-design note:', err.message);
  }
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
  url.searchParams.set('cb', crypto.randomBytes(8).toString('hex'));
  const opts = {
    method,
    cache: 'no-store',
    headers: {
      accept: 'application/json',
      'cache-control': 'no-cache',
      pragma: 'no-cache',
    },
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

/**
 * Read-only WooCommerce REST GET. Prices and other catalogue reads use this.
 * It does not POST, PUT, or DELETE.
 *
 * @param {string} path  Path under /wp-json/wc/v3, including a query string.
 * @returns {Promise<any>}
 */
async function wcGet(path, signal) {
  if (!(env('WC_URL') && env('WC_CONSUMER_KEY') && env('WC_CONSUMER_SECRET'))) {
    const err = new Error('WooCommerce is not configured.');
    err.status = 503;
    throw err;
  }
  return wcFetch(path, 'GET', null, { signal });
}

module.exports = {
  isConfigured,
  wcGet,
  createOrder,
  getDesignIdFromOrder,
  resolveProductIds,
  buildOrderPayload,
  designNoteText,
  savedAwaitingPaymentNote,
  designIdFromOrder,
  geometrySummaryFrom,
  configuredProductIds,
  checkApi,
  getOrder,
  cancelPendingOrder,
  cancelOrder,
  requestChange,
};
