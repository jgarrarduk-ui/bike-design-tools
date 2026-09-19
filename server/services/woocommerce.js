'use strict';

/**
 * WooCommerce REST API v3
 *
 * Creates a pending order for the bespoke design files product.
 * The design ID is stored in order meta_data so the webhook handler
 * can look it up after payment.
 *
 * Requires environment variables:
 *   WC_URL, WC_CONSUMER_KEY, WC_CONSUMER_SECRET,
 *   WC_PRODUCT_ID, WC_PRODUCT_PRICE
 */

const WC_URL            = process.env.WC_URL;
const WC_CONSUMER_KEY   = process.env.WC_CONSUMER_KEY;
const WC_CONSUMER_SECRET = process.env.WC_CONSUMER_SECRET;
const WC_API_VERSION    = process.env.WC_API_VERSION || 'v3';

function isConfigured() {
  return !!(WC_URL && WC_CONSUMER_KEY && WC_CONSUMER_SECRET && process.env.WC_PRODUCT_ID);
}

function apiUrl(path) {
  return `${WC_URL}/wp-json/wc/${WC_API_VERSION}${path}`;
}

function authHeader() {
  const credentials = Buffer.from(`${WC_CONSUMER_KEY}:${WC_CONSUMER_SECRET}`).toString('base64');
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

/**
 * Create a WooCommerce order for a single bespoke design product.
 *
 * @param {object} opts
 * @param {string} opts.designId        - Our internal design UUID
 * @param {string} opts.customerName
 * @param {string} opts.customerEmail
 * @param {object} opts.params          - Bike geometry params
 * @returns {{ checkoutUrl: string, wcOrderId: string }}
 */
async function createOrder({ designId, customerName, customerEmail, params }) {
  if (!isConfigured()) {
    throw new Error('WooCommerce is not configured. Check WC_* environment variables.');
  }

  const productId = process.env.WC_PRODUCT_ID;
  const price     = process.env.WC_PRODUCT_PRICE;

  const geometrySummary = [
    params.reach            && `Reach: ${params.reach}mm`,
    params.chainstay_length && `CS: ${params.chainstay_length}mm`,
    params.ht_angle         && `HTA: ${params.ht_angle}°`,
    params.st_angle         && `STA: ${params.st_angle}°`,
    params.bb_drop          && `BB Drop: ${params.bb_drop}mm`,
  ].filter(Boolean).join(', ');

  const nameParts = customerName.trim().split(' ');
  const firstName = nameParts[0] || customerName;
  const lastName  = nameParts.slice(1).join(' ') || '';

  const lineItem = {
    product_id: Number(productId),
    quantity: 1,
  };
  if (price) {
    lineItem.subtotal = price;
    lineItem.total    = price;
  }

  const payload = {
    payment_method:       'bacs',
    payment_method_title: 'Bank Transfer',
    set_paid:             false,
    billing: {
      first_name: firstName,
      last_name:  lastName,
      email:      customerEmail,
    },
    line_items: [lineItem],
    meta_data: [
      { key: 'design_id',        value: designId },
      { key: 'geometry_summary', value: geometrySummary },
    ],
    customer_note: `Bespoke bike design — ID: ${designId}`,
  };

  const order = await wcFetch('/orders', 'POST', payload);

  // WooCommerce returns payment_url in REST API responses
  const checkoutUrl = order.payment_url ||
    `${WC_URL}/checkout/order-pay/${order.id}/?pay_for_order=true&key=${order.order_key}`;

  return {
    wcOrderId: String(order.id),
    checkoutUrl,
  };
}

/**
 * Given a WooCommerce order ID, return the design_id stored in meta_data.
 *
 * @param {string|number} wcOrderId
 * @returns {string|null} designId
 */
async function getDesignIdFromOrder(wcOrderId) {
  if (!isConfigured()) return null;
  try {
    const order = await wcFetch(`/orders/${wcOrderId}`);
    const meta  = order.meta_data || [];
    const entry = meta.find(m => m.key === 'design_id');
    return entry ? entry.value : null;
  } catch (err) {
    console.error('woocommerce.getDesignIdFromOrder error:', err.message);
    return null;
  }
}

module.exports = { isConfigured, createOrder, getDesignIdFromOrder };
