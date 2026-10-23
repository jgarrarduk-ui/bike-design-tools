// Frame Designer → tools-api checkout.
// Classic script (window.FrameShop) and a CommonJS export for node tests.
// Product ids are the public Woo catalogue. No credentials live here.
(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.FrameShop = api;
  if (api && typeof api.paintShopLeadTime === 'function') api.paintShopLeadTime();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const SESSION_KEY = 'creature.frameDesign';

  // Same phrase as the WordPress creature_fd_lead_time filter and FD_LEAD_TIME.
  // This file cannot read either of those. Keep the three copies in step.
  const FD_LEAD_TIME = '5 working days';

  function designFileDeliverySentence() {
    return 'Design files are delivered within ' + FD_LEAD_TIME + ' of payment.';
  }

  function paintShopLeadTime(doc) {
    const root = doc || (typeof document !== 'undefined' ? document : null);
    if (!root || typeof root.getElementById !== 'function') return;
    const el = root.getElementById('shop-lead-time');
    if (el) el.textContent = designFileDeliverySentence();
  }

  // Dropouts stay on the picker but are not sold, and are never priced here.
  // Live amounts come from GET /api/prices. Nothing in this file is a price
  // the page may show before that response arrives. Printed 316L is enquire
  // only, not a product. Checkout still sends product ids, not prices.
  const PARTS = [
    { id: 8634, name: 'BB yoke' },
    { id: 8635, name: 'SS yoke' },
    { id: 8636, name: 'Dropouts', unavailable: true },
  ];

  // null until a live catalogue is applied. Never a hard-coded price list.
  let currentCatalog = null;

  function orderableParts() {
    return PARTS.filter(part => !part.unavailable);
  }

  function defaultProductIds() {
    return orderableParts().map(part => part.id);
  }

  // Rows for the Save design picker. Omit selectedIds to tick the buyable
  // defaults. Pass an array (including []) to keep that selection. Dropouts
  // stay visible, disabled, and unchecked.
  const PARTS_LOCK_NOTE = 'These parts are the ones you paid for, so they stay as they are. You can still change the geometry.';
  const CHANGE_CLOSED_MESSAGE = 'The change window has closed. Reply to your confirmation email and we\'ll help.';

  function pickerRows(selectedIds, options) {
    const lockParts = !!(options && options.lockParts);
    const priceOverride = options && Object.prototype.hasOwnProperty.call(options, 'prices')
      ? options.prices
      : undefined;
    const catalog = resolveCatalog(priceOverride);
    // A paid amend keeps the parts already on the order. Availability gates
    // a new purchase only, so a later draft or out-of-stock status does not
    // untick them.
    const chosen = selectedIds == null
      ? defaultProductIds()
      : (lockParts ? knownIds(selectedIds) : productIdsFromSelection(selectedIds, priceOverride));
    const selected = new Set(chosen);
    return PARTS.map(part => {
      const comingSoon = !!part.unavailable;
      const blocked = !comingSoon && !lockParts && isBlocked(part.id, catalog);
      return {
        id: part.id,
        name: part.name,
        price: comingSoon || blocked ? null : amountFor(part.id, catalog),
        checked: !comingSoon && !blocked && selected.has(part.id),
        disabled: comingSoon || blocked || lockParts,
        comingSoon,
      };
    });
  }

  function knownIds(ids) {
    const known = new Set(orderableParts().map(part => part.id));
    const out = [];
    const seen = new Set();
    for (const raw of ids || []) {
      const n = Number(raw);
      if (!known.has(n) || seen.has(n)) continue;
      seen.add(n);
      out.push(n);
    }
    return out;
  }

  function productIdsFromSelection(selectedIds, priceMap, options) {
    const catalog = resolveCatalog(priceMap);
    const keepBlocked = !!(options && options.keepBlocked);
    return knownIds(selectedIds).filter(id => keepBlocked || !isBlocked(id, catalog));
  }

  function formatGbp(amount) {
    if (amount == null || amount === '') return '';
    const n = Number(amount);
    if (!Number.isFinite(n) || n < 0) return '';
    const cents = Math.round(n * 100);
    const pounds = Math.trunc(cents / 100);
    const pence = Math.abs(cents % 100);
    if (pence === 0) return '£' + pounds;
    return '£' + pounds + '.' + String(pence).padStart(2, '0');
  }

  // A price map is the GET /api/prices body, or `{ id: { price, available } }`.
  // null means prices are unknown. undefined means the catalogue last applied.
  function resolveCatalog(priceMap) {
    if (priceMap === null) return null;
    if (priceMap !== undefined) return normalizeCatalog(priceMap);
    return currentCatalog;
  }

  function normalizeCatalog(input) {
    if (!input || typeof input !== 'object') return null;
    const currency = typeof input.currency === 'string' ? input.currency.trim() : '';
    if (currency && currency !== 'GBP') return { currency, products: {}, blocked: new Set() };
    const source = input.products && typeof input.products === 'object' ? input.products : input;
    const products = {};
    const blocked = new Set();
    for (const part of orderableParts()) {
      const raw = source[part.id] != null ? source[part.id] : source[String(part.id)];
      if (!raw || typeof raw !== 'object') continue;
      // Off sale still keeps a price when one was sent, so an amend of
      // parts already paid for can total them. A new purchase ignores it.
      if (raw.available === false) blocked.add(part.id);
      if (raw.price == null || raw.price === '') continue;
      const price = Number(raw.price);
      if (!Number.isFinite(price) || price < 0) continue;
      products[part.id] = price;
    }
    return { currency: currency || 'GBP', products, blocked };
  }

  function isBlocked(id, catalog) {
    return !!(catalog && catalog.blocked && catalog.blocked.has(id));
  }

  function amountFor(id, catalog) {
    if (!catalog || !catalog.products) return null;
    const price = catalog.products[id];
    return price == null ? null : price;
  }

  function applyPrices(input) {
    currentCatalog = input == null ? null : normalizeCatalog(input);
    return currentCatalog;
  }

  function resetPrices() {
    currentCatalog = null;
  }

  function listTotal(selectedIds, priceMap, options) {
    const ids = productIdsFromSelection(selectedIds, priceMap, options);
    if (!ids.length) return 0;
    const catalog = resolveCatalog(priceMap);
    let cents = 0;
    for (const id of ids) {
      const price = amountFor(id, catalog);
      if (price == null) return null;
      cents += Math.round(price * 100);
    }
    return cents / 100;
  }

  // Sum of the live prices for the buyable selection. null when any of those
  // prices is missing, so the page cannot fall back to a made-up figure.
  // The rear-end discount is a Woo fee, not a total computed here.
  function selectionTotal(selectedIds, priceMap, options) {
    return listTotal(selectedIds, priceMap, options);
  }

  function partLabel(part, priceMap) {
    if (!part) return '';
    if (part.unavailable || part.comingSoon) return part.name;
    const catalog = resolveCatalog(priceMap);
    if (isBlocked(part.id, catalog)) return part.name;
    const price = amountFor(part.id, catalog);
    if (price == null) return part.name;
    return part.name + ' ' + formatGbp(price);
  }

  // The £24 whole-rear-end fee is applied by WooCommerce, not as a picker saving.
  function selectionSaving(_selectedIds, _priceMap) {
    return '';
  }

  function selectionLabel(selectedIds, priceMap, options) {
    const ids = productIdsFromSelection(selectedIds, priceMap, options);
    if (!ids.length) return 'Choose at least one design file.';
    const total = selectionTotal(ids, priceMap, options);
    if (total == null) return 'Price shown at checkout';
    if (ids.length === 1) return partLabel(PARTS.find(part => part.id === ids[0]), priceMap);
    return 'Selected design files ' + formatGbp(total);
  }

  function pricesUrl(apiBase) {
    const base = String(apiBase || '').trim().replace(/\/$/, '');
    if (!/^https?:\/\//i.test(base)) {
      const err = new Error('Tools API is not configured.');
      err.status = 400;
      throw err;
    }
    return base + '/api/prices';
  }

  // DOM-free. The page paints labels after this resolves. A failed fetch
  // leaves the previous live catalogue in place, or none if there wasn't one.
  async function fetchPrices(apiBase, fetchImpl) {
    let url;
    try { url = pricesUrl(apiBase); }
    catch { return null; }
    const doFetch = fetchImpl || (typeof fetch === 'function' ? fetch : null);
    if (!doFetch) return null;
    let res;
    try {
      res = await doFetch(url, {
        method: 'GET',
        headers: { accept: 'application/json' },
      });
    } catch {
      return null;
    }
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    if (!res || !res.ok || !data || typeof data !== 'object' || !data.products) return null;
    applyPrices(data);
    return data;
  }

  function buildDesignPayload({ customerName, customerEmail, params, productIds, designName }) {
    const ids = productIdsFromSelection(productIds);
    if (!ids.length) {
      const err = new Error('Choose at least one design file.');
      err.status = 400;
      throw err;
    }
    if (!params || typeof params !== 'object') {
      const err = new Error('Frame geometry is missing.');
      err.status = 400;
      throw err;
    }
    const payload = {
      customerName: String(customerName || '').trim(),
      customerEmail: String(customerEmail || '').trim(),
      params,
      productIds: ids,
    };
    const name = String(designName || '').trim();
    if (name) payload.designName = name;
    return payload;
  }

  function selectionSnapshot(params, productIds, email) {
    const ids = productIdsFromSelection(productIds).slice().sort((a, b) => a - b);
    return JSON.stringify({
      params,
      productIds: ids,
      email: String(email || '').trim().toLowerCase(),
    });
  }

  function designIdFromResponse(body) {
    if (!body || typeof body !== 'object') return '';
    const id = body.design_id || body.designId || '';
    return typeof id === 'string' ? id.trim() : '';
  }

  // Only follow a Woo order-pay URL on the store, or a local placeholder.
  function isOrderPayUrl(url) {
    let parsed;
    try { parsed = new URL(url); } catch { return false; }
    const host = parsed.hostname;
    const local = host === 'localhost' || host === '127.0.0.1';
    const store = host === 'creaturecycles.co.uk' || host.endsWith('.creaturecycles.co.uk');
    if (parsed.protocol === 'https:' && store && parsed.pathname.includes('/checkout/order-pay/')) return true;
    if (parsed.protocol === 'http:' && local && (
      parsed.pathname.includes('/checkout/order-pay/') ||
      parsed.pathname.includes('/order-confirmation')
    )) return true;
    return false;
  }

  // Reuse a saved order when the geometry, email, and parts have not changed.
  // A change posts a new design, which creates a new pending order. The previous
  // unpaid order is left for the 90-day cleanup.
  function checkoutPlan({ saved, snapshot, productIds }) {
    const ids = productIdsFromSelection(productIds);
    if (!ids.length) return { action: 'error', message: 'Choose at least one design file.' };
    if (
      saved &&
      saved.designId &&
      saved.snapshot === snapshot &&
      isOrderPayUrl(saved.checkoutUrl)
    ) {
      return {
        action: 'redirect',
        designId: saved.designId,
        checkoutUrl: saved.checkoutUrl,
        productIds: ids,
      };
    }
    return { action: 'post', productIds: ids };
  }

  function writeSession(storage, record) {
    storage.setItem(SESSION_KEY, JSON.stringify(record));
  }

  function readSession(storage, urlDesignId) {
    let data;
    try { data = JSON.parse(storage.getItem(SESSION_KEY) || 'null'); }
    catch { return null; }
    if (!data || typeof data.designId !== 'string' || !data.designId) return null;
    if (urlDesignId && urlDesignId !== data.designId) return null;
    return data;
  }

  function apiUrl(apiBase) {
    const base = String(apiBase || '').trim().replace(/\/$/, '');
    if (!/^https?:\/\//i.test(base)) {
      const err = new Error('Tools API is not configured.');
      err.status = 400;
      throw err;
    }
    return base + '/api/designs';
  }

  function hydrateUrl(apiBase, designId, resumeToken) {
    const url = new URL(apiUrl(apiBase) + '/' + encodeURIComponent(String(designId || '').trim()));
    url.searchParams.set('resume', String(resumeToken || ''));
    return url.toString();
  }

  // Session record from a resume hydrate. Dropouts stay unsellable here too.
  function sessionFromHydrate(data) {
    if (!data || typeof data !== 'object') {
      const err = new Error('This design link has expired or is not valid.');
      err.status = 404;
      throw err;
    }
    const designId = typeof data.designId === 'string' ? data.designId.trim() : '';
    if (!designId) {
      const err = new Error('This design link has expired or is not valid.');
      err.status = 404;
      throw err;
    }
    const params = data.params && typeof data.params === 'object' ? data.params : {};
    const productIds = productIdsFromSelection(data.productIds);
    const customerEmail = String(data.customerEmail || '').trim();
    const customerName = String(data.customerName || '').trim();
    const checkoutUrl = typeof data.checkoutUrl === 'string' ? data.checkoutUrl : '';
    return {
      designId,
      checkoutUrl,
      snapshot: selectionSnapshot(params, productIds, customerEmail),
      customerName,
      customerEmail,
      productIds,
      params,
    };
  }

  async function fetchHydratedDesign(apiBase, designId, resumeToken, fetchImpl) {
    const url = hydrateUrl(apiBase, designId, resumeToken);
    const doFetch = fetchImpl || fetch;
    let res;
    try {
      res = await doFetch(url, {
        method: 'GET',
        headers: { accept: 'application/json' },
      });
    } catch {
      throw new Error('Could not reach the tools API.');
    }
    let data = {};
    try { data = await res.json(); } catch { data = {}; }
    if (!res.ok || !data || data.designId !== String(designId || '').trim()) {
      const err = new Error('This design link has expired or is not valid.');
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function changeUrl(apiBase, designId, changeToken) {
    const url = new URL(apiUrl(apiBase) + '/' + encodeURIComponent(String(designId || '').trim()));
    url.searchParams.set('change', String(changeToken || ''));
    return url.toString();
  }

  function sessionFromChange(data) {
    const session = sessionFromHydrate({
      designId: data && data.designId,
      params: data && data.params,
      productIds: data && data.productIds,
      customerName: data && data.customerName,
      customerEmail: data && data.customerEmail,
      checkoutUrl: '',
    });
    session.mode = 'amend';
    session.checkoutUrl = '';
    session.revision = Number(data && data.revision) || 0;
    session.lockedProductIds = session.productIds.slice();
    return session;
  }

  async function fetchChangeDesign(apiBase, designId, changeToken, fetchImpl) {
    const url = changeUrl(apiBase, designId, changeToken);
    const doFetch = fetchImpl || fetch;
    let res;
    try {
      res = await doFetch(url, { method: 'GET', headers: { accept: 'application/json' } });
    } catch {
      throw new Error('Could not reach the tools API.');
    }
    let data = {};
    try { data = await res.json(); } catch { data = {}; }
    if (res.status === 410 || (data && data.error === 'closed')) {
      const err = new Error(CHANGE_CLOSED_MESSAGE);
      err.closed = true;
      err.status = 410;
      throw err;
    }
    const id = String(designId || '').trim();
    if (!res.ok || !data || data.mode !== 'amend' || data.designId !== id) {
      const err = new Error('This design link has expired or is not valid.');
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function revisionPlan({ lockedIds, productIds, params, savedParams }) {
    // Paid ids skip the catalogue filter. Adding or removing a part is still
    // rejected: the two lists have to match.
    const locked = knownIds(lockedIds).slice().sort((a, b) => a - b);
    const next = knownIds(productIds).slice().sort((a, b) => a - b);
    if (!locked.length || locked.join(',') !== next.join(',')) {
      return { action: 'error', message: 'Those parts can\'t be changed on this order.' };
    }
    if (savedParams && JSON.stringify(savedParams) === JSON.stringify(params)) {
      return { action: 'unchanged', productIds: locked };
    }
    return { action: 'post', productIds: locked };
  }

  async function postRevision(apiBase, designId, payload, fetchImpl) {
    const url = apiUrl(apiBase) + '/' + encodeURIComponent(String(designId || '').trim()) + '/revision';
    const doFetch = fetchImpl || fetch;
    let res;
    try {
      res = await doFetch(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json', accept: 'application/json' },
        body: JSON.stringify({
          change: payload && payload.change,
          params: payload && payload.params,
          productIds: payload && payload.productIds,
        }),
      });
    } catch {
      throw new Error('Could not reach the tools API.');
    }
    let data = {};
    try { data = await res.json(); } catch { data = {}; }
    if (res.status === 410 || (data && data.error === 'closed')) {
      const err = new Error(CHANGE_CLOSED_MESSAGE);
      err.closed = true;
      err.status = 410;
      throw err;
    }
    if (!res.ok) {
      const message = data && typeof data.message === 'string' && data.message
        ? data.message
        : (data && typeof data.error === 'string' && data.error ? data.error : 'Could not save the changes.');
      const err = new Error(message);
      err.status = res.status;
      throw err;
    }
    return data;
  }

  async function postDesign(apiBase, payload, fetchImpl) {
    const url = apiUrl(apiBase);
    const doFetch = fetchImpl || fetch;
    let res;
    try {
      res = await doFetch(url, {
        method: 'POST',
        headers: {
          'content-type': 'application/json',
          'accept': 'application/json',
        },
        body: JSON.stringify(payload),
      });
    } catch {
      throw new Error('Could not reach the tools API.');
    }
    let data = {};
    try { data = await res.json(); } catch { data = {}; }
    if (!res.ok) {
      const message = data && typeof data.error === 'string' && data.error
        ? data.error
        : 'Could not save the design.';
      const err = new Error(message);
      err.status = res.status;
      throw err;
    }
    return data;
  }

  return {
    SESSION_KEY,
    PARTS,
    defaultProductIds,
    pickerRows,
    formatGbp,
    listTotal,
    selectionTotal,
    partLabel,
    selectionSaving,
    selectionLabel,
    applyPrices,
    resetPrices,
    pricesUrl,
    fetchPrices,
    productIdsFromSelection,
    buildDesignPayload,
    selectionSnapshot,
    designIdFromResponse,
    isOrderPayUrl,
    checkoutPlan,
    writeSession,
    readSession,
    apiUrl,
    hydrateUrl,
    sessionFromHydrate,
    fetchHydratedDesign,
    PARTS_LOCK_NOTE,
    CHANGE_CLOSED_MESSAGE,
    changeUrl,
    sessionFromChange,
    fetchChangeDesign,
    revisionPlan,
    postRevision,
    postDesign,
    FD_LEAD_TIME,
    designFileDeliverySentence,
    paintShopLeadTime,
  };
});
