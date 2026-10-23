'use strict';

/**
 * Live Frame Designer prices.
 *
 * Order creation with an explicit productIds list does not send line
 * subtotal or total (WC_PRODUCT_PRICE applies only when productIds is
 * omitted). WooCommerce REST prepare_line_items() then sets both from
 * wc_get_price_excluding_tax(), which reads the product `price` field
 * (WC_Product::get_price(): the sale price when one is active, otherwise
 * the regular price). save_object() calls calculate_totals(), which adds
 * tax. A tools-api order has no billing country, so that tax is the shop
 * base rate.
 *
 * The amount charged for one unit is therefore:
 * - the stored `price`, when taxes are off or the product is not taxable
 * - the stored `price`, when catalogue prices already include tax (Woo
 *   strips base tax from `price`, then calculate_totals() adds that same
 *   base tax back)
 * - `price` plus base-rate tax, when catalogue prices exclude tax and the
 *   product is taxable
 *
 * Rounding follows Woo: tax is rounded to max(price decimals + 2, 6), each
 * tax is then formatted to the shop's price decimals, and each ex-tax line
 * is rounded to those decimals before the tax is added (unless
 * woocommerce_tax_round_at_subtotal is yes). The figure returned here is
 * that charged unit amount, not the ex-tax line total.
 *
 * Frame Designer product ids only. 8637 is not a picker product. Nothing
 * here writes to WooCommerce. A missing price is omitted, never filled in.
 */

const { wcGet } = require('./woocommerce');

const FD_PRODUCT_IDS = [8634, 8635, 8636];
const TTL_MS = 5 * 60 * 1000;
const STALE_MS = 60 * 60 * 1000;
const ROUNDING_PRECISION_FLOOR = 6;

let cache = null;
let inflight = null;
let nowFn = () => Date.now();

function setting(list, id) {
  const row = (list || []).find(item => item && item.id === id);
  if (!row || row.value == null) return null;
  return String(row.value).trim();
}

function requireSetting(list, id) {
  const value = setting(list, id);
  if (!value) {
    throw new Error(`WooCommerce setting ${id} is missing.`);
  }
  return value;
}

function requireYesNo(list, id) {
  const value = requireSetting(list, id).toLowerCase();
  if (value !== 'yes' && value !== 'no') {
    throw new Error(`WooCommerce setting ${id} is not yes or no.`);
  }
  return value;
}

function priceDecimals(general) {
  const raw = setting(general, 'woocommerce_price_num_decimals');
  if (raw == null || raw === '') return 2;
  const n = Number(raw);
  if (!Number.isInteger(n) || n < 0 || n > 6) {
    throw new Error('WooCommerce price decimals are not usable.');
  }
  return n;
}

function roundHalfUp(value, decimals) {
  if (!Number.isFinite(value)) return NaN;
  const sign = value < 0 ? -1 : 1;
  const abs = Math.abs(value);
  const moved = Number(`${abs}e${decimals}`);
  if (!Number.isFinite(moved)) return NaN;
  return sign * Number(`${Math.round(moved)}e-${decimals}`);
}

function formatMoney(amount, decimals) {
  return roundHalfUp(amount, decimals).toFixed(decimals);
}

function normClass(value) {
  const text = String(value || '').trim().toLowerCase();
  if (!text || text === 'standard') return '';
  return text;
}

function taxStatus(product) {
  const status = String(product && product.tax_status || '').trim().toLowerCase();
  return status || 'taxable';
}

function locationList(rate, singular, plural) {
  const many = rate && rate[plural];
  if (Array.isArray(many) && many.length) {
    return many.map(value => String(value).trim().toUpperCase()).filter(value => value && value !== '*');
  }
  const one = rate && rate[singular];
  if (Array.isArray(one)) {
    return one.map(value => String(value).trim().toUpperCase()).filter(value => value && value !== '*');
  }
  if (typeof one === 'string' && one.trim()) {
    return one.split(/[;,]/).map(value => value.trim().toUpperCase()).filter(value => value && value !== '*');
  }
  return [];
}

function rateMatches(rate, base, taxClass) {
  if (normClass(rate && rate.class) !== taxClass) return false;
  const country = String(rate.country || '').trim().toUpperCase();
  if (country && country !== '*' && country !== base.country) return false;
  const state = String(rate.state || '').trim().toUpperCase();
  if (state && state !== '*' && state !== base.state) return false;
  const postcodes = locationList(rate, 'postcode', 'postcodes');
  if (postcodes.length && (!base.postcode || !postcodes.includes(base.postcode))) return false;
  const cities = locationList(rate, 'city', 'cities');
  if (cities.length && (!base.city || !cities.includes(base.city))) return false;
  return true;
}

function normaliseRate(rate, index) {
  const percent = Number(rate && rate.rate);
  if (!Number.isFinite(percent)) return null;
  const compound = rate.compound === true || rate.compound === 'yes' || rate.compound === 1 || rate.compound === '1';
  return {
    id: rate.id != null ? rate.id : index,
    rate: percent,
    compound,
  };
}

function ratesFor(product, ctx) {
  const taxClass = normClass(product && product.tax_class);
  const matched = [];
  (ctx.rates || []).forEach((rate, index) => {
    if (!rateMatches(rate, ctx.base, taxClass)) return;
    const normalised = normaliseRate(rate, index);
    if (normalised) matched.push(normalised);
  });
  return matched;
}

function calcInclusiveTax(price, rates, precision) {
  const taxes = new Map();
  const compound = [];
  const regular = [];
  for (const rate of rates) {
    taxes.set(rate.id, 0);
    if (rate.compound) compound.push(rate);
    else regular.push(rate);
  }
  let nonCompoundPrice = price;
  for (const rate of compound.slice().reverse()) {
    const taxAmount = nonCompoundPrice - (nonCompoundPrice / (1 + (rate.rate / 100)));
    taxes.set(rate.id, taxes.get(rate.id) + taxAmount);
    nonCompoundPrice -= taxAmount;
  }
  const regularTaxRate = 1 + regular.reduce((sum, rate) => sum + rate.rate, 0) / 100;
  for (const rate of regular) {
    const theRate = regularTaxRate === 0 ? 0 : (rate.rate / 100) / regularTaxRate;
    taxes.set(rate.id, taxes.get(rate.id) + (theRate * nonCompoundPrice));
  }
  return [...taxes.values()].map(value => roundHalfUp(value, precision));
}

function calcExclusiveTax(price, rates, precision) {
  const regular = [];
  let preCompound = 0;
  for (const rate of rates) {
    if (rate.compound) continue;
    const taxAmount = price * (rate.rate / 100);
    regular.push(taxAmount);
    preCompound += taxAmount;
  }
  const compound = [];
  for (const rate of rates) {
    if (!rate.compound) continue;
    const taxAmount = (price + preCompound) * (rate.rate / 100);
    compound.push(taxAmount);
    preCompound += taxAmount;
  }
  return [...regular, ...compound].map(value => roundHalfUp(value, precision));
}

function roundedOrderTax(lineEx, rates, ctx) {
  const precise = calcExclusiveTax(lineEx, rates, ctx.roundingPrecision);
  return precise.reduce((sum, value) => sum + roundHalfUp(value, ctx.decimals), 0);
}

function parsePrice(raw) {
  if (typeof raw === 'number') {
    if (!Number.isFinite(raw) || raw < 0) return null;
    return raw;
  }
  if (typeof raw !== 'string') return null;
  const text = raw.trim();
  if (!/^\d+(\.\d+)?$/.test(text)) return null;
  const value = Number(text);
  return Number.isFinite(value) ? value : null;
}

/**
 * Charged unit amount for one Frame Designer line, as a decimal string.
 * null when Woo did not provide a price.
 *
 * @param {object} product
 * @param {object} ctx
 * @returns {string|null}
 */
function chargedUnitPrice(product, ctx) {
  const price = parsePrice(product && product.price);
  if (price == null) return null;
  const taxable = ctx.calcTaxes && taxStatus(product) === 'taxable';
  if (!taxable) return formatMoney(price, ctx.decimals);

  const rates = ratesFor(product, ctx);
  let lineEx = price;
  if (ctx.pricesIncludeTax) {
    const removed = calcInclusiveTax(price, rates, ctx.roundingPrecision);
    lineEx = price - removed.reduce((sum, value) => sum + value, 0);
  }
  const tax = roundedOrderTax(lineEx, rates, ctx);
  const lineRounded = ctx.roundAtSubtotal ? lineEx : roundHalfUp(lineEx, ctx.decimals);
  return formatMoney(lineRounded + tax, ctx.decimals);
}

function baseLocation(general) {
  const raw = setting(general, 'woocommerce_default_country') || '';
  const [country, state] = raw.split(':');
  return {
    country: String(country || '').trim().toUpperCase(),
    state: String(state || '').trim().toUpperCase(),
    postcode: String(setting(general, 'woocommerce_store_postcode') || '').trim().toUpperCase(),
    city: String(setting(general, 'woocommerce_store_city') || '').trim().toUpperCase(),
  };
}

function productsPath() {
  const params = new URLSearchParams();
  params.set('include', FD_PRODUCT_IDS.join(','));
  params.set('per_page', '10');
  params.set('status', 'any');
  params.set('_fields', 'id,name,price,tax_status,tax_class,status');
  return `/products?${params.toString()}`;
}

function catalogueFrom(products, ctx) {
  const byId = new Map();
  for (const product of products || []) {
    const id = Number(product && product.id);
    if (!FD_PRODUCT_IDS.includes(id) || byId.has(id)) continue;
    byId.set(id, product);
  }
  const out = {};
  for (const id of FD_PRODUCT_IDS) {
    const product = byId.get(id);
    const key = String(id);
    if (!product || String(product.status || '').toLowerCase() === 'trash') {
      out[key] = { available: false };
      continue;
    }
    const name = typeof product.name === 'string' ? product.name : '';
    const price = chargedUnitPrice(product, ctx);
    if (price == null) {
      out[key] = name ? { name, available: false } : { available: false };
      continue;
    }
    out[key] = name
      ? { price, name, available: true }
      : { price, available: true };
  }
  return out;
}

async function fetchFromWoo() {
  const products = await wcGet(productsPath());
  if (!Array.isArray(products)) throw new Error('WooCommerce products response was not a list.');
  const general = await wcGet('/settings/general');
  if (!Array.isArray(general)) throw new Error('WooCommerce general settings response was not a list.');

  const ctx = {
    currency: requireSetting(general, 'woocommerce_currency'),
    calcTaxes: requireYesNo(general, 'woocommerce_calc_taxes') === 'yes',
    decimals: priceDecimals(general),
    pricesIncludeTax: false,
    roundAtSubtotal: false,
    base: null,
    rates: [],
  };
  ctx.roundingPrecision = Math.max(ctx.decimals + 2, ROUNDING_PRECISION_FLOOR);

  if (ctx.calcTaxes) {
    const taxSettings = await wcGet('/settings/tax');
    if (!Array.isArray(taxSettings)) throw new Error('WooCommerce tax settings response was not a list.');
    ctx.pricesIncludeTax = requireYesNo(taxSettings, 'woocommerce_prices_include_tax') === 'yes';
    ctx.roundAtSubtotal = setting(taxSettings, 'woocommerce_tax_round_at_subtotal') === 'yes';
    ctx.base = baseLocation(general);
    if (!ctx.base.country) throw new Error('WooCommerce base country is missing.');
    const rates = await wcGet('/taxes?per_page=100');
    if (!Array.isArray(rates)) throw new Error('WooCommerce tax rates response was not a list.');
    ctx.rates = rates;
  }

  return {
    currency: ctx.currency,
    products: catalogueFrom(products, ctx),
  };
}

async function refresh(now) {
  try {
    const body = await fetchFromWoo();
    body.fetched_at = new Date(now).toISOString();
    cache = { body, fetchedAt: now };
    return { status: 200, body };
  } catch (err) {
    console.error('[prices]', err && err.message ? err.message : err);
    if (cache && now - cache.fetchedAt < STALE_MS) {
      return { status: 200, body: cache.body };
    }
    return { status: 503, body: { error: 'prices_unavailable' } };
  }
}

function getPrices() {
  const now = nowFn();
  if (cache && now - cache.fetchedAt < TTL_MS) {
    return Promise.resolve({ status: 200, body: cache.body });
  }
  if (!inflight) {
    const pending = refresh(now).finally(() => {
      if (inflight === pending) inflight = null;
    });
    inflight = pending;
  }
  return inflight;
}

function resetForTests() {
  cache = null;
  inflight = null;
  nowFn = () => Date.now();
}

function setNowForTests(fn) {
  nowFn = typeof fn === 'function' ? fn : () => Date.now();
}

module.exports = {
  FD_PRODUCT_IDS,
  TTL_MS,
  STALE_MS,
  getPrices,
  resetForTests,
  setNowForTests,
};
