import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const dbPath = path.join(os.tmpdir(), `creature-wc-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.WC_PRODUCT_ID;
delete process.env.WC_PRODUCT_IDS;
delete process.env.WC_PRODUCT_PRICE;
delete process.env.WC_WEBHOOK_SECRET;
delete process.env.SMTP_HOST;
delete process.env.SMTP_USER;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;

const require = createRequire(import.meta.url);
const woocommerce = require('../services/woocommerce');
const db = require('../db');
const webhooks = require('../routes/webhooks');
const designs = require('../routes/designs');

const PARAMS = {
  reach: 450,
  chainstay_length: 430,
  ht_angle: 64.5,
  st_angle: 76,
  bb_drop: 30,
};
const SUMMARY = 'Reach: 450mm, CS: 430mm, HTA: 64.5°, STA: 76°, BB Drop: 30mm';

const CATALOGUE = {
  WC_URL: 'https://shop.example',
  WC_CONSUMER_KEY: 'ck_test',
  WC_CONSUMER_SECRET: 'cs_test',
  WC_PRODUCT_IDS: '8634, 8635, 8636, 8637',
  WC_PRODUCT_ID: '8634',
};

const ENV_KEYS = [
  'WC_URL', 'WC_CONSUMER_KEY', 'WC_CONSUMER_SECRET',
  'WC_PRODUCT_ID', 'WC_PRODUCT_IDS', 'WC_PRODUCT_PRICE',
  'WC_WEBHOOK_SECRET', 'BASE_URL',
];

async function withEnv(vars, fn) {
  const prev = new Map(ENV_KEYS.map(k => [k, process.env[k]]));
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

function post(server, urlPath, body, headers = {}) {
  const payload = typeof body === 'string' ? body : JSON.stringify(body);
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
        ...headers,
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

function insertDesign({ id, status = 'checkout_created', wcOrderId = null }) {
  db.prepare(`
    INSERT INTO designs (id, customer_name, customer_email, params, status, wc_order_id)
    VALUES (?, 'Smoke', ?, '{}', ?, ?)
  `).run(id, `${id}@example.com`, status, wcOrderId);
}

function designStatus(id) {
  return db.prepare('SELECT status, wc_order_id FROM designs WHERE id = ?').get(id);
}

async function waitForStatus(id, status) {
  for (let i = 0; i < 40; i++) {
    const row = designStatus(id);
    if (row && row.status === status) return row;
    await new Promise((resolve) => setTimeout(resolve, 15));
  }
  return designStatus(id);
}

const app = express();
app.use('/api/webhooks', webhooks);
app.use(express.json({ limit: '1mb' }));
app.use('/api/designs', designs);

describe('multi-line WooCommerce orders', { concurrency: false }, () => {
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

  test('catalogue comes from WC_PRODUCT_IDS plus WC_PRODUCT_ID', () => {
    return withEnv(CATALOGUE, () => {
      assert.deepEqual(woocommerce.configuredProductIds(), [8634, 8635, 8636, 8637]);
      assert.equal(woocommerce.isConfigured(), true);
    });
  });

  test('WC_PRODUCT_ID alone still configures a single product', () => {
    return withEnv({
      WC_URL: 'https://shop.example',
      WC_CONSUMER_KEY: 'ck_test',
      WC_CONSUMER_SECRET: 'cs_test',
      WC_PRODUCT_ID: '8634',
    }, () => {
      assert.deepEqual(woocommerce.configuredProductIds(), [8634]);
      assert.deepEqual(woocommerce.resolveProductIds(undefined), [8634]);
    });
  });

  test('omitted productIds uses WC_PRODUCT_ID when several products are configured', () => {
    return withEnv(CATALOGUE, () => {
      assert.deepEqual(woocommerce.resolveProductIds(undefined), [8634]);
    });
  });

  test('productIds is required when only a multi-id list is configured', () => {
    return withEnv({
      WC_PRODUCT_IDS: '8634,8635',
    }, () => {
      assert.throws(
        () => woocommerce.resolveProductIds(undefined),
        (err) => err.status === 400 && /productIds is required/.test(err.message),
      );
    });
  });

  test('a single configured id is the default when WC_PRODUCT_ID is unset', () => {
    return withEnv({ WC_PRODUCT_IDS: '8636' }, () => {
      assert.deepEqual(woocommerce.resolveProductIds(undefined), [8636]);
    });
  });

  test('rejects ids outside the catalogue and empty selections', () => {
    return withEnv(CATALOGUE, () => {
      assert.throws(
        () => woocommerce.resolveProductIds([8634, 9999]),
        (err) => err.status === 400
          && err.message === 'Product 9999 is not available.'
          && !/8634|8635|8636|8637/.test(err.message),
      );
      assert.throws(
        () => woocommerce.resolveProductIds([]),
        (err) => err.status === 400,
      );
      assert.throws(
        () => woocommerce.resolveProductIds(['nope']),
        (err) => err.status === 400,
      );
    });
  });

  test('buildOrderPayload copies design meta onto the order and every line', () => {
    return withEnv(CATALOGUE, () => {
      const payload = woocommerce.buildOrderPayload({
        designId: 'design-1',
        customerName: 'Ada Lovelace',
        customerEmail: 'ada@example.com',
        params: PARAMS,
        productIds: ['8634', 8635, '8634', 8637],
      });

      assert.equal(payload.set_paid, false);
      assert.deepEqual(
        payload.line_items.map((line) => line.product_id),
        [8634, 8635, 8637],
      );
      assert.deepEqual(payload.meta_data, [
        { key: 'design_id', value: 'design-1' },
        { key: 'creature_design_id', value: 'design-1' },
        { key: 'geometry_summary', value: SUMMARY },
      ]);
      for (const line of payload.line_items) {
        assert.equal(line.quantity, 1);
        assert.equal(line.subtotal, undefined);
        assert.deepEqual(line.meta_data, payload.meta_data);
        assert.notEqual(line.meta_data, payload.meta_data);
      }
      assert.notEqual(payload.line_items[0].meta_data, payload.line_items[1].meta_data);
      assert.equal(payload.billing.first_name, 'Ada');
      assert.equal(payload.billing.last_name, 'Lovelace');
      assert.equal(payload.customer_note, undefined);
      assert.equal(JSON.stringify(payload).includes('Bespoke bike design'), false);
    });
  });

  test('WC_PRODUCT_PRICE applies only when productIds is omitted', () => {
    return withEnv({ ...CATALOGUE, WC_PRODUCT_PRICE: '49.00' }, () => {
      const one = woocommerce.buildOrderPayload({
        designId: 'design-1',
        customerName: 'Ada',
        customerEmail: 'ada@example.com',
        params: PARAMS,
      });
      assert.equal(one.line_items.length, 1);
      assert.equal(one.line_items[0].product_id, 8634);
      assert.equal(one.line_items[0].total, '49.00');
      assert.equal(one.line_items[0].subtotal, '49.00');

      const explicitDefault = woocommerce.buildOrderPayload({
        designId: 'design-1',
        customerName: 'Ada',
        customerEmail: 'ada@example.com',
        params: PARAMS,
        productIds: [8634],
      });
      assert.equal(explicitDefault.line_items[0].product_id, 8634);
      assert.equal(explicitDefault.line_items[0].total, undefined);
      assert.equal(explicitDefault.line_items[0].subtotal, undefined);

      const other = woocommerce.buildOrderPayload({
        designId: 'design-price-leak',
        customerName: 'Test',
        customerEmail: 'test@example.com',
        params: PARAMS,
        productIds: [8636],
      });
      assert.equal(other.line_items.length, 1);
      assert.equal(other.line_items[0].product_id, 8636);
      assert.equal(other.line_items[0].total, undefined);
      assert.equal(other.line_items[0].subtotal, undefined);

      const many = woocommerce.buildOrderPayload({
        designId: 'design-1',
        customerName: 'Ada',
        customerEmail: 'ada@example.com',
        params: PARAMS,
        productIds: [8634, 8636],
      });
      assert.equal(many.line_items[0].total, undefined);
      assert.equal(many.line_items[1].total, undefined);
    });
  });

  test('designIdFromOrder prefers order meta, then line items', () => {
    assert.equal(woocommerce.designIdFromOrder({
      meta_data: [{ key: 'design_id', value: 'order-level' }],
      line_items: [{ meta_data: [{ key: 'design_id', value: 'line-level' }] }],
    }), 'order-level');

    assert.equal(woocommerce.designIdFromOrder({
      meta_data: [],
      line_items: [
        { product_id: 8634, meta_data: [] },
        { product_id: 8635, meta_data: [{ key: 'design_id', value: 'from-line' }, { key: 'geometry_summary', value: SUMMARY }] },
      ],
    }), 'from-line');

    assert.equal(woocommerce.designIdFromOrder({ meta_data: [], line_items: [] }), null);
  });

  test('createOrder posts every selected line and does not touch products', async () => {
    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      let captured;
      const notes = [];
      global.fetch = async (url, opts) => {
        const href = String(url);
        if (href.endsWith('/notes')) {
          notes.push(JSON.parse(opts.body));
          return new Response(JSON.stringify({ id: 1 }), {
            status: 201,
            headers: { 'content-type': 'application/json' },
          });
        }
        captured = { url: href, body: JSON.parse(opts.body), authorization: opts.headers.Authorization };
        return new Response(JSON.stringify({
          id: 4242,
          order_key: 'wc_order_test',
          payment_url: 'https://shop.example/checkout/order-pay/4242/?pay_for_order=true&key=wc_order_test',
        }), { status: 201, headers: { 'content-type': 'application/json' } });
      };
      try {
        const result = await woocommerce.createOrder({
          designId: 'design-9',
          customerName: 'Grace Hopper',
          customerEmail: 'grace@example.com',
          params: PARAMS,
          productIds: [8634, 8635, 8636],
        });
        assert.equal(result.wcOrderId, '4242');
        assert.match(result.checkoutUrl, /order-pay\/4242/);
        assert.equal(captured.url, 'https://shop.example/wp-json/wc/v3/orders');
        assert.equal(captured.body.customer_note, undefined);
        assert.equal(notes.length, 1);
        assert.equal(notes[0].customer_note, false);
        assert.equal(notes[0].note, 'Bespoke bike design — ID: design-9');
        assert.equal(captured.url.includes('/products'), false);
        assert.ok(captured.authorization.startsWith('Basic '), 'sends basic auth');
        assert.deepEqual(
          captured.body.line_items.map((line) => line.product_id),
          [8634, 8635, 8636],
        );
        assert.equal(captured.body.meta_data[0].value, 'design-9');
        for (const line of captured.body.line_items) {
          assert.deepEqual(line.meta_data, captured.body.meta_data);
        }
      } finally {
        global.fetch = original;
      }
    });
  });

  test('getDesignIdFromOrder reads line-item meta when the order meta is empty', async () => {
    await withEnv({
      WC_URL: 'https://shop.example',
      WC_CONSUMER_KEY: 'ck_test',
      WC_CONSUMER_SECRET: 'cs_test',
      WC_PRODUCT_ID: '8634',
    }, async () => {
      const original = global.fetch;
      global.fetch = async (url) => {
        assert.match(url, /\/orders\/77$/);
        return new Response(JSON.stringify({
          id: 77,
          meta_data: [{ key: 'geometry_summary', value: SUMMARY }],
          line_items: [
            { product_id: 8635, meta_data: [{ key: 'design_id', value: 'from-line' }] },
          ],
        }), { status: 200, headers: { 'content-type': 'application/json' } });
      };
      try {
        assert.equal(await woocommerce.getDesignIdFromOrder(77), 'from-line');
      } finally {
        global.fetch = original;
      }
    });
  });

  test('POST /api/designs rejects an unknown product before saving a design', async () => {
    await withEnv(CATALOGUE, async () => {
      const before = db.prepare('SELECT COUNT(*) AS n FROM designs').get().n;
      const res = await post(server, '/api/designs', {
        customerName: 'Smoke Test',
        customerEmail: 'unknown-product@example.com',
        params: PARAMS,
        productIds: [8634, 9999],
      });
      assert.equal(res.status, 400);
      assert.equal(res.body.error, 'Product 9999 is not available.');
      assert.equal(/8634|8635|8636|8637/.test(res.body.error), false);
      assert.equal(db.prepare('SELECT COUNT(*) AS n FROM designs').get().n, before);
    });
  });

  test('POST /api/designs stores one order id for several line items', async () => {
    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      let captured;
      global.fetch = async (url, opts) => {
        if (String(url).includes('/notes')) {
          return new Response(JSON.stringify({ id: 1 }), {
            status: 201,
            headers: { 'content-type': 'application/json' },
          });
        }
        captured = JSON.parse(opts.body);
        return new Response(JSON.stringify({
          id: 5150,
          order_key: 'wc_order_5150',
          payment_url: 'https://shop.example/checkout/order-pay/5150/?key=wc_order_5150',
        }), { status: 201, headers: { 'content-type': 'application/json' } });
      };
      try {
        const res = await post(server, '/api/designs', {
          customerName: 'Smoke Test',
          customerEmail: 'multi-line@example.com',
          params: PARAMS,
          productIds: [8634, 8635],
        });
        assert.equal(res.status, 200);
        assert.match(res.body.checkoutUrl, /order-pay\/5150/);
        const row = designStatus(res.body.designId);
        assert.equal(row.status, 'checkout_created');
        assert.equal(row.wc_order_id, '5150');
        assert.deepEqual(captured.line_items.map((line) => line.product_id), [8634, 8635]);
        assert.equal(captured.meta_data.find((m) => m.key === 'design_id').value, res.body.designId);
        for (const line of captured.line_items) {
          assert.equal(line.meta_data.find((m) => m.key === 'design_id').value, res.body.designId);
          assert.equal(line.meta_data.find((m) => m.key === 'geometry_summary').value, SUMMARY);
        }
      } finally {
        global.fetch = original;
      }
    });
  });

  test('POST /api/designs applies WC_PRODUCT_PRICE only when productIds is omitted', async () => {
    await withEnv({ ...CATALOGUE, WC_PRODUCT_PRICE: '49.00' }, async () => {
      const original = global.fetch;
      const bodies = [];
      global.fetch = async (url, opts) => {
        if (String(url).includes('/notes')) {
          return new Response(JSON.stringify({ id: 1 }), {
            status: 201,
            headers: { 'content-type': 'application/json' },
          });
        }
        bodies.push(JSON.parse(opts.body));
        const id = 6100 + bodies.length;
        return new Response(JSON.stringify({
          id,
          order_key: `k${id}`,
          payment_url: `https://shop.example/checkout/order-pay/${id}/?key=k${id}`,
        }), { status: 201, headers: { 'content-type': 'application/json' } });
      };
      try {
        const omitted = await post(server, '/api/designs', {
          customerName: 'Smoke Test',
          customerEmail: 'price-omitted@example.com',
          params: PARAMS,
        });
        assert.equal(omitted.status, 200);
        assert.equal(bodies[0].line_items[0].product_id, 8634);
        assert.equal(bodies[0].line_items[0].total, '49.00');

        const other = await post(server, '/api/designs', {
          customerName: 'Smoke Test',
          customerEmail: 'price-other@example.com',
          params: PARAMS,
          productIds: [8636],
        });
        assert.equal(other.status, 200);
        assert.equal(bodies[1].line_items[0].product_id, 8636);
        assert.equal(bodies[1].line_items[0].total, undefined);
        assert.equal(bodies[1].line_items[0].subtotal, undefined);

        const explicitDefault = await post(server, '/api/designs', {
          customerName: 'Smoke Test',
          customerEmail: 'price-explicit-default@example.com',
          params: PARAMS,
          productIds: [8634],
        });
        assert.equal(explicitDefault.status, 200);
        assert.equal(bodies[2].line_items[0].product_id, 8634);
        assert.equal(bodies[2].line_items[0].total, undefined);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('POST /api/designs without productIds still orders WC_PRODUCT_ID', async () => {
    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      let captured;
      global.fetch = async (url, opts) => {
        if (String(url).includes('/notes')) {
          return new Response(JSON.stringify({ id: 1 }), {
            status: 201,
            headers: { 'content-type': 'application/json' },
          });
        }
        captured = JSON.parse(opts.body);
        return new Response(JSON.stringify({ id: 8, order_key: 'k' }), {
          status: 201,
          headers: { 'content-type': 'application/json' },
        });
      };
      try {
        const res = await post(server, '/api/designs', {
          customerName: 'Smoke Test',
          customerEmail: 'single-default@example.com',
          params: PARAMS,
        });
        assert.equal(res.status, 200);
        assert.deepEqual(captured.line_items.map((line) => line.product_id), [8634]);
        assert.match(res.body.checkoutUrl, /order-pay\/8/);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('a paid multi-line order found by wc_order_id marks the design paid', async () => {
    const id = 'design-by-order';
    insertDesign({ id, wcOrderId: '9001' });
    const res = await post(server, '/api/webhooks/woocommerce/order-updated', {
      id: 9001,
      status: 'processing',
      meta_data: [
        { key: 'design_id', value: id },
        { key: 'geometry_summary', value: SUMMARY },
      ],
      line_items: [
        { product_id: 8634, quantity: 1, meta_data: [{ key: 'design_id', value: id }] },
        { product_id: 8635, quantity: 1, meta_data: [{ key: 'design_id', value: id }] },
        { product_id: 8636, quantity: 1, meta_data: [{ key: 'design_id', value: 'someone-else' }] },
      ],
    });
    assert.equal(res.status, 200);
    assert.equal((await waitForStatus(id, 'paid')).status, 'paid');
    assert.equal(designStatus('someone-else'), undefined);
  });

  test('a completed order with design_id only on line items still marks the design paid', async () => {
    const id = 'design-by-line';
    insertDesign({ id, status: 'pending', wcOrderId: null });
    const res = await post(server, '/api/webhooks/woocommerce/order-updated', {
      id: 9002,
      status: 'completed',
      meta_data: [],
      line_items: [
        { product_id: 8634, meta_data: [] },
        {
          product_id: 8637,
          meta_data: [
            { key: 'design_id', value: id },
            { key: 'geometry_summary', value: SUMMARY },
          ],
        },
      ],
    });
    assert.equal(res.status, 200);
    assert.equal((await waitForStatus(id, 'paid')).status, 'paid');
  });

  test('unpaid multi-line updates do not mark the design paid', async () => {
    const id = 'design-pending';
    insertDesign({ id, wcOrderId: '9003' });
    const res = await post(server, '/api/webhooks/woocommerce/order-updated', {
      id: 9003,
      status: 'pending',
      meta_data: [{ key: 'design_id', value: id }],
      line_items: [
        { product_id: 8634, meta_data: [{ key: 'design_id', value: id }] },
        { product_id: 8635, meta_data: [{ key: 'design_id', value: id }] },
      ],
    });
    assert.equal(res.status, 200);
    await new Promise((resolve) => setTimeout(resolve, 30));
    assert.equal(designStatus(id).status, 'checkout_created');
  });

  test('webhook HMAC still gates a multi-line paid payload', async () => {
    const id = 'design-hmac';
    insertDesign({ id, wcOrderId: '9004' });
    const body = JSON.stringify({
      id: 9004,
      status: 'processing',
      meta_data: [{ key: 'design_id', value: id }],
      line_items: [
        { product_id: 8634, meta_data: [{ key: 'design_id', value: id }] },
        { product_id: 8635, meta_data: [{ key: 'design_id', value: id }] },
      ],
    });
    await withEnv({ WC_WEBHOOK_SECRET: 'test-webhook-secret' }, async () => {
      const bad = await post(server, '/api/webhooks/woocommerce/order-updated', body, {
        'x-wc-webhook-signature': 'not-the-signature',
      });
      assert.equal(bad.status, 401);
      assert.equal(designStatus(id).status, 'checkout_created');

      const sig = crypto.createHmac('sha256', 'test-webhook-secret').update(body).digest('base64');
      const ok = await post(server, '/api/webhooks/woocommerce/order-updated', body, {
        'x-wc-webhook-signature': sig,
      });
      assert.equal(ok.status, 200);
    });
    assert.equal((await waitForStatus(id, 'paid')).status, 'paid');
  });

  test('production rejects an unsigned webhook when the secret is unset', async () => {
    const id = 'design-prod-hmac';
    insertDesign({ id, wcOrderId: '9005' });
    const prev = process.env.NODE_ENV;
    process.env.NODE_ENV = 'production';
    delete process.env.WC_WEBHOOK_SECRET;
    try {
      const res = await post(server, '/api/webhooks/woocommerce/order-updated', {
        id: 9005,
        status: 'processing',
        meta_data: [{ key: 'design_id', value: id }],
        line_items: [
          { product_id: 8634, meta_data: [{ key: 'design_id', value: id }] },
          { product_id: 8636, meta_data: [{ key: 'design_id', value: id }] },
        ],
      });
      assert.equal(res.status, 401);
      await new Promise((resolve) => setTimeout(resolve, 30));
      assert.equal(designStatus(id).status, 'checkout_created');
    } finally {
      if (prev === undefined) delete process.env.NODE_ENV;
      else process.env.NODE_ENV = prev;
    }
  });

  test('cancelling or refunding a paid design updates its status once', async () => {
    const cancelled = 'design-cancel';
    insertDesign({ id: cancelled, status: 'paid', wcOrderId: '9010' });
    const orderBody = (id, wooId, status) => ({
      id: wooId,
      status,
      meta_data: [{ key: 'design_id', value: id }],
      line_items: [
        { product_id: 8634, meta_data: [{ key: 'design_id', value: id }] },
      ],
    });
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(cancelled, 9010, 'cancelled'))).status, 200);
    assert.equal((await waitForStatus(cancelled, 'cancelled')).status, 'cancelled');
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(cancelled, 9010, 'cancelled'))).status, 200);
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(designStatus(cancelled).status, 'cancelled');

    const refunded = 'design-refund';
    insertDesign({ id: refunded, status: 'in_review', wcOrderId: '9011' });
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(refunded, 9011, 'refunded'))).status, 200);
    assert.equal((await waitForStatus(refunded, 'refunded')).status, 'refunded');
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(refunded, 9011, 'cancelled'))).status, 200);
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(designStatus(refunded).status, 'refunded');

    const failed = 'design-failed-then-paid';
    insertDesign({ id: failed, status: 'checkout_created', wcOrderId: '9013' });
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(failed, 9013, 'failed'))).status, 200);
    assert.equal((await waitForStatus(failed, 'failed')).status, 'failed');
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(failed, 9013, 'processing'))).status, 200);
    assert.equal((await waitForStatus(failed, 'paid')).status, 'paid');

    const expired = 'design-expired-cancel';
    insertDesign({ id: expired, status: 'expired', wcOrderId: '9012' });
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', orderBody(expired, 9012, 'cancelled'))).status, 200);
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(designStatus(expired).status, 'expired');

    const untouched = 'design-not-this-order';
    insertDesign({ id: untouched, status: 'paid', wcOrderId: '8888' });
    assert.equal((await post(server, '/api/webhooks/woocommerce/order-updated', {
      id: 9099,
      status: 'cancelled',
      meta_data: [],
      line_items: [{ product_id: 100, meta_data: [] }],
    })).status, 200);
    await new Promise((resolve) => setTimeout(resolve, 40));
    assert.equal(designStatus(untouched).status, 'paid');
    assert.equal(designStatus('missing-design'), undefined);
  });
});
