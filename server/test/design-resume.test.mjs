import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const dbPath = path.join(os.tmpdir(), `creature-resume-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.WC_PRODUCT_ID;
delete process.env.WC_PRODUCT_IDS;
delete process.env.WC_PRODUCT_PRICE;
delete process.env.SMTP_HOST;
delete process.env.SMTP_USER;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;
delete process.env.EMAIL_FROM;
delete process.env.EMAIL_REPLY_TO;
delete process.env.FRAME_DESIGNER_URL;

const require = createRequire(import.meta.url);
const db = require('../db');
const designs = require('../routes/designs');
const email = require('../services/email');

const PARAMS = {
  reach: 450,
  chainstay_length: 430,
  ht_angle: 64.5,
  st_angle: 76,
  bb_drop: 30,
};

const CATALOGUE = {
  WC_URL: 'https://shop.example',
  WC_CONSUMER_KEY: 'ck_test',
  WC_CONSUMER_SECRET: 'cs_test',
  WC_PRODUCT_IDS: '8634, 8635, 8636, 8637',
  WC_PRODUCT_ID: '8634',
  BASE_URL: 'https://tools.example',
};

const ENV_KEYS = [
  'WC_URL', 'WC_CONSUMER_KEY', 'WC_CONSUMER_SECRET',
  'WC_PRODUCT_ID', 'WC_PRODUCT_IDS', 'WC_PRODUCT_PRICE',
  'BASE_URL', 'SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'RESEND_API_KEY',
  'EMAIL_FROM', 'EMAIL_REPLY_TO', 'FRAME_DESIGNER_URL',
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
        resolve({ status: res.statusCode, headers: res.headers, body: parsed, raw });
      });
    });
    req.on('error', reject);
    if (payload) req.end(payload);
    else req.end();
  });
}

function rowFor(id) {
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(id);
}

function wooOrder(id, status = 'pending') {
  return new Response(JSON.stringify({
    id,
    status,
    order_key: `wc_order_${id}`,
    payment_url: `https://shop.example/checkout/order-pay/${id}/?key=wc_order_${id}`,
  }), { status: status === 'missing' ? 404 : 201, headers: { 'content-type': 'application/json' } });
}

const app = express();
app.use(express.json({ limit: '1mb' }));
app.use('/api/designs', designs);

describe('design resume links', { concurrency: false }, () => {
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

  test('save email copy has both links and skips Resend when the key is unset', async () => {
    const editUrl = 'https://creaturecycles.co.uk/apps/frame-designer.html?design=abcdef12-rest&resume=tok';
    const checkoutUrl = 'https://tools.example/api/designs/abcdef12-rest/checkout?resume=tok';
    const message = email.designSavedMessage({
      customerName: 'Ada Lovelace',
      designName: 'Night Train',
      designId: 'abcdef12-rest',
      editUrl,
      checkoutUrl,
    });
    assert.equal(message.subject, 'Your Creature Cycles design is saved');
    assert.equal(message.preheader, 'Open it again to edit, or continue to checkout when you\u2019re ready.');
    assert.match(message.text, /^Hi Ada,/);
    assert.match(message.text, /Your frame design Night Train is saved with Creature Cycles\./);
    assert.match(message.text, /Dropouts are coming soon/);
    assert.match(message.text, /Edit or revisit your design:\nhttps:\/\/creaturecycles\.co\.uk\/apps\/frame-designer\.html\?design=/);
    assert.match(message.text, /Dropouts are coming soon\.\nhttps:\/\/tools\.example\/api\/designs\//);
    assert.equal(message.text.includes('Design ID:'), false);
    assert.equal(message.text.includes('Edit design:'), false);
    assert.match(message.html, />\s*Edit design\s*</);
    assert.match(message.html, />\s*Take me to checkout\s*</);
    assert.match(message.html, /src="https:\/\/creaturecycles\.co\.uk\/wp-content\/uploads\/2026\/10\/creature-logo-email-1\.png"/);
    assert.match(message.html, /alt="Creature Cycles"/);
    assert.match(message.html, /width="220"/);
    assert.equal(/<h1[\s>]/i.test(message.html), false);
    assert.match(message.text, /Thanks,\nCreature Cycles\ninfo@creaturecycles\.co\.uk$/);
    assert.match(message.html, /they\u2019ll join the same flow when they\u2019re ready/);
    assert.equal(/£\s*\d/.test(message.text + message.html), false);
    const unnamed = email.designSavedMessage({
      customerName: '',
      designName: '',
      designId: 'abcdef12-rest',
      editUrl,
      checkoutUrl,
    });
    assert.match(unnamed.text, /^Hi,/);
    assert.equal(unnamed.text.includes('Hi ,'), false);
    assert.match(unnamed.text, /Your frame design is saved with Creature Cycles\./);

    const originalFetch = global.fetch;
    let resendCalls = 0;
    try {
      global.fetch = async (url) => {
        if (String(url).includes('api.resend.com')) resendCalls += 1;
        throw new Error(`unexpected fetch ${url}`);
      };
      process.env.SMTP_HOST = 'smtp.resend.com';
      process.env.SMTP_PORT = '465';
      process.env.SMTP_USER = 'resend';
      delete process.env.SMTP_PASS;
      delete process.env.RESEND_API_KEY;
      await email.sendOrderConfirmation({
        to: 'ada@example.com',
        customerName: 'Ada',
        designId: 'abcdef12-rest',
        editUrl: 'https://creaturecycles.co.uk/apps/frame-designer.html?design=1&resume=tok',
        checkoutUrl: 'https://tools.example/api/designs/1/checkout?resume=tok',
      });
      assert.equal(resendCalls, 0);
      assert.equal(email.isConfigured(), false);

      const sent = [];
      global.fetch = async (url, opts = {}) => {
        const headers = opts.headers || {};
        sent.push({
          url: String(url),
          authorization: headers.Authorization || headers.authorization,
          body: JSON.parse(opts.body),
        });
        return new Response(JSON.stringify({ id: 'email_alias' }), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        });
      };
      process.env.RESEND_API_KEY = 're_alias';
      assert.equal(email.isConfigured(), true);
      await email.sendOrderConfirmation({
        to: 'ada@example.com',
        customerName: 'Ada',
        designName: 'Night Train',
        designId: 'abcdef12-rest',
        editUrl,
        checkoutUrl,
      });
      process.env.SMTP_PASS = 're_smtp_pass';
      assert.equal(email.isConfigured(), true);
      await email.sendOrderConfirmation({
        to: 'ada@example.com',
        customerName: 'Ada',
        designName: 'Night Train',
        designId: 'abcdef12-rest',
        editUrl,
        checkoutUrl,
      });
      assert.equal(sent.length, 2);
      assert.equal(sent[0].url, 'https://api.resend.com/emails');
      assert.equal(sent[0].authorization, 'Bearer re_alias');
      assert.equal(sent[1].authorization, 'Bearer re_smtp_pass');
      assert.equal(sent[1].body.from, '"Creature Cycles" <info@creaturecycles.co.uk>');
      assert.equal(sent[1].body.reply_to, 'info@creaturecycles.co.uk');
      assert.deepEqual(sent[1].body.to, ['ada@example.com']);
      assert.equal(sent[1].body.subject, 'Your Creature Cycles design is saved');
      assert.match(sent[1].body.html, /src="https:\/\/creaturecycles\.co\.uk\/wp-content\/uploads\/2026\/10\/creature-logo-email-1\.png"/);
      assert.equal(/<h1[\s>]/i.test(sent[1].body.html), false);
      assert.match(sent[1].body.text, /Thanks,\nCreature Cycles\n/);

      await email.sendPaymentConfirmation({
        to: 'ada@example.com',
        customerName: 'Ada Lovelace',
        designId: 'abcdef12-rest',
      });
      assert.equal(sent.length, 3);
      assert.match(sent[2].body.html, /src="https:\/\/creaturecycles\.co\.uk\/wp-content\/uploads\/2026\/10\/creature-logo-email-1\.png"/);
      assert.match(sent[2].body.html, /alt="Creature Cycles"/);
      assert.equal(/<h1[\s>]/i.test(sent[2].body.html), false);
      assert.match(sent[2].body.text, /– Creature Cycles/);
    } finally {
      delete process.env.SMTP_HOST;
      delete process.env.SMTP_PORT;
      delete process.env.SMTP_USER;
      delete process.env.SMTP_PASS;
      delete process.env.RESEND_API_KEY;
      global.fetch = originalFetch;
    }
  });

  test('POST stores resume_token and product_ids and emails both links', async () => {
    const sent = [];
    await withEnv({
      ...CATALOGUE,
      SMTP_PASS: 'secret',
    }, async () => {
      const original = global.fetch;
      global.fetch = async (url, opts = {}) => {
        if (String(url) === 'https://api.resend.com/emails') {
          const headers = opts.headers || {};
          sent.push({
            authorization: headers.Authorization || headers.authorization,
            ...JSON.parse(opts.body),
          });
          return new Response(JSON.stringify({ id: 'email_test' }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
          });
        }
        return wooOrder(5150);
      };
      try {
        const res = await request(server, 'POST', '/api/designs', {
          customerName: 'Ada Lovelace',
          customerEmail: 'Ada@Example.com',
          designName: '  Night Train  ',
          params: PARAMS,
          productIds: [8635, 8634, 8634],
        });
        assert.equal(res.status, 200);
        assert.equal(res.body.resumeToken, undefined);
        assert.equal(res.body.resume, undefined);
        assert.match(res.body.checkoutUrl, /order-pay\/5150/);

        const row = rowFor(res.body.designId);
        assert.equal(row.status, 'checkout_created');
        assert.match(row.resume_token, /^[a-f0-9]{64}$/);
        assert.equal(row.design_name, 'Night Train');
        assert.equal(db.prepare(`SELECT datetime(resume_expires_at) > datetime('now', '+89 days') AS ok FROM designs WHERE id = ?`).get(row.id).ok, 1);
        assert.deepEqual(JSON.parse(row.product_ids), [8635, 8634]);
        assert.equal(sent.length, 1);
        assert.equal(sent[0].authorization, 'Bearer secret');
        assert.equal(sent[0].from, '"Creature Cycles" <info@creaturecycles.co.uk>');
        assert.equal(sent[0].reply_to, 'info@creaturecycles.co.uk');
        assert.deepEqual(sent[0].to, ['ada@example.com']);
        assert.equal(sent[0].subject, 'Your Creature Cycles design is saved');
        const edit = `https://creaturecycles.co.uk/apps/frame-designer.html?design=${row.id}&resume=${row.resume_token}`;
        const checkout = `https://tools.example/api/designs/${row.id}/checkout?resume=${row.resume_token}`;
        assert.match(sent[0].text, /Hi Ada,/);
        assert.match(sent[0].text, /Dropouts are coming soon/);
        assert.ok(sent[0].text.includes(`Edit or revisit your design:\n${edit}`));
        assert.ok(sent[0].text.includes(`Dropouts are coming soon.\n${checkout}`));
        assert.match(sent[0].html, />\s*Edit design\s*</);
        assert.match(sent[0].html, />\s*Take me to checkout\s*</);
        assert.ok(sent[0].html.includes(edit.replaceAll('&', '&amp;')));
        assert.ok(sent[0].html.includes(checkout.replaceAll('&', '&amp;')));
        assert.equal(JSON.stringify(res.body).includes(row.resume_token), false);

        const implicit = await request(server, 'POST', '/api/designs', {
          customerName: 'Ada Lovelace',
          customerEmail: 'ada-default@example.com',
          params: PARAMS,
        });
        assert.equal(implicit.status, 200);
        assert.deepEqual(JSON.parse(rowFor(implicit.body.designId).product_ids), [8634]);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('POST returns without waiting for a hung Resend send', async () => {
    await withEnv({
      ...CATALOGUE,
      SMTP_PASS: 're_hang',
    }, async () => {
      const original = global.fetch;
      let resendStarted = 0;
      global.fetch = (url, opts = {}) => {
        if (String(url) === 'https://api.resend.com/emails') {
          resendStarted += 1;
          return new Promise((_resolve, reject) => {
            const signal = opts.signal;
            if (signal) {
              const onAbort = () => {
                const reason = signal.reason instanceof Error ? signal.reason : new Error('aborted');
                reject(reason);
              };
              if (signal.aborted) onAbort();
              else signal.addEventListener('abort', onAbort, { once: true });
            }
          });
        }
        return wooOrder(5400);
      };
      try {
        const started = Date.now();
        const res = await request(server, 'POST', '/api/designs', {
          customerName: 'Ada Lovelace',
          customerEmail: 'ada-hang@example.com',
          params: PARAMS,
          productIds: [8634],
        });
        const elapsed = Date.now() - started;
        assert.equal(res.status, 200);
        assert.ok(res.body.designId);
        assert.equal(res.body.message, 'Design saved. Proceed to checkout.');
        assert.equal(resendStarted, 1);
        assert.ok(elapsed < 1500, `save waited ${elapsed}ms on mail`);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('hydrate returns the design only when the resume token matches', async () => {
    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      global.fetch = async () => wooOrder(5200);
      try {
        const saved = await request(server, 'POST', '/api/designs', {
          customerName: 'Grace Hopper',
          customerEmail: 'grace@example.com',
          params: PARAMS,
          productIds: [8634],
        });
        const row = rowFor(saved.body.designId);
        const bare = await request(server, 'GET', `/api/designs/${row.id}`);
        assert.equal(bare.status, 404);
        assert.equal(bare.raw.includes('grace@example.com'), false);

        const wrong = await request(server, 'GET', `/api/designs/${row.id}?resume=not-the-token`);
        assert.equal(wrong.status, 404);
        assert.equal(wrong.raw.includes('grace@example.com'), false);

        const ok = await request(server, 'GET', `/api/designs/${row.id}?resume=${row.resume_token}`);
        assert.equal(ok.status, 200);
        assert.deepEqual(Object.keys(ok.body).sort(), [
          'checkoutUrl', 'customerEmail', 'customerName', 'designId', 'params', 'productIds',
        ]);
        assert.equal(ok.body.designId, row.id);
        assert.deepEqual(ok.body.params, PARAMS);
        assert.deepEqual(ok.body.productIds, [8634]);
        assert.equal(ok.body.customerName, 'Grace Hopper');
        assert.equal(ok.body.customerEmail, 'grace@example.com');
        assert.match(ok.body.checkoutUrl, /order-pay\/5200/);
        assert.equal(ok.raw.includes(row.resume_token), false);

        db.prepare(`UPDATE designs SET status = 'paid' WHERE id = ?`).run(row.id);
        const retired = await request(server, 'GET', `/api/designs/${row.id}?resume=${row.resume_token}`);
        assert.equal(retired.status, 404);
        assert.equal(retired.raw.includes('grace@example.com'), false);

        db.prepare(`UPDATE designs SET status = 'checkout_created', resume_expires_at = datetime('now', '-1 day') WHERE id = ?`).run(row.id);
        const stale = await request(server, 'GET', `/api/designs/${row.id}?resume=${row.resume_token}`);
        assert.equal(stale.status, 404);
        const stalePay = await request(server, 'GET', `/api/designs/${row.id}/checkout?resume=${row.resume_token}`);
        assert.equal(stalePay.status, 404);
      } finally {
        global.fetch = original;
      }
    });
  });

  test('checkout redirects to the stored order and recreates a cancelled one', async () => {
    await withEnv(CATALOGUE, async () => {
      const original = global.fetch;
      let mode = 'pending';
      let nextId = 5300;
      const posts = [];
      global.fetch = async (_url, opts = {}) => {
        const method = opts.method || 'GET';
        if (method === 'POST') {
          posts.push(JSON.parse(opts.body));
          const id = nextId;
          nextId += 1;
          return wooOrder(id);
        }
        if (mode === 'missing') {
          return new Response(JSON.stringify({ message: 'Invalid ID.' }), {
            status: 404,
            headers: { 'content-type': 'application/json' },
          });
        }
        return new Response(JSON.stringify({
          id: 5300,
          status: mode === 'cancelled' ? 'cancelled' : 'pending',
        }), { status: 200, headers: { 'content-type': 'application/json' } });
      };
      try {
        const saved = await request(server, 'POST', '/api/designs', {
          customerName: 'Grace Hopper',
          customerEmail: 'grace-pay@example.com',
          params: PARAMS,
          productIds: [8634, 8635],
        });
        const row = rowFor(saved.body.designId);
        const payPath = `/api/designs/${row.id}/checkout?resume=${row.resume_token}`;

        const same = await request(server, 'GET', payPath);
        assert.equal(same.status, 302);
        assert.match(same.headers.location, /order-pay\/5300/);
        assert.equal(posts.length, 1);

        const noToken = await request(server, 'GET', `/api/designs/${row.id}/checkout`);
        assert.equal(noToken.status, 404);

        mode = 'cancelled';
        const replaced = await request(server, 'GET', payPath);
        assert.equal(replaced.status, 302);
        assert.match(replaced.headers.location, /order-pay\/5301/);
        assert.equal(rowFor(row.id).wc_order_id, '5301');
        assert.deepEqual(posts.at(-1).line_items.map((line) => line.product_id), [8634, 8635]);

        mode = 'missing';
        const gone = await request(server, 'GET', payPath);
        assert.equal(gone.status, 302);
        assert.match(gone.headers.location, /order-pay\/5302/);
        assert.equal(rowFor(row.id).wc_order_id, '5302');
      } finally {
        global.fetch = original;
      }
    });
  });

  test('checkout refuses a stored URL that is not http(s)', async () => {
    const id = 'design-bad-redirect';
    const token = 'ab'.repeat(32);
    db.prepare(`
      INSERT INTO designs (
        id, customer_name, customer_email, params, status, resume_token, wc_checkout_url
      ) VALUES (?, 'Ada', 'ada-redirect@example.com', '{}', 'pending', ?, ?)
    `).run(id, token, 'javascript:alert(1)');
    const res = await request(server, 'GET', `/api/designs/${id}/checkout?resume=${token}`);
    assert.equal(res.status, 502);
    assert.equal(res.headers.location, undefined);
  });
});
