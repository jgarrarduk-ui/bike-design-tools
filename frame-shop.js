// Frame Designer → tools-api checkout.
// Classic script (window.FrameShop) and a CommonJS export for node tests.
// Product ids are the public Woo catalogue. No credentials live here.
(function (root, factory) {
  const api = factory();
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.FrameShop = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const ASSEMBLY_ID = 8637;
  const SESSION_KEY = 'creature.frameDesign';

  // BB yoke, SS yoke, dropouts, and the optional assembled rear end.
  // The assembled rear end replaces the three parts; it is not added to them.
  const PARTS = [
    { id: 8634, name: 'BB yoke' },
    { id: 8635, name: 'SS yoke' },
    { id: 8636, name: 'Dropouts' },
    { id: 8637, name: 'Whole rear end', optional: true },
  ];

  function defaultProductIds() {
    return PARTS.filter(part => !part.optional).map(part => part.id);
  }

  function knownIds(ids) {
    const known = new Set(PARTS.map(part => part.id));
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

  // Checked ids after a toggle. Whole rear end (8637) is exclusive.
  function applyAssemblyExclusivity(checkedIds, changedId) {
    const checked = knownIds(checkedIds);
    const changed = Number(changedId);
    if (changed === ASSEMBLY_ID && checked.includes(ASSEMBLY_ID)) return [ASSEMBLY_ID];
    return checked.filter(id => id !== ASSEMBLY_ID);
  }

  // Ids to send as productIds. An assembled rear end is the whole selection.
  function productIdsFromSelection(selectedIds) {
    const ids = knownIds(selectedIds);
    if (ids.includes(ASSEMBLY_ID)) return [ASSEMBLY_ID];
    return ids;
  }

  function buildDesignPayload({ customerName, customerEmail, params, productIds }) {
    const ids = productIdsFromSelection(productIds);
    if (!ids.length) {
      const err = new Error('Choose at least one part.');
      err.status = 400;
      throw err;
    }
    if (!params || typeof params !== 'object') {
      const err = new Error('Frame geometry is missing.');
      err.status = 400;
      throw err;
    }
    return {
      customerName: String(customerName || '').trim(),
      customerEmail: String(customerEmail || '').trim(),
      params,
      productIds: ids,
    };
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
  function checkoutPlan({ saved, snapshot, productIds }) {
    const ids = productIdsFromSelection(productIds);
    if (!ids.length) return { action: 'error', message: 'Choose at least one part.' };
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
    ASSEMBLY_ID,
    SESSION_KEY,
    PARTS,
    defaultProductIds,
    applyAssemblyExclusivity,
    productIdsFromSelection,
    buildDesignPayload,
    selectionSnapshot,
    designIdFromResponse,
    isOrderPayUrl,
    checkoutPlan,
    writeSession,
    readSession,
    apiUrl,
    postDesign,
  };
});
