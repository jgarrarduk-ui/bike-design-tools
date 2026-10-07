// Frame Designer → tools-api checkout.
// Classic script (window.FrameShop) and a CommonJS export for node tests.
// Product ids are the public Woo catalogue. No credentials live here.
(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.FrameShop = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const SESSION_KEY = 'creature.frameDesign';

  // Design-file list prices locked 7 Oct 2026.
  // Dropouts stay on the picker but are not sold. The full-set total is hidden
  // until every part is buyable. Printed 316L is enquire only, not a product.
  const TRIO_PRICE = 128;
  const PARTS = [
    { id: 8634, name: 'BB yoke', price: 58 },
    { id: 8635, name: 'SS yoke', price: 42 },
    { id: 8636, name: 'Dropouts', price: 64, unavailable: true },
  ];

  function orderableParts() {
    return PARTS.filter(part => !part.unavailable);
  }

  function defaultProductIds() {
    return orderableParts().map(part => part.id);
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

  function productIdsFromSelection(selectedIds) {
    return knownIds(selectedIds);
  }

  function formatGbp(amount) {
    return '£' + amount;
  }

  function listTotal(selectedIds) {
    const selected = new Set(productIdsFromSelection(selectedIds));
    return PARTS.reduce((sum, part) => sum + (selected.has(part.id) ? part.price : 0), 0);
  }

  // The full-set price is withheld while any row is coming soon.
  function isFullSet(selectedIds) {
    if (PARTS.some(part => part.unavailable)) return false;
    const ids = new Set(productIdsFromSelection(selectedIds));
    return PARTS.every(part => ids.has(part.id));
  }

  // Buyable files show their list sum. A full set is not offered while dropouts are unavailable.
  function selectionTotal(selectedIds) {
    if (isFullSet(selectedIds)) return TRIO_PRICE;
    return listTotal(selectedIds);
  }

  function partLabel(part) {
    return part.name + ' ' + formatGbp(part.price);
  }

  function selectionSaving(selectedIds) {
    if (!isFullSet(selectedIds)) return '';
    const list = listTotal(selectedIds);
    return 'List ' + formatGbp(list) + ', save ' + formatGbp(list - TRIO_PRICE);
  }

  function selectionLabel(selectedIds) {
    const ids = productIdsFromSelection(selectedIds);
    if (!ids.length) return 'Choose at least one design file.';
    const total = formatGbp(selectionTotal(ids));
    if (isFullSet(ids)) return 'Three design files ' + total;
    if (ids.length === 1) return partLabel(PARTS.find(part => part.id === ids[0]));
    return 'Selected design files ' + total;
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
    TRIO_PRICE,
    formatGbp,
    listTotal,
    selectionTotal,
    partLabel,
    selectionSaving,
    selectionLabel,
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
    postDesign,
  };
});
