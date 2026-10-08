'use strict';

/**
 * Paid Frame Designer changes.
 *
 * The change token is minted on the Woo order (see creature-fd-order-experience.php)
 * because the customer email is sent by Woo at payment, and the window is the
 * paid date plus the shop's filter, or the In design status. This process stores
 * the geometry that the design files are built from, including each revision.
 *
 * GET  /api/designs/:id?change=
 * POST /api/designs/:id/revision   { change, params, productIds }
 */

const crypto = require('crypto');
const db = require('../db');
const woocommerce = require('./woocommerce');
const email = require('./email');

const CLOSED_MESSAGE = 'The change window has closed. Reply to your confirmation email and we\'ll help.';

function parseJson(raw, fallback) {
  try {
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
}

function stable(value) {
  if (Array.isArray(value)) return value.map(stable);
  if (value && typeof value === 'object') {
    const out = {};
    for (const key of Object.keys(value).sort()) out[key] = stable(value[key]);
    return out;
  }
  return value;
}

function sameParams(left, right) {
  return JSON.stringify(stable(left)) === JSON.stringify(stable(right));
}

function idempotencyKey(params) {
  return crypto.createHash('sha256').update(JSON.stringify(stable(params))).digest('hex');
}

function normalizeIds(ids) {
  const out = [];
  const seen = new Set();
  for (const raw of ids || []) {
    const n = Number(raw);
    if (!Number.isSafeInteger(n) || n <= 0 || seen.has(n)) continue;
    seen.add(n);
    out.push(n);
  }
  out.sort((a, b) => a - b);
  return out;
}

function sameIds(left, right) {
  const a = normalizeIds(left);
  const b = normalizeIds(right);
  return a.length === b.length && a.every((n, i) => n === b[i]);
}

function closed(res) {
  return res.status(410).json({ error: 'closed', message: CLOSED_MESSAGE });
}

function invalid(res) {
  return res.status(404).json({ error: 'invalid', message: 'This link is not valid.' });
}

function loadDesign(designId) {
  if (!designId || typeof designId !== 'string') return null;
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(designId) || null;
}

async function askShop(method, payload) {
  try {
    return await woocommerce.requestChange(method, payload);
  } catch (err) {
    const status = err.status || 503;
    return {
      status,
      body: { error: 'unavailable', message: 'Changes are unavailable right now.' },
    };
  }
}

function shopClosed(result) {
  return result.status === 410 || (result.body && result.body.error === 'closed');
}

function shopOk(result) {
  return result.status >= 200 && result.status < 300 && result.body && result.body.ok === true;
}

async function hydrate(req, res) {
  const design = loadDesign(req.params.id);
  const token = typeof req.query.change === 'string' ? req.query.change : '';
  if (!design || !token) return invalid(res);

  const result = await askShop('GET', { designId: design.id, token });
  if (shopClosed(result)) return closed(res);
  if (!shopOk(result)) {
    if (result.body && result.body.error === 'unavailable') {
      return res.status(503).json({ error: 'unavailable', message: 'Changes are unavailable right now.' });
    }
    return invalid(res);
  }
  if (result.body.designId !== design.id) return invalid(res);

  const params = parseJson(design.params, {});
  const productIds = normalizeIds(parseJson(design.product_ids, result.body.productIds));
  return res.json({
    mode: 'amend',
    designId: design.id,
    params: params && typeof params === 'object' ? params : {},
    productIds,
    customerName: design.customer_name,
    customerEmail: design.customer_email,
    checkoutUrl: null,
    revision: Number(result.body.revision) || Number(design.revision) || 0,
    geometrySummary: result.body.geometrySummary || '',
  });
}

function rememberRevision(design, revision, params) {
  const existing = db.prepare(
    'SELECT COUNT(*) AS n FROM design_revisions WHERE design_id = ?'
  ).get(design.id);
  if (!existing || !existing.n) {
    db.prepare(
      'INSERT INTO design_revisions (design_id, revision, params) VALUES (?, ?, ?)'
    ).run(design.id, 0, design.params);
  }
  db.prepare(
    'INSERT INTO design_revisions (design_id, revision, params) VALUES (?, ?, ?)'
  ).run(design.id, revision, JSON.stringify(params));
  db.prepare('UPDATE designs SET params = ?, revision = ? WHERE id = ?').run(
    JSON.stringify(params),
    revision,
    design.id,
  );
}

async function revise(req, res) {
  const design = loadDesign(req.params.id);
  const body = req.body || {};
  const token = typeof body.change === 'string' ? body.change : '';
  const params = body.params;
  if (!design || !token) return invalid(res);
  if (!params || typeof params !== 'object' || Array.isArray(params)) {
    return res.status(400).json({ error: 'params (bike geometry object) is required.' });
  }

  const storedIds = normalizeIds(parseJson(design.product_ids, []));
  const requestedIds = normalizeIds(body.productIds);
  const geometrySummary = woocommerce.geometrySummaryFrom(params);
  if (!geometrySummary) {
    return res.status(400).json({ error: 'Geometry is missing.' });
  }

  const gate = await askShop('GET', { designId: design.id, token });
  if (shopClosed(gate)) return closed(res);
  if (!shopOk(gate)) {
    if (gate.body && gate.body.error === 'unavailable') {
      return res.status(503).json({ error: 'unavailable', message: 'Changes are unavailable right now.' });
    }
    return invalid(res);
  }
  if (gate.body.designId !== design.id) return invalid(res);

  const paidIds = normalizeIds(gate.body.productIds);
  const expected = storedIds.length ? storedIds : paidIds;
  if (!requestedIds.length || !sameIds(requestedIds, expected) || (paidIds.length && !sameIds(requestedIds, paidIds))) {
    return res.status(409).json({ error: 'parts', message: 'Those parts can\'t be changed on this order.' });
  }

  const current = parseJson(design.params, {});
  if (sameParams(current, params)) {
    return res.json({
      ok: true,
      unchanged: true,
      designId: design.id,
      revision: Number(design.revision) || Number(gate.body.revision) || 0,
      productIds: expected,
    });
  }

  const applied = await askShop('POST', {
    designId: design.id,
    token,
    productIds: expected,
    geometrySummary,
    idempotencyKey: idempotencyKey(params),
  });
  if (shopClosed(applied)) return closed(res);
  if (applied.status === 409 || (applied.body && applied.body.error === 'parts')) {
    return res.status(409).json({ error: 'parts', message: 'Those parts can\'t be changed on this order.' });
  }
  if (applied.status === 429 || (applied.body && applied.body.error === 'rate')) {
    return res.status(429).json({ error: 'rate', message: 'Please wait a moment before saving another change.' });
  }
  if (!shopOk(applied)) return invalid(res);

  const revision = Number(applied.body.revision) || ((Number(design.revision) || 0) + 1);
  if (!applied.body.unchanged) {
    rememberRevision(design, revision, params);
  } else if (!sameParams(current, params)) {
    rememberRevision(design, revision, params);
  }

  if (!applied.body.unchanged) {
    try {
      await email.sendChangeNotice({
        orderId: applied.body.orderId,
        orderUrl: applied.body.orderUrl,
        designId: design.id,
        revision,
        oldGeometry: applied.body.oldGeometry,
        newGeometry: applied.body.newGeometry || geometrySummary,
      });
    } catch (err) {
      console.warn('[change] Admin notice failed:', err.message);
    }
  }

  return res.json({
    ok: true,
    unchanged: applied.body.unchanged === true,
    designId: design.id,
    revision,
    productIds: expected,
    geometrySummary,
  });
}

module.exports = {
  CLOSED_MESSAGE,
  hydrate,
  revise,
  sameParams,
  sameIds,
};
