import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const dbPath = path.join(os.tmpdir(), `creature-saved-pay-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.WC_PRODUCT_ID;
delete process.env.WC_PRODUCT_IDS;
delete process.env.WC_PRODUCT_PRICE;
delete process.env.WC_WEBHOOK_SECRET;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;
delete process.env.RETENTION_PURGE_DRY_RUN;

const require = createRequire(import.meta.url);
const woocommerce = require('../services/woocommerce');
const db = require('../db');
const webhooks = require('../routes/webhooks');
const designs = require('../routes/designs');
const cleanup = require('../services/unpaid-cleanup');
const retention = require('../services/retention-purge');

const PARAMS = {
  reach: 450,
  chainstay_length: 430,
  ht_angle: 64.5,
  st_angle: 76,
  bb_drop: 30,
};
const SAVED_TITLE = 'Saved design, awaiting card payment';
const SAVED_NOTE = 'Design saved from Frame Designer; no payment attempted yet.';
const RETIRED_METHOD = ['ba', 'cs'].join('');
const RETIRED_TITLE = ['Bank', ' Transfer'].join('');

const CATALOGUE = {
  WC_URL: 'https://shop.example',
  WC_CONSUMER_KEY: 'ck_test',
  WC_CONSUMER_SECRET: 'cs_test',
  WC_PRODUCT_IDS: '8634,8635,8636',
  WC_PRODUCT_ID: '8634',
  SMTP_PASS: 're_test',
};

const ENV_KEYS = [
  'WC_URL', 'WC_CONSUMER_KEY', 'WC_CONSUMER_SECRET',
  'WC_PRODUCT_ID', 'WC_PRODUCT_IDS', 'WC_PRODUCT_PRICE',
  'WC_WEBHOOK_SECRET', 'SMTP_PASS', 'RESEND_API_KEY',
  'RETENTION_PURGE_DRY_RUN', 'BASE_URL',
];

const app = express();
app.use('/api/webhooks', webhooks);
app.use(express.json({ limit: '1mb' }));
app.use('/api/designs', designs);

function repoRoot() {
  return path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
}

async function withEnv(vars, fn) {
  const prev = new Map(ENV_KEYS.map((key) => [key, process.env[key]]));
  for (const key of ENV_KEYS) delete process.env[key];
  for (const [key, value] of Object.entries(vars)) {
    if (value == null) delete process.env[key];
    else process.env[key] = String(value);
  }
  try {
    return await fn();
  } finally {
    for (const [key, value] of prev) {
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
  }
}

function post(server, urlPath, body) {
  const payload = JSON.stringify(body);
  return new Promise((resolve, reject) => {
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: urlPath,
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'content-length': Buffer.byteLength(payload),
      },
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let parsed = raw;
        try { parsed = JSON.parse(raw); } catch { /* keep text */ }
        resolve({ status: res.statusCode, body: parsed });
      });
    });
    req.on('error', reject);
    req.end(payload);
  });
}

function insertDesign({ id, status = 'checkout_created', wcOrderId = null, createdSql = 'CURRENT_TIMESTAMP', expiresSql = "datetime('now', '+90 days')" }) {
  db.prepare(`
    INSERT INTO designs (
      id, created_at, customer_name, customer_email, params, status, wc_order_id, resume_expires_at
    ) VALUES (?, ${createdSql}, 'Ada Lovelace', ?, ?, ?, ?, ${expiresSql})
  `).run(id, `${id}@example.com`, JSON.stringify(PARAMS), status, wcOrderId);
}

function designRow(id) {
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(id);
}

async function waitFor(fn) {
  for (let i = 0; i < 40; i += 1) {
    const value = fn();
    if (value) return value;
    await new Promise((resolve) => setTimeout(resolve, 15));
  }
  return fn();
}

/**
 * Model of WC_Order::set_payment_method then set_payment_method_title.
 * Woo's REST schema applies those keys in that order. This is not a shop call.
 */
function applyWooRestPayment(payload) {
  let method = '';
  let title = '';
  if (Object.prototype.hasOwnProperty.call(payload, 'payment_method')) {
    if (payload.payment_method === '') {
      method = '';
      title = '';
    } else {
      method = String(payload.payment_method);
    }
  }
  if (payload.payment_method_title != null) title = String(payload.payment_method_title);
  return { method, title };
}

function resendCalls(calls) {
  return calls.filter((call) => call.href.includes('api.resend.com'));
}

function wooWrites(calls) {
  return calls.filter((call) => call.href.includes('/wp-json/wc/') && call.method !== 'GET');
}

describe('saved design payment label', { concurrency: false }, () => {
  /** @type {http.Server} */
  let server;

  before(async () => {
    server = await new Promise((resolve) => {
      const listening = app.listen(0, '127.0.0.1', () => resolve(listening));
    });
  });

  after(async () => {
    if (server) {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    for (const suffix of ['', '-wal', '-shm']) {
      try { fs.unlinkSync(dbPath + suffix); } catch { /* already gone */ }
    }
  });

  test('the repo no longer stores the retired gateway id or title', () => {
    const skip = new Set(['node_modules', '.git']);
    const hits = [];
    function walk(dir) {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (skip.has(entry.name)) continue;
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
          walk(full);
          continue;
        }
        if (!/\.(js|mjs|php|md|html|css|json)$/.test(entry.name)) continue;
        const text = fs.readFileSync(full, 'utf8');
        if (text.includes(RETIRED_METHOD) || text.includes(RETIRED_TITLE)) {
          hits.push(path.relative(repoRoot(), full));
        }
      }
    }
    walk(repoRoot());
    assert.deepEqual(hits, []);
  });

  test('order-pay is not filtered by the stored payment method', () => {
    const root = path.join(repoRoot(), 'wordpress-plugins/mu-plugins');
    const names = [
      'creature-fd-order-experience.php',
      'creature-fd-only-purchase.php',
      'creature-rear-end-discount.php',
      'creature-unpaid-design-orders.php',
    ];
    for (const name of names) {
      const text = fs.readFileSync(path.join(root, name), 'utf8');
      assert.equal(text.includes(RETIRED_METHOD), false, name);
      assert.equal(text.includes('woocommerce_available_payment_gateways'), false, name);
      assert.equal(text.includes('get_payment_method('), false, name);
    }

    const experience = fs.readFileSync(path.join(root, 'creature-fd-order-experience.php'), 'utf8');
    const expressAt = experience.indexOf('function on_before_pay_form_express');
    const expressEnd = experience.indexOf('function filter_checkout_show_terms');
    const express = experience.slice(expressAt, expressEnd);
    assert.equal(express.includes('payment_method'), false);
    assert.match(express, /wcpay-express-checkout-wrapper/);
    assert.match(experience, /is_fd_order/);

    const only = fs.readFileSync(path.join(root, 'creature-fd-only-purchase.php'), 'utf8');
    assert.match(only, /order-pay are left as the gateway decided/);
    assert.match(only, /Does not touch REST-created orders or order-pay/);
  });

  test('empty payment method keeps the saved title under Woo REST setter order', () => {
    return withEnv(CATALOGUE, () => {
      const payload = woocommerce.buildOrderPayload({
        designId: 'design-label',
        customerName: 'Ada Lovelace',
        customerEmail: 'ada@example.com',
        params: PARAMS,
        productIds: [8634, 8635, 8636],
      });
      assert.equal(payload.payment_method, '');
      assert.equal(payload.payment_method_title, SAVED_TITLE);
      assert.equal(payload.set_paid, false);
      assert.equal(payload.status, undefined);
      assert.equal(payload.fee_lines, undefined);
      assert.deepEqual(payload.line_items.map((line) => line.product_id), [8634, 8635, 8636]);
      for (const line of payload.line_items) {
        assert.equal(line.quantity, 1);
        assert.equal(line.total, undefined);
        assert.equal(line.subtotal, undefined);
      }

      const applied = applyWooRestPayment(payload);
      assert.equal(applied.method, '');
      assert.equal(applied.title, SAVED_TITLE);
      assert.equal(JSON.stringify(payload).includes(`"payment_method":""`), true);

      const priced = woocommerce.buildOrderPayload({
        designId: 'design-label',
        customerName: 'Ada',
        customerEmail: 'ada@example.com',
        params: PARAMS,
      });
      assert.equal(priced.line_items[0].total, undefined);
    });
  });

  test('a. saved unpaid order posts the label and a private note, and still saves if the note fails', async () => {
    await withEnv({ ...CATALOGUE, WC_PRODUCT_PRICE: '49.00' }, async () => {
      const original = global.fetch;
      const calls = [];
      global.fetch = async (url, opts = {}) => {
        const href = String(url);
        const body = opts.body ? JSON.parse(opts.body) : null;
        calls.push({ href, method: opts.method || 'GET', body, raw: opts.body || '' });
        if (href.includes('api.resend.com')) {
          return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });
        }
        if (href.endsWith('/notes')) {
          return new Response(JSON.stringify({ id: calls.length }), {
            status: 201,
            headers: { 'content-type': 'application/json' },
          });
        }
        return new Response(JSON.stringify({
          id: 4242,
          order_key: 'wc_order_4242',
          status: 'pending',
          payment_url: 'https://shop.example/checkout/order-pay/4242/?pay_for_order=true&key=wc_order_4242',
        }), { status: 201, headers: { 'content-type': 'application/json' } });
      };
      try {
        const res = await post(server, '/api/designs', {
          customerName: 'Ada Lovelace',
          customerEmail: 'saved-unpaid@example.com',
          params: PARAMS,
          productIds: [8634, 8635],
        });
        assert.equal(res.status, 200);
        const row = designRow(res.body.designId);
        assert.equal(row.status, 'checkout_created');
        assert.equal(row.wc_order_id, '4242');

        const created = calls.find((call) => call.href.endsWith('/orders'));
        assert.equal(created.method, 'POST');
        assert.equal(created.body.payment_method, '');
        assert.equal(created.body.payment_method_title, SAVED_TITLE);
        assert.equal(created.body.set_paid, false);
        assert.equal(created.body.status, undefined);
        assert.equal(created.body.fee_lines, undefined);
        assert.equal(created.raw.includes('"payment_method":""'), true);
        assert.deepEqual(created.body.line_items.map((line) => line.product_id), [8634, 8635]);
        for (const line of created.body.line_items) {
          assert.equal(line.total, undefined);
          assert.equal(line.subtotal, undefined);
        }

        const notes = calls.filter((call) => call.href.endsWith('/notes'));
        assert.equal(notes.length, 2);
        assert.deepEqual(notes.map((call) => call.body.customer_note), [false, false]);
        assert.equal(notes[0].body.note, woocommerce.designNoteText(res.body.designId));
        assert.equal(notes[1].body.note, SAVED_NOTE);
        assert.equal(woocommerce.savedAwaitingPaymentNote(), SAVED_NOTE);

        const mailed = await waitFor(() => resendCalls(calls)[0]);
        assert.equal(mailed.body.subject, 'Your Creature Cycles design is saved');
        assert.equal(mailed.body.to[0], 'saved-unpaid@example.com');
        assert.equal(JSON.stringify(mailed.body).includes(RETIRED_TITLE), false);
      } finally {
        global.fetch = original;
      }
    });

    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      let notePosts = 0;
      global.fetch = async (url, opts = {}) => {
        const href = String(url);
        if (href.endsWith('/notes')) {
          notePosts += 1;
          const body = JSON.parse(opts.body);
          if (body.note.startsWith('Bespoke')) {
            return new Response(JSON.stringify({ message: 'note failed' }), {
              status: 500,
              headers: { 'content-type': 'application/json' },
            });
          }
          return new Response(JSON.stringify({ id: 2 }), {
            status: 500,
            headers: { 'content-type': 'application/json' },
          });
        }
        return new Response(JSON.stringify({
          id: 77,
          order_key: 'k77',
          payment_url: 'https://shop.example/checkout/order-pay/77/?key=k77',
        }), { status: 201, headers: { 'content-type': 'application/json' } });
      };
      try {
        const result = await woocommerce.createOrder({
          designId: 'design-note-fails',
          customerName: 'Ada Lovelace',
          customerEmail: 'ada@example.com',
          params: PARAMS,
          productIds: [8634],
        });
        assert.equal(result.wcOrderId, '77');
        assert.match(result.checkoutUrl, /order-pay\/77/);
        assert.equal(notePosts, 2);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('b/c. a paid webhook records paid and does not write the saved label back', async () => {
    await withEnv({ SMTP_PASS: 're_test' }, async () => {
      const original = global.fetch;
      const calls = [];
      global.fetch = async (url, opts = {}) => {
        calls.push({
          href: String(url),
          method: opts.method || 'GET',
          body: opts.body ? JSON.parse(opts.body) : null,
        });
        return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });
      };
      try {
        const card = 'design-paid-card';
        insertDesign({ id: card, wcOrderId: '6101' });
        const cardRes = await post(server, '/api/webhooks/woocommerce/order-updated', {
          id: 6101,
          status: 'processing',
          payment_method: 'woocommerce_payments',
          payment_method_title: 'Credit card / debit card',
          meta_data: [{ key: 'design_id', value: card }],
        });
        assert.equal(cardRes.status, 200);
        const cardRow = await waitFor(() => {
          const row = designRow(card);
          return row && row.status === 'paid' && row.paid_at ? row : null;
        });
        assert.equal(cardRow.status, 'paid');
        assert.ok(cardRow.paid_at);
        const cardMail = await waitFor(() => resendCalls(calls).find((call) => String(call.body.subject).includes('under review')));
        assert.match(cardMail.body.subject, /Design #DESIGN-P is under review/);
        assert.equal(cardMail.body.to[0], 'design-paid-card@example.com');
        assert.equal(wooWrites(calls).length, 0);
        assert.equal(JSON.stringify(calls).includes(SAVED_TITLE), false);
        assert.equal(JSON.stringify(calls).includes(RETIRED_METHOD), false);

        const paypal = 'design-paid-paypal';
        insertDesign({ id: paypal, wcOrderId: '6102' });
        const beforeCalls = calls.length;
        const paypalRes = await post(server, '/api/webhooks/woocommerce/order-updated', {
          id: 6102,
          status: 'processing',
          payment_method: 'ppcp-gateway',
          payment_method_title: 'PayPal',
          meta_data: [{ key: 'design_id', value: paypal }],
        });
        assert.equal(paypalRes.status, 200);
        const paypalRow = await waitFor(() => {
          const row = designRow(paypal);
          return row && row.status === 'paid' ? row : null;
        });
        assert.equal(paypalRow.status, 'paid');
        assert.ok(paypalRow.paid_at);
        const paypalMail = await waitFor(() => resendCalls(calls).slice(1).find((call) => String(call.body.subject).includes('under review')));
        assert.equal(paypalMail.body.to[0], 'design-paid-paypal@example.com');
        assert.equal(wooWrites(calls.slice(beforeCalls)).length, 0);
        assert.equal(JSON.stringify(calls.slice(beforeCalls)).includes(SAVED_TITLE), false);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('d. a failed card does not email, and the later payment records paid', async () => {
    await withEnv({ SMTP_PASS: 're_test' }, async () => {
      const original = global.fetch;
      const calls = [];
      global.fetch = async (url, opts = {}) => {
        calls.push({
          href: String(url),
          method: opts.method || 'GET',
          body: opts.body ? JSON.parse(opts.body) : null,
        });
        return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });
      };
      const id = 'design-failed-card';
      insertDesign({ id, wcOrderId: '6103' });
      try {
        assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', {
          id: 6103,
          status: 'failed',
          payment_method: 'woocommerce_payments',
          payment_method_title: 'Credit card / debit card',
          meta_data: [{ key: 'design_id', value: id }],
        })).status, 200);
        const failed = await waitFor(() => {
          const row = designRow(id);
          return row && row.status === 'failed' ? row : null;
        });
        assert.equal(failed.status, 'failed');
        assert.equal(failed.paid_at, null);
        await new Promise((resolve) => setTimeout(resolve, 40));
        assert.equal(resendCalls(calls).length, 0);
        assert.equal(wooWrites(calls).length, 0);

        assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', {
          id: 6103,
          status: 'processing',
          payment_method: 'woocommerce_payments',
          payment_method_title: 'Credit card / debit card',
          meta_data: [{ key: 'design_id', value: id }],
        })).status, 200);
        const paid = await waitFor(() => {
          const row = designRow(id);
          return row && row.status === 'paid' && row.paid_at ? row : null;
        });
        assert.equal(paid.status, 'paid');
        const mailed = await waitFor(() => resendCalls(calls)[0]);
        assert.match(mailed.body.subject, /under review/);
        assert.equal(wooWrites(calls).length, 0);
        assert.equal(JSON.stringify(calls).includes(SAVED_TITLE), false);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('e. unpaid cleanup cancels a pending order without touching the payment label', async () => {
    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      const calls = [];
      const orders = {
        81: {
          id: 81,
          status: 'pending',
          payment_method: '',
          payment_method_title: SAVED_TITLE,
          meta_data: [{ key: 'design_id', value: 'abandoned-pending' }],
        },
        84: {
          id: 84,
          status: 'processing',
          payment_method: 'woocommerce_payments',
          payment_method_title: 'Credit card / debit card',
        },
      };
      global.fetch = async (url, opts = {}) => {
        const href = String(url);
        const body = opts.body ? JSON.parse(opts.body) : null;
        calls.push({ href, method: opts.method || 'GET', body });
        if (href.includes('api.resend.com')) {
          return new Response('{}', { status: 200 });
        }
        const match = href.match(/\/orders\/(\d+)$/);
        assert.ok(match, href);
        const order = orders[match[1]];
        if ((opts.method || 'GET') === 'PUT') {
          assert.deepEqual(body, { status: 'cancelled' });
          order.status = 'cancelled';
        }
        return new Response(JSON.stringify(order), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        });
      };
      try {
        insertDesign({
          id: 'abandoned-pending',
          status: 'checkout_created',
          wcOrderId: '81',
          createdSql: "datetime('now', '-100 days')",
          expiresSql: "datetime('now', '-1 day')",
        });
        insertDesign({
          id: 'abandoned-paid',
          status: 'paid',
          wcOrderId: '84',
          createdSql: "datetime('now', '-200 days')",
          expiresSql: "datetime('now', '-100 days')",
        });
        const result = await cleanup.run();
        assert.deepEqual(result.expired.map((row) => row.designId), ['abandoned-pending']);
        const expired = designRow('abandoned-pending');
        assert.equal(expired.status, 'expired');
        assert.ok(expired.deleted_at);
        assert.equal(designRow('abandoned-paid').status, 'paid');
        const puts = calls.filter((call) => call.method === 'PUT');
        assert.equal(puts.length, 1);
        assert.match(puts[0].href, /\/orders\/81$/);
        assert.deepEqual(puts[0].body, { status: 'cancelled' });
        assert.equal(orders[81].status, 'cancelled');
        assert.equal(orders[81].payment_method, '');
        assert.equal(orders[81].payment_method_title, SAVED_TITLE);
        assert.equal(orders[84].payment_method, 'woocommerce_payments');
        assert.equal(resendCalls(calls).length, 0);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('f. the one-year purge cancels a payable order without rewriting payment', async () => {
    await withEnv({ ...CATALOGUE, RETENTION_PURGE_DRY_RUN: '0' }, async () => {
      const original = global.fetch;
      const calls = [];
      let status = 'pending';
      global.fetch = async (url, opts = {}) => {
        const href = String(url);
        const body = opts.body ? JSON.parse(opts.body) : null;
        calls.push({ href, method: opts.method || 'GET', body });
        if (href.includes('api.resend.com')) {
          return new Response('{}', { status: 200 });
        }
        if (href.endsWith('/system_status')) {
          return new Response(JSON.stringify({ environment: { version: '9.0.0' } }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
          });
        }
        if ((opts.method || 'GET') === 'PUT') {
          assert.deepEqual(body, { status: 'cancelled' });
          status = 'cancelled';
        }
        return new Response(JSON.stringify({
          id: 701,
          status,
          payment_method: '',
          payment_method_title: SAVED_TITLE,
          date_paid: null,
          date_paid_gmt: null,
          meta_data: [{ key: 'design_id', value: 'purge-pending' }],
          line_items: [{ meta_data: [{ key: 'design_id', value: 'purge-pending' }] }],
        }), { status: 200, headers: { 'content-type': 'application/json' } });
      };
      try {
        insertDesign({
          id: 'purge-pending',
          status: 'pending',
          wcOrderId: '701',
          createdSql: "datetime('now', '-400 days')",
          expiresSql: "datetime('now', '-1 day')",
        });
        const result = await retention.run();
        assert.equal(result.aborted, false);
        assert.deepEqual(result.cancelledOrderIds, ['purge-pending']);
        assert.deepEqual(result.designIds, ['purge-pending']);
        const row = designRow('purge-pending');
        assert.ok(row.anonymised_at);
        assert.equal(row.customer_email, 'redacted+purge-pending@invalid');
        const puts = wooWrites(calls);
        assert.equal(puts.length, 1);
        assert.deepEqual(puts[0].body, { status: 'cancelled' });
        assert.equal(JSON.stringify(puts).includes('payment_method'), false);
        assert.equal(resendCalls(calls).length, 0);
        assert.equal(status, 'cancelled');
      } finally {
        global.fetch = original;
      }
    });
  });

  test('g. a geometry change stays on the paid order and takes no new payment', async () => {
    const experience = fs.readFileSync(
      path.join(repoRoot(), 'wordpress-plugins/mu-plugins/creature-fd-order-experience.php'),
      'utf8',
    );
    const noteAt = experience.indexOf("add_order_note( 'Geometry revised (revision ");
    assert.notEqual(noteAt, -1);
    const noteLine = experience.slice(noteAt, experience.indexOf('\n', noteAt));
    assert.equal(noteLine.includes('customer_note'), false);
    assert.equal(experience.includes(RETIRED_METHOD), false);

    await withEnv({ WC_URL: 'https://shop.example', SMTP_PASS: 're_test' }, async () => {
      const id = '22222222-2222-2222-2222-222222222222';
      const token = 'b'.repeat(64);
      db.prepare(`
        INSERT INTO designs (
          id, customer_name, customer_email, params, status, wc_order_id, product_ids, revision
        ) VALUES (?, 'Ada Lovelace', 'ada@example.com', ?, 'paid', '42', ?, 0)
      `).run(id, JSON.stringify(PARAMS), JSON.stringify([8634, 8635]));

      const original = global.fetch;
      const calls = [];
      global.fetch = async (url, opts = {}) => {
        const href = String(url);
        const body = opts.body ? JSON.parse(opts.body) : null;
        calls.push({ href, method: opts.method || 'GET', body });
        if (href.includes('api.resend.com')) {
          return new Response('{}', { status: 200, headers: { 'content-type': 'application/json' } });
        }
        assert.equal(href.includes('/wp-json/wc/'), false);
        assert.equal(body == null || body.payment_method === undefined, true);
        if ((opts.method || 'GET') === 'GET') {
          return new Response(JSON.stringify({
            ok: true,
            designId: id,
            orderId: 42,
            productIds: [8634, 8635],
            geometrySummary: 'Reach: 450mm',
            revision: 0,
            status: 'processing',
          }), { status: 200, headers: { 'content-type': 'application/json' } });
        }
        return new Response(JSON.stringify({
          ok: true,
          unchanged: false,
          designId: id,
          orderId: 42,
          orderUrl: 'https://shop.example/wp-admin/admin.php?page=wc-orders&action=edit&id=42',
          revision: 1,
          oldGeometry: 'Reach: 450mm',
          newGeometry: body.geometrySummary,
          productIds: [8634, 8635],
        }), { status: 200, headers: { 'content-type': 'application/json' } });
      };
      try {
        const res = await post(server, `/api/designs/${id}/revision`, {
          change: token,
          params: { ...PARAMS, reach: 460 },
          productIds: [8634, 8635],
        });
        assert.equal(res.status, 200);
        assert.equal(res.body.ok, true);
        assert.equal(res.body.revision, 1);
        const row = designRow(id);
        assert.equal(row.status, 'paid');
        assert.equal(row.wc_order_id, '42');
        assert.equal(row.revision, 1);
        const shopPosts = calls.filter((call) => call.method === 'POST' && call.href.includes('/creature-fd/v1/change'));
        assert.equal(shopPosts.length, 1);
        assert.equal(shopPosts[0].body.payment_method, undefined);
        assert.equal(shopPosts[0].body.line_items, undefined);
        assert.equal(shopPosts[0].body.total, undefined);
        const mailed = await waitFor(() => resendCalls(calls)[0]);
        assert.match(mailed.body.subject, /geometry change — order 42/);
        assert.match(mailed.body.text, /No new payment was taken/);
        assert.equal(calls.some((call) => call.href.includes('/wp-json/wc/v3/orders')), false);
      } finally {
        global.fetch = original;
      }
    });
  });
});
