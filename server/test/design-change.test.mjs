import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const dbPath = path.join(os.tmpdir(), `creature-change-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;
delete process.env.FD_CHANGE_NOTIFY_EMAIL;

const require = createRequire(import.meta.url);
const db = require('../db');
const designs = require('../routes/designs');
const email = require('../services/email');
const changeWindow = require('../services/change-window');
const woocommerce = require('../services/woocommerce');

const DESIGN = '11111111-1111-1111-1111-111111111111';
const TOKEN = 'a'.repeat(64);
const PARAMS = {
  reach: 450,
  chainstay_length: 430,
  ht_angle: 64.5,
  st_angle: 76,
  bb_drop: 30,
};
const NEXT = { ...PARAMS, reach: 460 };

const app = express();
app.use(express.json({ limit: '1mb' }));
app.use('/api/designs', designs);

function request(server, method, urlPath, body) {
  const payload = body == null ? null : JSON.stringify(body);
  return new Promise((resolve, reject) => {
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: urlPath,
      method,
      headers: payload ? {
        'content-type': 'application/json',
        'content-length': Buffer.byteLength(payload),
      } : {},
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let parsed = raw;
        try { parsed = JSON.parse(raw); } catch { /* keep text */ }
        resolve({ status: res.statusCode, body: parsed, raw, headers: res.headers });
      });
    });
    req.on('error', reject);
    if (payload) req.end(payload);
    else req.end();
  });
}

function insertDesign() {
  db.prepare(`
    INSERT INTO designs (
      id, customer_name, customer_email, params, status, wc_order_id, product_ids, revision
    ) VALUES (?, ?, ?, ?, 'paid', '42', ?, 0)
  `).run(DESIGN, 'Ada Lovelace', 'ada@example.com', JSON.stringify(PARAMS), JSON.stringify([8634, 8635]));
}

describe('paid design changes', { concurrency: false }, () => {
  /** @type {http.Server} */
  let server;
  let calls;
  let closed;
  let rate;

  before(async () => {
    server = await new Promise((resolve) => {
      const listening = app.listen(0, '127.0.0.1', () => resolve(listening));
    });
    process.env.WC_URL = 'https://shop.example';
    process.env.SMTP_PASS = 're_test';
    global.fetch = async (url, opts = {}) => {
      const href = String(url);
      const headers = opts.headers || {};
      calls.push({
        href,
        method: opts.method || 'GET',
        cache: opts.cache,
        cacheControl: headers['cache-control'] || headers['Cache-Control'] || '',
        pragma: headers.pragma || headers.Pragma || '',
        body: opts.body ? JSON.parse(opts.body) : null,
      });
      if (href.includes('api.resend.com')) {
        return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });
      }
      if (!href.includes('/wp-json/creature-fd/v1/change')) {
        return new Response('{}', { status: 404, headers: { 'content-type': 'application/json' } });
      }
      if ((opts.method || 'GET') === 'GET') {
        const parsed = new URL(href);
        const design = parsed.searchParams.get('design');
        const token = parsed.searchParams.get('token');
        if (token !== TOKEN || design !== DESIGN) {
          return new Response(JSON.stringify({ error: 'invalid', message: 'This link is not valid.' }), { status: 404 });
        }
        if (closed) {
          return new Response(JSON.stringify({
            error: 'closed',
            message: changeWindow.CLOSED_MESSAGE,
          }), { status: 410 });
        }
        return new Response(JSON.stringify({
          ok: true,
          designId: DESIGN,
          orderId: 42,
          orderUrl: 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=42',
          productIds: [8634, 8635],
          geometrySummary: 'Reach: 450mm, CS: 430mm, HTA: 64.5°, STA: 76°, BB Drop: 30mm',
          revision: 0,
          status: 'processing',
        }), { status: 200 });
      }
      const body = opts.body ? JSON.parse(opts.body) : {};
      assert.equal(body.total, undefined);
      assert.equal(body.line_items, undefined);
      assert.equal(body.price, undefined);
      const ids = (body.productIds || []).map(Number).sort((a, b) => a - b);
      if (ids.join(',') !== '8634,8635') {
        return new Response(JSON.stringify({ ok: false, error: 'parts' }), { status: 409 });
      }
      if (rate) {
        return new Response(JSON.stringify({ ok: false, error: 'rate' }), { status: 429 });
      }
      return new Response(JSON.stringify({
        ok: true,
        unchanged: false,
        designId: DESIGN,
        orderId: 42,
        orderUrl: 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=42',
        revision: 1,
        oldGeometry: 'Reach: 450mm, CS: 430mm, HTA: 64.5°, STA: 76°, BB Drop: 30mm',
        newGeometry: body.geometrySummary,
        productIds: [8634, 8635],
      }), { status: 200 });
    };
  });

  after(async () => {
    if (server) {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    for (const suffix of ['', '-wal', '-shm']) {
      try { fs.unlinkSync(dbPath + suffix); } catch { /* already gone */ }
    }
  });

  test('admin notice names the order and both geometries', () => {
    const message = email.changeNoticeMessage({
      orderId: 42,
      orderUrl: 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=42',
      designId: DESIGN,
      revision: 1,
      oldGeometry: 'Reach: 450mm',
      newGeometry: 'Reach: 460mm',
    });
    assert.equal(message.to, 'info@creaturecycles.co.uk');
    assert.match(message.subject, /order 42/);
    assert.match(message.text, /Previous geometry: Reach: 450mm/);
    assert.match(message.text, /New geometry: Reach: 460mm/);
    assert.match(message.text, /https:\/\/shop\.example\/wp-admin\/admin\.php\?page=wc-orders/);
    assert.match(message.html, /Reach: 460mm/);
    assert.match(message.text, /No new payment was taken/);
  });

  test('an open change token hydrates amend mode and a closed one shows no personal details', async () => {
    calls = [];
    closed = false;
    insertDesign();
    const open = await request(server, 'GET', `/api/designs/${DESIGN}?change=${TOKEN}`);
    assert.equal(open.status, 200);
    assert.equal(open.body.mode, 'amend');
    assert.equal(open.body.checkoutUrl, null);
    assert.deepEqual(open.body.productIds, [8634, 8635]);
    assert.equal(open.body.customerEmail, 'ada@example.com');
    assert.equal(JSON.stringify(open.body).includes(TOKEN), false);
    assert.equal(open.headers['cache-control'], 'no-store, private');

    closed = true;
    const shut = await request(server, 'GET', `/api/designs/${DESIGN}?change=${TOKEN}`);
    assert.equal(shut.status, 410);
    assert.equal(shut.body.message, changeWindow.CLOSED_MESSAGE);
    assert.equal(shut.raw.includes('ada@example.com'), false);
    assert.equal(shut.raw.includes('Ada Lovelace'), false);
    assert.equal(shut.raw.includes('Reach'), false);
    assert.equal(shut.raw.includes(TOKEN), false);
    assert.equal(shut.headers['cache-control'], 'no-store, private');
    closed = false;
  });

  test('each shop read of the change route bypasses the cache', async () => {
    calls = [];
    await woocommerce.requestChange('GET', { designId: DESIGN, token: TOKEN });
    await woocommerce.requestChange('GET', { designId: DESIGN, token: TOKEN });
    await woocommerce.requestChange('POST', {
      designId: DESIGN,
      token: TOKEN,
      productIds: [8634, 8635],
      geometrySummary: 'Reach: 450mm',
      idempotencyKey: 'abc',
    });
    const reads = calls.filter((call) => call.href.includes('/wp-json/creature-fd/v1/change'));
    assert.equal(reads.length, 3);
    const urls = reads.map((call) => call.href);
    assert.equal(new Set(urls).size, 3);
    for (const call of reads) {
      const parsed = new URL(call.href);
      assert.match(parsed.searchParams.get('cb') || '', /^[a-f0-9]{16}$/);
      assert.equal(call.cache, 'no-store');
      assert.equal(call.cacheControl, 'no-cache');
      assert.equal(call.pragma, 'no-cache');
    }
  });

  test('revision keeps the design id, stores history, and emails James', async () => {
    calls = [];
    rate = false;
    const saved = await request(server, 'POST', `/api/designs/${DESIGN}/revision`, {
      change: TOKEN,
      params: NEXT,
      productIds: [8635, 8634],
    });
    assert.equal(saved.status, 200);
    assert.equal(saved.headers['cache-control'], 'no-store, private');
    assert.equal(saved.body.ok, true);
    assert.equal(saved.body.unchanged, false);
    assert.equal(saved.body.revision, 1);
    assert.equal(saved.body.designId, DESIGN);
    const row = db.prepare('SELECT params, revision FROM designs WHERE id = ?').get(DESIGN);
    assert.equal(row.revision, 1);
    assert.equal(JSON.parse(row.params).reach, 460);
    const history = db.prepare('SELECT revision, params FROM design_revisions WHERE design_id = ? ORDER BY revision').all(DESIGN);
    assert.deepEqual(history.map((entry) => entry.revision), [0, 1]);
    assert.equal(JSON.parse(history[0].params).reach, 450);
    assert.equal(JSON.parse(history[1].params).reach, 460);
    const shopWrites = calls.filter((call) => call.href.includes('/wp-json/creature-fd/v1/change') && call.method === 'POST');
    assert.equal(shopWrites.length, 1);
    assert.deepEqual(shopWrites[0].body.productIds, [8634, 8635]);
    assert.equal(shopWrites[0].body.total, undefined);
    const notices = calls.filter((call) => call.href.includes('api.resend.com'));
    assert.equal(notices.length, 1);
    assert.equal(notices[0].body.to[0], 'info@creaturecycles.co.uk');
    assert.match(notices[0].body.text, /Reach: 450mm/);
    assert.match(notices[0].body.text, /Reach: 460mm/);
    assert.match(notices[0].body.text, /page=wc-orders/);

    calls = [];
    const again = await request(server, 'POST', `/api/designs/${DESIGN}/revision`, {
      change: TOKEN,
      params: NEXT,
      productIds: [8634, 8635],
    });
    assert.equal(again.status, 200);
    assert.equal(again.body.unchanged, true);
    assert.equal(calls.some((call) => call.href.includes('api.resend.com')), false);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM design_revisions WHERE design_id = ?').get(DESIGN).n, 2);
  });

  test('a different part list is rejected and does not write the order or send mail', async () => {
    calls = [];
    const rejected = await request(server, 'POST', `/api/designs/${DESIGN}/revision`, {
      change: TOKEN,
      params: { ...NEXT, reach: 470 },
      productIds: [8634],
    });
    assert.equal(rejected.status, 409);
    assert.equal(rejected.headers['cache-control'], 'no-store, private');
    assert.equal(rejected.body.error, 'parts');
    assert.equal(calls.some((call) => call.method === 'POST' && call.href.includes('/wp-json/creature-fd/v1/change')), false);
    assert.equal(calls.some((call) => call.href.includes('api.resend.com')), false);
    const row = db.prepare('SELECT params, revision FROM designs WHERE id = ?').get(DESIGN);
    assert.equal(row.revision, 1);
    assert.equal(JSON.parse(row.params).reach, 460);
    assert.equal(rejected.raw.includes('ada@example.com'), false);
  });

  test('the shop rate limit is passed through', async () => {
    calls = [];
    rate = true;
    const limited = await request(server, 'POST', `/api/designs/${DESIGN}/revision`, {
      change: TOKEN,
      params: { ...NEXT, reach: 480 },
      productIds: [8634, 8635],
    });
    assert.equal(limited.status, 429);
    assert.equal(limited.body.error, 'rate');
    assert.equal(calls.some((call) => call.href.includes('api.resend.com')), false);
    rate = false;
  });

  test('a token for another design does not revise this one', async () => {
    calls = [];
    const other = await request(server, 'POST', `/api/designs/${DESIGN}/revision`, {
      change: 'b'.repeat(64),
      params: { ...NEXT, reach: 490 },
      productIds: [8634, 8635],
    });
    assert.equal(other.status, 404);
    assert.equal(other.raw.includes('ada@example.com'), false);
    assert.equal(JSON.parse(db.prepare('SELECT params FROM designs WHERE id = ?').get(DESIGN).params).reach, 460);
  });
});
