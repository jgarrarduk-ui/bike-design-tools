import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import { parentPort, workerData } from 'node:worker_threads';
import express from 'express';

const dbPath = workerData.dbPath;
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;
delete process.env.RETENTION_UNPAID_DAYS;
delete process.env.RETENTION_PAID_DAYS;
delete process.env.RETENTION_PURGE_INTERVAL_HOURS;
delete process.env.RETENTION_PURGE_MAX;
delete process.env.RETENTION_INVALID_ID_STREAK;
delete process.env.RETENTION_INVALID_ID_FRACTION;
delete process.env.ADMIN_PASSWORD;
delete process.env.WC_WEBHOOK_SECRET;
if (workerData.dryRun === null) delete process.env.RETENTION_PURGE_DRY_RUN;
else process.env.RETENTION_PURGE_DRY_RUN = workerData.dryRun == null ? '0' : workerData.dryRun;

const require = createRequire(import.meta.url);
const SAMPLE = 'jane.doe@example.com';
const RESUME_DONE = "datetime('now', '-1 day')";

if (workerData.name === 'migration') {
  const Database = require('better-sqlite3');
  const raw = new Database(dbPath);
  raw.exec(`
    CREATE TABLE designs (
      id TEXT PRIMARY KEY,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      customer_name TEXT NOT NULL,
      customer_email TEXT NOT NULL,
      params TEXT NOT NULL,
      pdf_base64 TEXT,
      status TEXT NOT NULL DEFAULT 'pending',
      wc_order_id TEXT,
      wc_checkout_url TEXT,
      download_token TEXT,
      download_expires_at DATETIME,
      delivered_at DATETIME
    );
  `);
  raw.prepare(`
    INSERT INTO designs (id, customer_name, customer_email, params, status, pdf_base64)
    VALUES ('legacy-1', 'Legacy Name', ?, '{"reach":450}', 'paid', 'cGRm')
  `).run(SAMPLE);
  raw.close();
}

const db = require('../db');
const retention = require('../services/retention-purge');
const email = require('../services/email');

function insert(fields) {
  db.prepare(`
    INSERT INTO designs (
      id, created_at, paid_at, customer_name, customer_email, params,
      pdf_base64, status, resume_token, wc_checkout_url, resume_expires_at,
      wc_order_id, accepted_at, delivered_at, review_sent_at, download_token,
      review_token, review_pdf_base64, download_expires_at, design_name, anonymised_at
    ) VALUES (
      ?, ${fields.createdSql}, ${fields.paidSql || 'NULL'}, ?, ?, '{"reach":450}',
      'cGRm', ?, 'resume-' || ?, 'https://shop.example/checkout',
      ${fields.expiresSql || "datetime('now', '+1 day')"},
      ?, ${fields.acceptedSql || 'NULL'}, ${fields.deliveredSql || 'NULL'},
      ${fields.reviewSql || 'NULL'}, ?, ?, ?, ${fields.downloadExpiresSql || 'NULL'},
      ?, ${fields.anonymisedSql || 'NULL'}
    )
  `).run(
    fields.id,
    fields.name || 'Jane Doe',
    fields.email || SAMPLE,
    fields.status,
    fields.id,
    fields.wcOrderId || null,
    fields.downloadToken || null,
    fields.reviewToken || null,
    fields.reviewPdf || null,
    fields.designName || "Jane's gravel bike",
  );
}

function keeper(id = 'keeper') {
  insert({ id, status: 'pending', createdSql: "datetime('now', '-1 day')" });
}

function row(id) {
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(id);
}

function woo(getOrder, checkApi = async () => true) {
  return { getOrder, checkApi };
}

function taggedOrder(orderId, designId, status, extra = {}) {
  return {
    id: orderId,
    status,
    meta_data: [{ key: 'design_id', value: designId }],
    ...extra,
  };
}

function withCancel(getOrder) {
  const cancelled = new Set();
  const calls = [];
  return {
    calls,
    options: {
      checkApi: async () => true,
      cancelOrder: async (orderId) => {
        calls.push(String(orderId));
        cancelled.add(String(orderId));
        return { id: orderId, status: 'cancelled' };
      },
      getOrder: async (orderId) => {
        if (cancelled.has(String(orderId))) {
          return { id: orderId, status: 'cancelled', date_paid_gmt: null };
        }
        return getOrder(orderId);
      },
    },
  };
}

function listen(app) {
  return new Promise((resolve) => {
    const server = app.listen(0, '127.0.0.1', () => resolve(server));
  });
}

function request(server, urlPath, { method = 'GET', json, headers } = {}) {
  return new Promise((resolve, reject) => {
    const payload = json == null ? null : Buffer.from(JSON.stringify(json));
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: urlPath,
      method,
      headers: {
        ...(payload ? { 'content-type': 'application/json', 'content-length': payload.length } : {}),
        ...(headers || {}),
      },
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf8');
        let parsed = body;
        try { parsed = JSON.parse(body); } catch { /* text */ }
        resolve({ status: res.statusCode, body, json: parsed, headers: res.headers });
      });
    });
    req.on('error', reject);
    if (payload) req.end(payload);
    else req.end();
  });
}

const cases = {
  async 'unpaid-window'() {
    insert({ id: 'unpaid-89', status: 'pending', createdSql: "datetime('now', '-89 days')" });
    insert({ id: 'unpaid-91', status: 'cancelled', createdSql: "datetime('now', '-91 days')", expiresSql: RESUME_DONE });
    insert({ id: 'failed-91', status: 'failed', createdSql: "datetime('now', '-91 days')", expiresSql: RESUME_DONE });
    keeper('keep-a');
    keeper('keep-b');
    const result = await retention.run();
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['failed-91', 'unpaid-91']);
    assert.equal(row('unpaid-89').customer_email, SAMPLE);
    assert.equal(row('unpaid-89').anonymised_at, null);
    const old = row('unpaid-91');
    assert.equal(old.customer_name, 'redacted');
    assert.equal(old.customer_email, 'redacted+unpaid-91@invalid');
    assert.equal(old.design_name, null);
    assert.equal(old.pdf_base64, null);
    assert.equal(old.status, 'cancelled');
    assert.equal(old.params, '{"reach":450}');
    assert.equal(row('failed-91').customer_email, 'redacted+failed-91@invalid');
    const again = await retention.run();
    assert.deepEqual(again.designIds, []);
    assert.equal(row('unpaid-91').customer_email, old.customer_email);
  },

  async 'paid-clock'() {
    insert({
      id: 'paid-young',
      status: 'paid',
      createdSql: "datetime('now', '-3000 days')",
      paidSql: "datetime('now', '-2556 days')",
    });
    insert({
      id: 'paid-old',
      status: 'delivered',
      createdSql: "datetime('now', '-10 days')",
      paidSql: "datetime('now', '-2558 days')",
    });
    insert({
      id: 'refunded-recent',
      status: 'refunded',
      createdSql: "datetime('now', '-91 days')",
      paidSql: "datetime('now', '-90 days')",
    });
    insert({
      id: 'paid-no-stamp',
      status: 'in_review',
      createdSql: "datetime('now', '-2556 days')",
    });
    const result = await retention.run();
    assert.deepEqual(result.designIds, ['paid-old']);
    assert.equal(row('paid-young').anonymised_at, null);
    assert.equal(row('refunded-recent').customer_email, SAMPLE);
    assert.equal(row('paid-no-stamp').customer_email, SAMPLE);
    assert.equal(row('paid-no-stamp').anonymised_at, null);
    assert.ok(result.skippedPaidSignals.includes('paid-no-stamp'));
    assert.equal(row('paid-old').customer_email, 'redacted+paid-old@invalid');
  },

  async 'uses-latest-paid-signal'() {
    insert({
      id: 'accepted-old',
      status: 'failed',
      createdSql: "datetime('now', '-10 days')",
      acceptedSql: "datetime('now', '-2558 days')",
    });
    insert({
      id: 'accepted-young',
      status: 'failed',
      createdSql: "datetime('now', '-3000 days')",
      acceptedSql: "datetime('now', '-10 days')",
    });
    keeper();
    const result = await retention.run();
    assert.deepEqual(result.designIds, ['accepted-old']);
    assert.equal(row('accepted-young').anonymised_at, null);
    assert.equal(row('accepted-young').customer_email, SAMPLE);
  },

  async 'dry-run'() {
    insert({ id: 'dry-91', status: 'expired', createdSql: "datetime('now', '-91 days')", expiresSql: RESUME_DONE });
    insert({
      id: 'dry-paid',
      status: 'delivered',
      createdSql: "datetime('now', '-10 days')",
      paidSql: "datetime('now', '-2558 days')",
    });
    insert({
      id: 'dry-backfill',
      status: 'pending',
      createdSql: "datetime('now', '-91 days')",
      wcOrderId: '77001',
    });
    keeper('keep-a');
    keeper('keep-b');
    const lines = [];
    const log = console.log;
    console.log = (...args) => lines.push(args.map(String).join(' '));
    try {
      const result = await retention.run(woo(async () => ({
        id: 77001,
        status: 'completed',
        date_paid_gmt: '2024-06-01T12:00:00Z',
      })));
      assert.equal(result.dryRun, true);
      assert.equal(result.aborted, false);
      assert.deepEqual(result.wouldAnonymiseUnpaid, ['dry-91']);
      assert.deepEqual(result.wouldAnonymisePaid, ['dry-paid']);
      assert.ok(result.skippedPaidSignals.includes('dry-backfill'));
      const blob = lines.join('\n');
      assert.match(blob, /dry-run would-anonymise unpaid 1: dry-91/);
      assert.match(blob, /dry-run would-anonymise paid 1: dry-paid/);
      assert.match(blob, /dry-run skipped as paid-signals/);
      assert.match(blob, /dry-run skipped as Woo unknown/);
      assert.match(blob, /dry-run unresolved past 365d/);
      assert.match(blob, /dry-run skipped as still payable/);
      assert.equal(blob.includes(SAMPLE), false);
      assert.equal(blob.includes('Jane'), false);
    } finally {
      console.log = log;
    }
    assert.equal(row('dry-91').customer_email, SAMPLE);
    assert.equal(row('dry-91').anonymised_at, null);
    assert.equal(row('dry-paid').customer_email, SAMPLE);
    assert.equal(row('dry-backfill').paid_at, null);
  },

  async 'endpoint-404s'() {
    insert({
      id: 'files-old',
      status: 'delivered',
      createdSql: "datetime('now', '-10 days')",
      paidSql: "datetime('now', '-2558 days')",
      downloadToken: 'dl-files-old',
      reviewToken: 'rv-files-old',
      reviewPdf: 'cGRm',
      downloadExpiresSql: "datetime('now', '+1 day')",
      email: 'purge-me@example.com',
      designName: 'Secret Frame Name',
    });
    keeper();
    const designs = require('../routes/designs');
    const download = require('../routes/download');
    const review = require('../routes/review');
    const app = express();
    app.use('/api/designs', designs);
    app.use('/api/download', download);
    app.use('/api/review', review);
    const server = await listen(app);
    try {
      const beforeFile = await request(server, '/api/download/dl-files-old');
      const beforePreview = await request(server, '/api/review/preview/rv-files-old');
      assert.notEqual(beforeFile.status, 404);
      assert.equal(beforePreview.status, 200);
      const result = await retention.run();
      assert.deepEqual(result.designIds, ['files-old']);
      assert.equal(row('files-old').download_token, null);
      assert.equal(row('files-old').review_token, null);
      assert.equal(row('files-old').design_name, null);
      const file = await request(server, '/api/download/dl-files-old');
      const preview = await request(server, '/api/review/preview/rv-files-old');
      const resume = await request(server, '/api/designs/files-old?resume=resume-files-old');
      assert.equal(file.status, 404);
      assert.equal(preview.status, 404);
      assert.equal(resume.status, 404);
      assert.equal(file.body.includes(SAMPLE), false);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const files = [dbPath];
    if (fs.existsSync(`${dbPath}-wal`)) files.push(`${dbPath}-wal`);
    for (const file of files) {
      const bytes = fs.readFileSync(file);
      assert.equal(bytes.includes(Buffer.from('purge-me@example.com')), false, file);
      assert.equal(bytes.includes(Buffer.from('Secret Frame Name')), false, file);
    }
    assert.equal(db.pragma('secure_delete', { simple: true }), 1);
    assert.equal(String(db.pragma('journal_mode', { simple: true })).toLowerCase(), 'wal');
  },

  async 'log-mask-and-links'() {
    const lines = [];
    const warn = console.warn;
    const error = console.error;
    const info = console.info;
    const push = (...args) => lines.push(args.map(String).join(' '));
    console.warn = push;
    console.error = push;
    console.info = push;
    const previous = global.fetch;
    try {
      await email.sendOrderConfirmation({
        to: SAMPLE,
        customerName: 'Jane Doe',
        designId: 'design-log',
        editUrl: 'https://secret.example/edit?resume=abc',
        checkoutUrl: 'https://secret.example/checkout?resume=abc',
      });
      await email.sendDesignReview({
        to: SAMPLE,
        customerName: 'Jane Doe',
        designId: 'design-log',
        previewUrl: 'https://secret.example/preview/tok',
        acceptUrl: 'https://secret.example/accept/tok',
      });
      await email.sendDesignAccepted({
        to: SAMPLE,
        customerName: 'Jane Doe',
        designId: 'design-log',
        downloadUrl: 'https://secret.example/download/tok',
      });
      process.env.SMTP_PASS = 're_test_key';
      global.fetch = async () => ({
        ok: false,
        status: 422,
        text: async () => `Invalid to field ${SAMPLE}`,
        arrayBuffer: async () => new ArrayBuffer(0),
      });
      await assert.rejects(
        () => email.sendPaymentConfirmation({
          to: SAMPLE,
          customerName: 'Jane Doe',
          designId: 'design-log',
        }),
        (err) => {
          lines.push(String(err && err.message));
          assert.equal(String(err.message).includes(SAMPLE), false);
          assert.match(String(err.message), /j\*\*\*@e\*\*\*\.com/);
          return true;
        },
      );
    } finally {
      console.warn = warn;
      console.error = error;
      console.info = info;
      global.fetch = previous;
      delete process.env.SMTP_PASS;
    }
    const blob = lines.join('\n');
    assert.equal(blob.includes(SAMPLE), false);
    assert.equal(blob.includes('secret.example'), false);
    assert.match(blob, /j\*\*\*@e\*\*\*\.com/);
  },

  async 'failed-after-accept'() {
    insert({
      id: 'failed-after-accept',
      status: 'failed',
      createdSql: "datetime('now', '-91 days')",
      acceptedSql: "datetime('now', '-90 days')",
      downloadToken: 'dl-failed-after-accept',
    });
    const result = await retention.run();
    assert.equal(result.designIds.includes('failed-after-accept'), false);
    assert.ok(result.skippedPaidSignals.includes('failed-after-accept'));
    const kept = row('failed-after-accept');
    assert.equal(kept.status, 'failed');
    assert.equal(kept.download_token, 'dl-failed-after-accept');
    assert.equal(kept.customer_email, SAMPLE);
    assert.equal(kept.anonymised_at, null);
  },

  async 'webhook-cancel-keeps-paid'() {
    insert({
      id: 'paid-then-cancelled',
      status: 'paid',
      createdSql: "datetime('now', '-200 days')",
      paidSql: "datetime('now', '-200 days')",
      wcOrderId: '88001',
    });
    const before = row('paid-then-cancelled').paid_at;
    const webhooks = require('../routes/webhooks');
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await listen(app);
    try {
      const res = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88001, status: 'cancelled' },
      });
      assert.equal(res.status, 200);
      let updated = row('paid-then-cancelled');
      for (let i = 0; i < 40 && updated.status !== 'cancelled'; i += 1) {
        await new Promise((resolve) => setTimeout(resolve, 15));
        updated = row('paid-then-cancelled');
      }
      assert.equal(updated.status, 'cancelled');
      assert.equal(updated.paid_at, before);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const result = await retention.run();
    assert.equal(result.designIds.includes('paid-then-cancelled'), false);
    assert.equal(row('paid-then-cancelled').paid_at, before);
    assert.equal(row('paid-then-cancelled').customer_email, SAMPLE);
  },

  async 'backfill-gmt'() {
    insert({
      id: 'backfill-paid',
      status: 'pending',
      createdSql: "datetime('now', '-91 days')",
      wcOrderId: '88002',
    });
    const getOrder = async () => ({
      id: 88002,
      status: 'completed',
      date_paid: '2024-06-01T13:00:00',
      date_paid_gmt: '2024-06-01T12:00:00Z',
    });
    process.env.RETENTION_PURGE_DRY_RUN = '1';
    const preview = await retention.run(woo(getOrder));
    assert.equal(row('backfill-paid').paid_at, null);
    assert.ok(preview.skippedPaidSignals.includes('backfill-paid'));
    process.env.RETENTION_PURGE_DRY_RUN = '0';
    const result = await retention.run(woo(getOrder));
    assert.equal(result.designIds.includes('backfill-paid'), false);
    assert.equal(row('backfill-paid').paid_at, '2024-06-01 12:00:00');
    assert.equal(row('backfill-paid').customer_email, SAMPLE);
    assert.equal(row('backfill-paid').status, 'pending');
  },

  async 'woo-row-error-skips'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    insert({
      id: 'woo-down',
      status: 'pending',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88003',
      expiresSql: RESUME_DONE,
    });
    let calls = 0;
    const result = await retention.run(woo(async () => {
      calls += 1;
      const err = new Error('connect ECONNREFUSED');
      throw err;
    }));
    assert.equal(result.aborted, false);
    assert.equal(result.reason, null);
    assert.equal(calls, 1);
    assert.deepEqual(result.designIds, ['still-here']);
    assert.ok(result.skippedWooError.includes('woo-down'));
    assert.equal(row('woo-down').anonymised_at, null);
    assert.equal(row('woo-down').customer_email, SAMPLE);
    assert.equal(row('still-here').customer_email, 'redacted+still-here@invalid');
  },

  async 'woo-auth-aborts'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    insert({
      id: 'auth-row',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88031',
      expiresSql: RESUME_DONE,
    });
    const result = await retention.run(woo(async () => {
      const err = new Error('unauthorized');
      err.status = 401;
      throw err;
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-auth');
    assert.equal(row('still-here').anonymised_at, null);
    assert.equal(row('auth-row').customer_email, SAMPLE);
  },

  async 'woo-error-streak-aborts'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    for (let i = 0; i < 20; i += 1) {
      insert({
        id: `err-${String(i).padStart(2, '0')}`,
        status: 'expired',
        createdSql: "datetime('now', '-120 days')",
        wcOrderId: String(88100 + i),
        expiresSql: RESUME_DONE,
      });
    }
    let calls = 0;
    const result = await retention.run(woo(async () => {
      calls += 1;
      const err = new Error('timeout');
      err.status = 504;
      throw err;
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-error');
    assert.equal(calls, 4);
    assert.equal(row('still-here').anonymised_at, null);
    assert.equal(row('err-00').customer_email, SAMPLE);
  },

  async 'woo-error-fraction-aborts'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    for (let i = 0; i < 4; i += 1) {
      insert({
        id: `mix-${i}`,
        status: 'expired',
        createdSql: "datetime('now', '-120 days')",
        wcOrderId: String(88200 + i),
        expiresSql: RESUME_DONE,
      });
    }
    let calls = 0;
    const result = await retention.run(woo(async () => {
      calls += 1;
      if (calls === 2) return { status: 'pending' };
      const err = new Error('timeout');
      err.status = 504;
      throw err;
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-error');
    assert.equal(calls, 3);
    assert.equal(row('still-here').anonymised_at, null);
    assert.equal(row('mix-0').customer_email, SAMPLE);
  },

  async 'api-check-aborts'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({
      id: 'needs-woo',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88009',
    });
    let calls = 0;
    const result = await retention.run({
      checkApi: async () => { throw new Error('system_status failed'); },
      getOrder: async () => { calls += 1; return { status: 'cancelled' }; },
    });
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-api');
    assert.equal(calls, 0);
    assert.equal(row('still-here').customer_email, SAMPLE);
    assert.equal(row('needs-woo').anonymised_at, null);
  },

  async 'generic-404-skips-row'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    insert({
      id: 'bad-route',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88010',
      expiresSql: RESUME_DONE,
    });
    const result = await retention.run(woo(async () => {
      const err = new Error('not found');
      err.status = 404;
      err.code = 'rest_no_route';
      throw err;
    }));
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['still-here']);
    assert.ok(result.skippedWooError.includes('bad-route'));
    assert.equal(row('bad-route').anonymised_at, null);
    assert.equal(row('bad-route').customer_email, SAMPLE);
  },

  async 'invalid-order-id-is-kept'() {
    insert({ id: 'no-order', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    insert({
      id: 'gone-order',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88011',
      expiresSql: RESUME_DONE,
    });
    keeper();
    const result = await retention.run(woo(async () => null));
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['no-order']);
    assert.equal(row('gone-order').customer_email, SAMPLE);
    assert.ok(result.skippedWooUnknown.includes('gone-order'));
  },

  async 'payable-orders-kept'() {
    for (const [id, status] of [
      ['hold', 'on-hold'],
      ['failed-order', 'failed'],
      ['pending-order', 'pending'],
      ['us-cancelled', 'canceled'],
    ]) {
      insert({
        id,
        status: 'expired',
        createdSql: "datetime('now', '-120 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: id,
      });
    }
    const result = await retention.run(woo(async (orderId) => ({
      id: orderId,
      status: orderId === 'us-cancelled' ? 'canceled' : (
        orderId === 'hold' ? 'on-hold' : orderId === 'failed-order' ? 'failed' : 'pending'
      ),
      date_paid_gmt: null,
    })));
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, []);
    for (const id of ['hold', 'failed-order', 'pending-order', 'us-cancelled']) {
      assert.equal(row(id).anonymised_at, null);
      assert.equal(row(id).customer_email, SAMPLE);
      assert.ok(result.skippedStillPayable.includes(id));
    }
  },

  async 'cancelled-order-is-purged'() {
    insert({
      id: 'woo-cancelled',
      status: 'checkout_created',
      createdSql: "datetime('now', '-91 days')",
      wcOrderId: '88012',
    });
    insert({
      id: 'woo-trash',
      status: 'pending',
      createdSql: "datetime('now', '-91 days')",
      wcOrderId: '88013',
    });
    insert({
      id: 'woo-cancelled-old',
      status: 'checkout_created',
      createdSql: "datetime('now', '-91 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88014',
    });
    insert({
      id: 'woo-trash-old',
      status: 'pending',
      createdSql: "datetime('now', '-91 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88015',
    });
    keeper('keep-a');
    keeper('keep-b');
    const result = await retention.run(woo(async (orderId) => ({
      id: orderId,
      status: String(orderId) === '88013' || String(orderId) === '88015' ? 'trash' : 'cancelled',
    })));
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['woo-cancelled-old', 'woo-trash-old']);
    assert.equal(row('woo-cancelled').customer_email, SAMPLE);
    assert.equal(row('woo-cancelled').resume_token, 'resume-woo-cancelled');
    assert.ok(result.skippedStillPayable.includes('woo-cancelled'));
    assert.ok(result.skippedStillPayable.includes('woo-trash'));
    assert.equal(row('woo-cancelled-old').customer_email, 'redacted+woo-cancelled-old@invalid');
    assert.equal(row('woo-trash-old').customer_email, 'redacted+woo-trash-old@invalid');
  },

  async 'resumable-without-order-kept'() {
    process.env.RETENTION_UNPAID_DAYS = '30';
    insert({
      id: 'still-resumable',
      status: 'pending',
      createdSql: "datetime('now', '-40 days')",
      expiresSql: "datetime('now', '+10 days')",
    });
    const result = await retention.run();
    assert.equal(result.designIds.includes('still-resumable'), false);
    assert.ok(result.skippedStillPayable.includes('still-resumable'));
    assert.equal(row('still-resumable').resume_token, 'resume-still-resumable');
  },

  async 'expired-resume-without-order-purged'() {
    insert({
      id: 'resume-done',
      status: 'pending',
      createdSql: "datetime('now', '-100 days')",
      expiresSql: "datetime('now', '-1 day')",
    });
    keeper();
    const result = await retention.run();
    assert.deepEqual(result.designIds, ['resume-done']);
    assert.equal(row('resume-done').customer_email, 'redacted+resume-done@invalid');
  },

  async 'expired-purged'() {
    insert({ id: 'expired-91', status: 'expired', createdSql: "datetime('now', '-91 days')", expiresSql: RESUME_DONE });
    keeper();
    const result = await retention.run();
    assert.deepEqual(result.designIds, ['expired-91']);
    assert.equal(row('expired-91').customer_email, 'redacted+expired-91@invalid');
    assert.equal(row('expired-91').design_name, null);
    assert.equal(row('expired-91').status, 'expired');
  },

  async 'cancelled-backlog-drains'() {
    for (let i = 0; i < 60; i += 1) {
      const n = String(i).padStart(2, '0');
      insert({
        id: `c-${n}`,
        status: 'expired',
        createdSql: "datetime('now', '-120 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: String(91000 + i),
      });
    }
    const getOrder = async (orderId) => ({ id: orderId, status: 'cancelled' });
    for (let pass = 0; pass < 3; pass += 1) {
      const result = await retention.run(woo(getOrder));
      assert.equal(result.aborted, false);
      assert.equal(result.reason, null);
      const expected = [];
      for (let i = pass * 20; i < (pass + 1) * 20; i += 1) {
        expected.push(`c-${String(i).padStart(2, '0')}`);
      }
      assert.deepEqual(result.designIds, expected);
      assert.equal(result.deferredIds.length, 40 - pass * 20);
    }
    const done = await retention.run(woo(getOrder));
    assert.deepEqual(done.designIds, []);
    for (let i = 0; i < 60; i += 1) {
      const id = `c-${String(i).padStart(2, '0')}`;
      assert.equal(row(id).customer_email, `redacted+${id}@invalid`);
      assert.ok(row(id).anonymised_at);
    }
  },

  async 'unresolved-cutoff'() {
    insert({
      id: 'hold-364',
      status: 'pending',
      createdSql: "datetime('now', '-364 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88364',
    });
    insert({
      id: 'hold-366',
      status: 'pending',
      createdSql: "datetime('now', '-366 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88366',
    });
    insert({
      id: 'hold-live',
      status: 'pending',
      createdSql: "datetime('now', '-366 days')",
      expiresSql: "datetime('now', '+10 days')",
      wcOrderId: '88367',
    });
    insert({
      id: 'invalid-366',
      status: 'expired',
      createdSql: "datetime('now', '-366 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88404',
    });
    insert({
      id: 'paid-366',
      status: 'paid',
      createdSql: "datetime('now', '-366 days')",
      paidSql: "datetime('now', '-10 days')",
      wcOrderId: '88370',
    });
    insert({
      id: 'woo-paid-366',
      status: 'pending',
      createdSql: "datetime('now', '-366 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88371',
    });
    insert({
      id: 'hold-race',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88372',
    });
    const shop = withCancel(async (orderId) => {
      const id = String(orderId);
      if (id === '88404') return null;
      if (id === '88372') {
        db.prepare(`
          UPDATE designs SET status = 'paid', paid_at = CURRENT_TIMESTAMP WHERE id = 'hold-race'
        `).run();
        return taggedOrder(orderId, 'hold-race', 'on-hold');
      }
      if (id === '88371') {
        return taggedOrder(orderId, 'woo-paid-366', 'on-hold', { date_paid: '2026-09-01T12:00:00' });
      }
      const designId = {
        88364: 'hold-364',
        88366: 'hold-366',
        88367: 'hold-live',
      }[id];
      return taggedOrder(orderId, designId, 'on-hold');
    });
    const result = await retention.run(shop.options);
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['hold-366', 'invalid-366']);
    assert.ok(result.wouldAnonymiseUnresolved.includes('hold-366'));
    assert.ok(result.wouldAnonymiseUnresolved.includes('invalid-366'));
    assert.equal(row('hold-364').customer_email, SAMPLE);
    assert.equal(row('hold-364').anonymised_at, null);
    assert.ok(result.skippedStillPayable.includes('hold-364'));
    assert.equal(row('hold-366').customer_email, 'redacted+hold-366@invalid');
    assert.equal(row('hold-live').customer_email, SAMPLE);
    assert.equal(row('hold-live').resume_token, 'resume-hold-live');
    assert.ok(result.skippedStillPayable.includes('hold-live'));
    assert.equal(row('invalid-366').customer_email, 'redacted+invalid-366@invalid');
    assert.equal(row('paid-366').customer_email, SAMPLE);
    assert.equal(row('paid-366').anonymised_at, null);
    assert.equal(row('woo-paid-366').customer_email, SAMPLE);
    assert.equal(row('woo-paid-366').anonymised_at, null);
    assert.ok(result.skippedPaidSignals.includes('woo-paid-366'));
    assert.equal(row('hold-race').status, 'paid');
    assert.equal(row('hold-race').customer_email, SAMPLE);
    assert.equal(row('hold-race').anonymised_at, null);
    assert.deepEqual(shop.calls, ['88366']);
    assert.deepEqual(result.cancelledOrderIds, ['hold-366']);
  },

  async 'paid-status-no-stamp'() {
    insert({
      id: 'stamp-young',
      status: 'paid',
      createdSql: "datetime('now', '-2556 days')",
    });
    insert({
      id: 'stamp-old',
      status: 'in_review',
      createdSql: "datetime('now', '-2558 days')",
    });
    const result = await retention.run();
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['stamp-old']);
    assert.equal(row('stamp-young').customer_email, SAMPLE);
    assert.equal(row('stamp-young').anonymised_at, null);
    assert.ok(result.skippedPaidSignals.includes('stamp-young'));
    assert.equal(row('stamp-old').customer_email, 'redacted+stamp-old@invalid');
    assert.ok(row('stamp-old').anonymised_at);
    assert.equal(row('stamp-old').status, 'in_review');
  },

  async 'unresolved-batch'() {
    process.env.RETENTION_PURGE_MAX = '20';
    for (let i = 0; i < 25; i += 1) {
      const n = String(i).padStart(2, '0');
      insert({
        id: `u-${n}`,
        status: 'pending',
        createdSql: "datetime('now', '-400 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: String(89000 + i),
      });
    }
    const shop = withCancel(async (orderId) => {
      const n = String(Number(orderId) - 89000).padStart(2, '0');
      return taggedOrder(orderId, `u-${n}`, 'on-hold');
    });
    const first = await retention.run(shop.options);
    assert.equal(first.aborted, false);
    assert.equal(first.reason, null);
    assert.equal(first.designIds.length, 20);
    assert.equal(first.deferredIds.length, 5);
    assert.equal(first.wouldAnonymiseUnresolved.length, 20);
    const second = await retention.run(shop.options);
    assert.equal(second.aborted, false);
    assert.equal(second.designIds.length, 5);
    assert.deepEqual(second.deferredIds, []);
    for (let i = 0; i < 25; i += 1) {
      const id = `u-${String(i).padStart(2, '0')}`;
      assert.equal(row(id).customer_email, `redacted+${id}@invalid`);
    }
  },

  async 'circuit-max'() {
    process.env.RETENTION_PURGE_MAX = '1';
    insert({ id: 'a', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    insert({ id: 'b', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    insert({ id: 'c', status: 'expired', createdSql: "datetime('now', '-120 days')", expiresSql: RESUME_DONE });
    keeper('keep-a');
    keeper('keep-b');
    const first = await retention.run();
    assert.equal(first.aborted, false);
    assert.equal(first.reason, null);
    assert.deepEqual(first.designIds, ['a']);
    assert.deepEqual(first.deferredIds, ['b', 'c']);
    assert.equal(row('a').customer_email, 'redacted+a@invalid');
    assert.equal(row('b').anonymised_at, null);
    assert.equal(row('c').customer_email, SAMPLE);
    const second = await retention.run();
    assert.deepEqual(second.designIds, ['b']);
    assert.equal(row('c').anonymised_at, null);
  },

  async 'race-recheck'() {
    insert({
      id: 'race-1',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88020',
    });
    insert({
      id: 'race-order',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88022',
    });
    insert({
      id: 'race-paid',
      status: 'delivered',
      createdSql: "datetime('now', '-10 days')",
      paidSql: "datetime('now', '-2558 days')",
      acceptedSql: "datetime('now', '-2558 days')",
    });
    keeper();
    const result = await retention.run(woo(async () => {
      db.prepare(`
        UPDATE designs
        SET status = 'paid', paid_at = CURRENT_TIMESTAMP
        WHERE id = 'race-1'
      `).run();
      db.prepare(`
        UPDATE designs
        SET wc_order_id = '99999', status = 'checkout_created'
        WHERE id = 'race-order'
      `).run();
      db.prepare(`UPDATE designs SET accepted_at = datetime('now') WHERE id = 'race-paid'`).run();
      return { id: 88020, status: 'cancelled' };
    }));
    assert.equal(result.aborted, false);
    assert.equal(result.designIds.includes('race-1'), false);
    assert.equal(result.designIds.includes('race-order'), false);
    assert.equal(result.designIds.includes('race-paid'), false);
    const kept = row('race-1');
    assert.equal(kept.status, 'paid');
    assert.ok(kept.paid_at);
    assert.equal(kept.customer_email, SAMPLE);
    assert.equal(kept.anonymised_at, null);
    assert.equal(kept.design_name, "Jane's gravel bike");
    assert.equal(row('race-order').wc_order_id, '99999');
    assert.equal(row('race-order').customer_email, SAMPLE);
    assert.equal(row('race-paid').customer_email, SAMPLE);
    assert.equal(row('race-paid').anonymised_at, null);
  },

  async 'overlap-lock'() {
    insert({
      id: 'locked',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88021',
    });
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const first = retention.run(woo(async () => {
      await gate;
      return { id: 88021, status: 'pending' };
    }));
    await new Promise((resolve) => setTimeout(resolve, 30));
    const second = await retention.run(woo(async () => ({ id: 88021, status: 'cancelled' })));
    assert.equal(second.aborted, true);
    assert.equal(second.reason, 'overlap');
    release();
    const finished = await first;
    assert.equal(finished.reason, null);
    assert.equal(row('locked').anonymised_at, null);
    db.prepare(`UPDATE designs SET resume_expires_at = datetime('now', '-1 day') WHERE id = 'locked'`).run();
    const stale = new Date(Date.now() - 31 * 60 * 1000).toISOString();
    db.prepare('UPDATE retention_lock SET owner = ?, acquired_at = ? WHERE id = 1').run('other-run', stale);
    const taken = await retention.run(woo(async () => ({ id: 88021, status: 'cancelled' })));
    assert.equal(taken.aborted, false);
    assert.equal(taken.reason, null);
    assert.equal(row('locked').customer_email, 'redacted+locked@invalid');
  },

  async 'anonymised-webhook'() {
    insert({
      id: 'already-gone',
      status: 'expired',
      createdSql: "datetime('now', '-200 days')",
      wcOrderId: '88030',
      anonymisedSql: "datetime('now', '-1 day')",
      email: SAMPLE,
    });
    const lines = [];
    const error = console.error;
    console.error = (...args) => lines.push(args.map(String).join(' '));
    global.fetch = async () => { throw new Error('resend should not be called'); };
    const webhooks = require('../routes/webhooks');
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await listen(app);
    try {
      const paid = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88030, status: 'processing' },
      });
      assert.equal(paid.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 40));
      assert.equal(row('already-gone').status, 'expired');
      assert.equal(row('already-gone').customer_email, SAMPLE);
      assert.ok(row('already-gone').retention_alerted_at);
      const again = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88030, status: 'completed' },
      });
      assert.equal(again.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 40));
      const cancelled = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88030, status: 'cancelled' },
      });
      assert.equal(cancelled.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 40));
      assert.equal(row('already-gone').status, 'expired');
    } finally {
      console.error = error;
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const blob = lines.join('\n');
    assert.match(blob, /design already-gone order 88030 status processing ignored/);
    assert.match(blob, /design already-gone order 88030 status completed ignored/);
    assert.match(blob, /design already-gone order 88030 status cancelled ignored/);
    assert.equal(blob.match(/retention alert not sent \(no API key\) design already-gone order 88030/g).length, 1);
    assert.equal(blob.includes(SAMPLE), false);
  },

  async 'admin-route'() {
    insert({ id: 'expired-91', status: 'expired', createdSql: "datetime('now', '-91 days')", expiresSql: RESUME_DONE });
    keeper();
    const admin = require('../routes/admin');
    const app = express();
    app.use('/api/admin', admin);
    const server = await listen(app);
    try {
      const closed = await request(server, '/api/admin/retention-purge', { method: 'POST' });
      assert.equal(closed.status, 503);
      assert.deepEqual(closed.json, { error: 'admin_not_configured' });
      assert.equal(row('expired-91').anonymised_at, null);

      process.env.ADMIN_PASSWORD = 'secret';
      const denied = await request(server, '/api/admin/retention-purge', { method: 'POST' });
      assert.equal(denied.status, 401);
      const allowed = await request(server, '/api/admin/retention-purge', {
        method: 'POST',
        headers: { authorization: 'Bearer secret' },
      });
      assert.equal(allowed.status, 200);
      assert.equal(allowed.json.ok, true);
      assert.equal(allowed.json.dryRun, false);
      assert.deepEqual(allowed.json.designIds, ['expired-91']);

      db.close();
      const broken = await request(server, '/api/admin/retention-purge', {
        method: 'POST',
        headers: { authorization: 'Bearer secret' },
      });
      assert.equal(broken.status, 500);
      assert.equal(JSON.stringify(broken.json).includes(SAMPLE), false);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
  },

  async 'admin-overlap'() {
    insert({
      id: 'locked',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88040',
    });
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    const first = retention.run(woo(async () => {
      await gate;
      return { status: 'pending' };
    }));
    await new Promise((resolve) => setTimeout(resolve, 30));
    const admin = require('../routes/admin');
    const app = express();
    app.use('/api/admin', admin);
    const server = await listen(app);
    try {
      process.env.ADMIN_PASSWORD = 'secret';
      const res = await request(server, '/api/admin/retention-purge', {
        method: 'POST',
        headers: { authorization: 'Bearer secret' },
      });
      assert.equal(res.status, 409);
      assert.equal(res.json.reason, 'overlap');
    } finally {
      release();
      await first;
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    assert.equal(row('locked').anonymised_at, null);
  },

  async 'migration'() {
    const legacy = db.prepare('SELECT customer_name, customer_email, pdf_base64 FROM designs WHERE id = ?').get('legacy-1');
    assert.equal(legacy.customer_name, 'Legacy Name');
    assert.equal(legacy.customer_email, SAMPLE);
    assert.equal(legacy.pdf_base64, 'cGRm');
    const cols = db.pragma('table_info(designs)').map((col) => col.name);
    for (const name of ['paid_at', 'anonymised_at', 'accepted_at', 'design_name', 'revision', 'retention_alerted_at', 'purge_cancelled_order_id']) {
      assert.ok(cols.includes(name), name);
    }
    assert.equal(db.pragma('secure_delete', { simple: true }), 1);
    delete require.cache[require.resolve('../db')];
    const db2 = require('../db');
    const again = db2.prepare('SELECT customer_email, pdf_base64 FROM designs WHERE id = ?').get('legacy-1');
    assert.equal(again.customer_email, SAMPLE);
    assert.equal(again.pdf_base64, 'cGRm');
  },

  async 'env-parsing'() {
    const errors = [];
    const warnings = [];
    const error = console.error;
    const warn = console.warn;
    console.error = (...args) => errors.push(args.map(String).join(' '));
    console.warn = (...args) => warnings.push(args.map(String).join(' '));
    try {
      delete process.env.RETENTION_UNPAID_DAYS;
      delete process.env.RETENTION_UNRESOLVED_DAYS;
      delete process.env.RETENTION_PAID_DAYS;
      delete process.env.RETENTION_PURGE_INTERVAL_HOURS;
      delete process.env.RETENTION_PURGE_DRY_RUN;
      assert.equal(retention.unpaidDays(), 90);
      assert.equal(retention.unresolvedDays(), 365);
      assert.equal(retention.paidDays(), 2557);
      assert.equal(retention.intervalHours(), 24);
      assert.equal(retention.dryRun(), true);
      assert.equal(retention.purgeMax(), 20);

      process.env.RETENTION_UNPAID_DAYS = '0';
      assert.equal(retention.unpaidDays(), 30);
      process.env.RETENTION_UNPAID_DAYS = '10';
      assert.equal(retention.unpaidDays(), 30);
      process.env.RETENTION_UNPAID_DAYS = 'abc';
      assert.equal(retention.unpaidDays(), 90);
      process.env.RETENTION_UNPAID_DAYS = '-5';
      assert.equal(retention.unpaidDays(), 90);
      process.env.RETENTION_PAID_DAYS = '2192';
      assert.equal(retention.paidDays(), 2557);
      process.env.RETENTION_PAID_DAYS = 'nope';
      assert.equal(retention.paidDays(), 2557);
      delete process.env.RETENTION_UNPAID_DAYS;
      process.env.RETENTION_UNRESOLVED_DAYS = '100';
      assert.equal(retention.unresolvedDays(), 365);
      process.env.RETENTION_UNRESOLVED_DAYS = 'nope';
      assert.equal(retention.unresolvedDays(), 365);
      process.env.RETENTION_UNPAID_DAYS = '400';
      process.env.RETENTION_UNRESOLVED_DAYS = '365';
      assert.equal(retention.unresolvedDays(), 400);
      delete process.env.RETENTION_UNPAID_DAYS;
      delete process.env.RETENTION_UNRESOLVED_DAYS;

      delete process.env.RETENTION_INVALID_ID_STREAK;
      delete process.env.RETENTION_INVALID_ID_FRACTION;
      assert.equal(retention.invalidIdStreak(), 3);
      assert.equal(retention.invalidIdFraction(), 0.2);
      process.env.RETENTION_INVALID_ID_STREAK = '5';
      assert.equal(retention.invalidIdStreak(), 5);
      process.env.RETENTION_INVALID_ID_STREAK = 'nope';
      assert.equal(retention.invalidIdStreak(), 3);
      process.env.RETENTION_INVALID_ID_FRACTION = '0.5';
      assert.equal(retention.invalidIdFraction(), 0.5);
      process.env.RETENTION_INVALID_ID_FRACTION = '2';
      assert.equal(retention.invalidIdFraction(), 0.2);
      process.env.RETENTION_INVALID_ID_FRACTION = 'nope';
      assert.equal(retention.invalidIdFraction(), 0.2);
      delete process.env.RETENTION_INVALID_ID_STREAK;
      delete process.env.RETENTION_INVALID_ID_FRACTION;

      process.env.RETENTION_PURGE_INTERVAL_HOURS = '0';
      assert.equal(retention.intervalHours(), 0);
      process.env.RETENTION_PURGE_INTERVAL_HOURS = 'abc';
      assert.equal(retention.intervalHours(), 24);
      process.env.RETENTION_PURGE_INTERVAL_HOURS = '-1';
      assert.equal(retention.intervalHours(), 24);
      process.env.RETENTION_PURGE_INTERVAL_HOURS = '1000';
      assert.equal(retention.intervalHours(), 168);
      process.env.RETENTION_PURGE_INTERVAL_HOURS = '0.5';
      assert.equal(retention.intervalHours(), 1);

      process.env.RETENTION_PURGE_DRY_RUN = '0';
      assert.equal(retention.dryRun(), false);
      process.env.RETENTION_PURGE_DRY_RUN = 'on';
      assert.equal(retention.dryRun(), true);
      process.env.RETENTION_PURGE_DRY_RUN = 'false';
      assert.equal(retention.dryRun(), true);
      process.env.RETENTION_PURGE_DRY_RUN = '';
      assert.equal(retention.dryRun(), true);
    } finally {
      console.error = error;
      console.warn = warn;
    }
    const blob = `${errors.join('\n')}\n${warnings.join('\n')}`;
    assert.match(blob, /RETENTION_UNPAID_DAYS=0 is below 30; using 30/);
    assert.match(blob, /RETENTION_UNPAID_DAYS=10 is below 30; using 30/);
    assert.match(blob, /RETENTION_UNPAID_DAYS=abc is not a number; using 90/);
    assert.match(blob, /RETENTION_PAID_DAYS=2192 is below 2557; using 2557/);
    assert.match(blob, /RETENTION_UNRESOLVED_DAYS=100 is below 365; using 365/);
    assert.match(blob, /RETENTION_UNRESOLVED_DAYS=nope is not a number; using 365/);
    assert.match(blob, /RETENTION_UNRESOLVED_DAYS=365 is below 400; using 400/);
    assert.match(blob, /RETENTION_PURGE_INTERVAL_HOURS=abc is not a number; using 24/);
    assert.match(blob, /RETENTION_PURGE_INTERVAL_HOURS=1000 exceeds 168; using 168/);
    assert.match(blob, /RETENTION_PURGE_INTERVAL_HOURS=0.5 is below 1; using 1/);
    assert.match(blob, /RETENTION_INVALID_ID_STREAK=nope is not a number; using 3/);
    assert.match(blob, /RETENTION_INVALID_ID_FRACTION=2 is not a fraction from 0 to 1; using 0.2/);
    assert.match(blob, /RETENTION_INVALID_ID_FRACTION=nope is not a fraction from 0 to 1; using 0.2/);
  },

  async 'dry-run-default-on'() {
    insert({ id: 'expired-91', status: 'expired', createdSql: "datetime('now', '-91 days')", expiresSql: RESUME_DONE });
    keeper();
    const result = await retention.run();
    assert.equal(result.dryRun, true);
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['expired-91']);
    assert.equal(row('expired-91').customer_email, SAMPLE);
    assert.equal(row('expired-91').anonymised_at, null);
    assert.deepEqual(result.writtenIds, []);
  },

  async 'woo-error-redacts-email'() {
    process.env.WC_URL = 'https://shop.example';
    process.env.WC_CONSUMER_KEY = 'ck_test';
    process.env.WC_CONSUMER_SECRET = 'cs_test';
    process.env.WC_PRODUCT_ID = '8634';
    const woocommerce = require('../services/woocommerce');
    const previous = global.fetch;
    global.fetch = async () => new Response(JSON.stringify({
      code: 'internal_server_error',
      message: `boom ${SAMPLE}`,
    }), { status: 500, headers: { 'content-type': 'application/json' } });
    try {
      await assert.rejects(
        () => woocommerce.getOrder('55'),
        (err) => {
          assert.equal(String(err.message).includes(SAMPLE), false);
          assert.equal(err.status, 500);
          assert.match(String(err.message), /j\*\*\*@e\*\*\*\.com/);
          return true;
        },
      );
    } finally {
      global.fetch = previous;
    }
  },

  async 'review-html-escape'() {
    insert({
      id: 'xss-review',
      status: 'in_review',
      createdSql: "datetime('now', '-1 day')",
      email: 'ann"><script>alert(1)</script>@example.com',
      reviewToken: 'rv-xss',
      reviewPdf: 'cGRm',
      name: 'Eve',
    });
    const review = require('../routes/review');
    const app = express();
    app.use('/api/review', review);
    const server = await listen(app);
    try {
      const res = await request(server, '/api/review/accept/rv-xss');
      assert.equal(res.status, 200);
      assert.equal(res.body.includes('<script>'), false);
      assert.match(res.body, /ann&quot;&gt;&lt;script&gt;alert\(1\)&lt;\/script&gt;@example.com/);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
  },

  async 'anonymised-change-link'() {
    insert({
      id: 'anon-change',
      status: 'paid',
      createdSql: "datetime('now', '-10 days')",
      anonymisedSql: "datetime('now', '-1 day')",
    });
    global.fetch = async () => { throw new Error('shop should not be called'); };
    const designs = require('../routes/designs');
    const app = express();
    app.use(express.json());
    app.use('/api/designs', designs);
    const server = await listen(app);
    try {
      const hydrate = await request(server, '/api/designs/anon-change?change=tok');
      assert.equal(hydrate.status, 404);
      const revise = await request(server, '/api/designs/anon-change/revision', {
        method: 'POST',
        json: { change: 'tok', params: { reach: 450 }, productIds: [8634] },
      });
      assert.equal(revise.status, 404);
      assert.equal(revise.body.includes(SAMPLE), false);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
  },

  async 'cancel-order-puts-cancelled'() {
    process.env.WC_URL = 'https://shop.example';
    process.env.WC_CONSUMER_KEY = 'ck_test';
    process.env.WC_CONSUMER_SECRET = 'cs_test';
    process.env.WC_PRODUCT_ID = '8634';
    const woocommerce = require('../services/woocommerce');
    const calls = [];
    const previous = global.fetch;
    global.fetch = async (url, opts) => {
      calls.push({ url: String(url), method: opts.method, body: opts.body });
      return new Response(JSON.stringify({ id: 55, status: 'cancelled' }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      });
    };
    try {
      const order = await woocommerce.cancelOrder('55');
      assert.equal(order.status, 'cancelled');
      assert.equal(calls.length, 1);
      assert.match(calls[0].url, /\/wp-json\/wc\/v3\/orders\/55$/);
      assert.equal(calls[0].method, 'PUT');
      assert.deepEqual(JSON.parse(calls[0].body), { status: 'cancelled' });
    } finally {
      global.fetch = previous;
    }
  },

  async 'cancel-then-wipe'() {
    for (const [id, wooStatus] of [
      ['pend-old', 'pending'],
      ['hold-old', 'on-hold'],
      ['fail-old', 'failed'],
    ]) {
      insert({
        id,
        status: wooStatus === 'failed' ? 'failed' : 'pending',
        createdSql: "datetime('now', '-400 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: id,
      });
    }
    const shop = withCancel(async (orderId) => taggedOrder(
      orderId,
      String(orderId),
      orderId === 'hold-old' ? 'on-hold' : orderId === 'fail-old' ? 'failed' : 'pending',
    ));
    const result = await retention.run(shop.options);
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['fail-old', 'hold-old', 'pend-old']);
    assert.deepEqual(result.cancelledOrderIds, ['fail-old', 'hold-old', 'pend-old']);
    assert.deepEqual(shop.calls, ['fail-old', 'hold-old', 'pend-old']);
    for (const id of ['pend-old', 'hold-old', 'fail-old']) {
      assert.equal(row(id).customer_email, `redacted+${id}@invalid`);
      assert.ok(row(id).anonymised_at);
    }
  },

  async 'cancel-fails-skips'() {
    insert({
      id: 'cancel-me',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88200',
      email: SAMPLE,
    });
    insert({
      id: 'orphan-old',
      status: 'expired',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
    });
    const lines = [];
    const error = console.error;
    const log = console.log;
    const push = (...args) => lines.push(args.map(String).join(' '));
    console.error = push;
    console.log = push;
    let cancels = 0;
    try {
      const result = await retention.run({
        checkApi: async () => true,
        cancelOrder: async () => {
          cancels += 1;
          return { status: 'cancelled' };
        },
        getOrder: async (orderId) => taggedOrder(orderId, 'cancel-me', 'on-hold'),
      });
      assert.equal(cancels, 1);
      assert.equal(result.aborted, false);
      assert.equal(result.reason, null);
      assert.deepEqual(result.designIds, ['orphan-old']);
      assert.deepEqual(result.cancelledOrderIds, []);
      assert.ok(result.skippedWooError.includes('cancel-me'));
      assert.equal(row('cancel-me').customer_email, SAMPLE);
      assert.equal(row('cancel-me').anonymised_at, null);
      assert.equal(row('cancel-me').wc_checkout_url, 'https://shop.example/checkout');
      assert.equal(row('orphan-old').customer_email, 'redacted+orphan-old@invalid');
    } finally {
      console.error = error;
      console.log = log;
    }
    const blob = lines.join('\n');
    assert.match(blob, /skipped design cancel-me; Woo cancel failed for order 88200 \(409\) j\*\*\*@e\*\*\*\.com/);
    assert.equal(blob.includes(SAMPLE), false);
    assert.equal(blob.includes('http'), false);
    assert.equal(blob.includes('shop.example'), false);
  },

  async 'cancel-error-aborts'() {
    for (let i = 0; i < 20; i += 1) {
      const n = String(i).padStart(2, '0');
      insert({
        id: `bad-${n}`,
        status: 'pending',
        createdSql: "datetime('now', '-400 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: `883${n}`,
      });
    }
    insert({
      id: 'orphan-old',
      status: 'expired',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
    });
    let cancels = 0;
    const result = await retention.run({
      checkApi: async () => true,
      cancelOrder: async () => {
        cancels += 1;
        const err = new Error('cancel failed');
        err.status = 500;
        throw err;
      },
      getOrder: async (orderId) => taggedOrder(orderId, `bad-${String(orderId).slice(-2)}`, 'pending'),
    });
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-error');
    assert.equal(cancels, 4);
    assert.deepEqual(result.designIds, []);
    assert.equal(row('orphan-old').customer_email, SAMPLE);
    assert.equal(row('orphan-old').anonymised_at, null);
    assert.equal(row('bad-00').anonymised_at, null);
    assert.equal(row('bad-19').customer_email, SAMPLE);
  },

  async 'cancel-dry-run'() {
    insert({
      id: 'dry-hold',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88400',
    });
    const lines = [];
    const log = console.log;
    console.log = (...args) => lines.push(args.map(String).join(' '));
    let cancels = 0;
    try {
      const result = await retention.run({
        checkApi: async () => true,
        cancelOrder: async () => {
          cancels += 1;
          throw new Error('dry run must not cancel');
        },
        getOrder: async (orderId) => ({ id: orderId, status: 'on-hold' }),
      });
      assert.equal(result.dryRun, true);
      assert.equal(result.aborted, false);
      assert.equal(cancels, 0);
      assert.deepEqual(result.wouldCancel, ['dry-hold']);
      assert.deepEqual(result.cancelledOrderIds, []);
      assert.deepEqual(result.wouldAnonymiseUnresolved, ['dry-hold']);
      assert.deepEqual(result.designIds, ['dry-hold']);
      assert.deepEqual(result.writtenIds, []);
    } finally {
      console.log = log;
    }
    assert.equal(row('dry-hold').customer_email, SAMPLE);
    assert.equal(row('dry-hold').anonymised_at, null);
    const blob = lines.join('\n');
    assert.match(blob, /dry-run would-cancel 1: dry-hold/);
    assert.equal(blob.includes(SAMPLE), false);
    assert.equal(blob.includes('http'), false);
  },

  async 'cancel-webhook-harmless'() {
    insert({
      id: 'fail-old',
      status: 'failed',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88100',
    });
    insert({
      id: 'pend-old',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88101',
    });
    const lines = [];
    const info = console.info;
    const error = console.error;
    const log = console.log;
    const push = (...args) => lines.push(args.map(String).join(' '));
    console.info = push;
    console.error = push;
    console.log = push;
    const fetches = [];
    const previous = global.fetch;
    process.env.SMTP_PASS = 're_test_key';
    global.fetch = async () => {
      fetches.push('resend');
      return { ok: true, status: 200, text: async () => '{}', json: async () => ({}) };
    };
    const webhooks = require('../routes/webhooks');
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await listen(app);
    const seen = [];
    const cancelled = new Set();
    const designFor = { 88100: 'fail-old', 88101: 'pend-old' };
    try {
      const result = await retention.run({
        checkApi: async () => true,
        cancelOrder: async (orderId) => {
          const posted = await request(server, '/api/webhooks/woocommerce/order-updated', {
            method: 'POST',
            json: { id: Number(orderId), status: 'cancelled' },
          });
          assert.equal(posted.status, 200);
          await new Promise((resolve) => setTimeout(resolve, 50));
          const id = designFor[String(orderId)];
          seen.push({ id, status: row(id).status, email: row(id).customer_email });
          cancelled.add(String(orderId));
          return { id: orderId, status: 'cancelled' };
        },
        getOrder: async (orderId) => {
          const id = String(orderId);
          if (cancelled.has(id)) return { id: orderId, status: 'cancelled' };
          if (id === '88100') return taggedOrder(orderId, 'fail-old', 'failed');
          return taggedOrder(orderId, 'pend-old', 'on-hold');
        },
      });
      assert.equal(result.aborted, false);
      assert.deepEqual(result.designIds, ['fail-old', 'pend-old']);
      assert.deepEqual(seen, [
        { id: 'fail-old', status: 'failed', email: SAMPLE },
        { id: 'pend-old', status: 'pending', email: SAMPLE },
      ]);
      assert.equal(fetches.length, 0);
      const stamped = row('fail-old').anonymised_at;
      const again = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88100, status: 'cancelled' },
      });
      assert.equal(again.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 50));
      assert.equal(row('fail-old').status, 'failed');
      assert.equal(row('fail-old').customer_email, 'redacted+fail-old@invalid');
      assert.equal(row('fail-old').anonymised_at, stamped);
      assert.equal(row('pend-old').status, 'pending');
      assert.equal(row('pend-old').customer_email, 'redacted+pend-old@invalid');
      assert.equal(fetches.length, 0);
    } finally {
      console.info = info;
      console.error = error;
      console.log = log;
      global.fetch = previous;
      delete process.env.SMTP_PASS;
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const blob = lines.join('\n');
    assert.match(blob, /design fail-old order 88100 status cancelled ignored; retention purge is cancelling this order/);
    assert.match(blob, /design pend-old order 88101 status cancelled ignored; retention purge is cancelling this order/);
    assert.match(blob, /design fail-old order 88100 status cancelled ignored; design is anonymised/);
    assert.equal(blob.includes('Payment confirmed'), false);
    assert.equal(blob.includes(SAMPLE), false);
    assert.equal(blob.includes('http'), false);
  },

  async 'invalid-id-aborts'() {
    for (let i = 0; i < 20; i += 1) {
      const n = String(i).padStart(2, '0');
      insert({
        id: `gone-${n}`,
        status: 'expired',
        createdSql: "datetime('now', '-400 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: `885${n}`,
      });
    }
    insert({
      id: 'orphan-old',
      status: 'expired',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
    });
    let calls = 0;
    const result = await retention.run(woo(async (orderId) => {
      calls += 1;
      const n = String(orderId).slice(-2);
      if (Number(n) < 4) return null;
      return { id: orderId, status: 'cancelled' };
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'invalid-id');
    assert.equal(calls, 4);
    assert.deepEqual(result.designIds, []);
    assert.equal(row('orphan-old').customer_email, SAMPLE);
    assert.equal(row('orphan-old').anonymised_at, null);
    assert.equal(row('gone-00').anonymised_at, null);
    assert.equal(row('gone-19').customer_email, SAMPLE);
  },

  async 'invalid-id-fraction-aborts'() {
    for (const id of ['a-gone', 'b-real', 'c-gone', 'd-real']) {
      insert({
        id,
        status: 'expired',
        createdSql: "datetime('now', '-120 days')",
        expiresSql: RESUME_DONE,
        wcOrderId: id,
      });
    }
    insert({
      id: 'orphan-old',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      expiresSql: RESUME_DONE,
    });
    let calls = 0;
    const result = await retention.run(woo(async (orderId) => {
      calls += 1;
      if (String(orderId) === 'a-gone' || String(orderId) === 'c-gone') return null;
      return { id: orderId, status: 'cancelled' };
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'invalid-id');
    assert.equal(calls, 3);
    assert.deepEqual(result.designIds, []);
    for (const id of ['a-gone', 'b-real', 'c-gone', 'd-real', 'orphan-old']) {
      assert.equal(row(id).customer_email, SAMPLE);
      assert.equal(row(id).anonymised_at, null);
    }
  },

  async 'cancel-readback-paid-alerts'() {
    insert({
      id: 'paid-back',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88600',
    });
    const fetches = [];
    const previous = global.fetch;
    process.env.SMTP_PASS = 're_test_key';
    global.fetch = async (url, opts) => {
      fetches.push({ url: String(url), body: opts && opts.body });
      return {
        ok: true,
        status: 200,
        text: async () => '{}',
        json: async () => ({}),
        arrayBuffer: async () => new ArrayBuffer(0),
      };
    };
    const lines = [];
    const error = console.error;
    console.error = (...args) => lines.push(args.map(String).join(' '));
    let calls = 0;
    let cancels = 0;
    try {
      const result = await retention.run({
        checkApi: async () => true,
        cancelOrder: async () => {
          cancels += 1;
          return { status: 'cancelled' };
        },
        getOrder: async (orderId) => {
          calls += 1;
          if (calls < 3) return taggedOrder(orderId, 'paid-back', 'pending');
          return taggedOrder(orderId, 'paid-back', 'cancelled', { date_paid_gmt: '2026-10-14T08:00:00' });
        },
      });
      assert.equal(cancels, 1);
      assert.equal(result.aborted, false);
      assert.deepEqual(result.designIds, []);
      assert.deepEqual(result.cancelledOrderIds, []);
      assert.equal(row('paid-back').anonymised_at, null);
      assert.equal(row('paid-back').customer_email, SAMPLE);
      assert.equal(row('paid-back').status, 'pending');
      assert.ok(row('paid-back').retention_alerted_at);
      assert.equal(fetches.length, 1);
      const payload = JSON.parse(fetches[0].body);
      assert.deepEqual(payload.to, ['info@creaturecycles.co.uk']);
      const blob = `${payload.subject}\n${payload.text}\n${payload.html}`;
      assert.match(blob, /j\*\*\*@e\*\*\*\.com/);
      assert.equal(blob.includes(SAMPLE), false);
      assert.equal(/https?:/i.test(blob), false);
      assert.equal(blob.includes('mailto:'), false);
      assert.equal(blob.includes('shop.example'), false);

      const again = await retention.run({
        checkApi: async () => true,
        cancelOrder: async () => {
          cancels += 1;
          throw new Error('second run must not cancel');
        },
        getOrder: async (orderId) => taggedOrder(orderId, 'paid-back', 'cancelled', {
          date_paid_gmt: '2026-10-14T08:00:00',
        }),
      });
      assert.equal(cancels, 1);
      assert.equal(fetches.length, 1);
      assert.equal(again.designIds.includes('paid-back'), false);
      assert.equal(row('paid-back').anonymised_at, null);
      assert.equal(row('paid-back').customer_email, SAMPLE);
    } finally {
      console.error = error;
      global.fetch = previous;
      delete process.env.SMTP_PASS;
    }
    const log = lines.join('\n');
    assert.match(log, /read back paid \(cancelled\) j\*\*\*@e\*\*\*\.com/);
    assert.equal(log.includes(SAMPLE), false);
    assert.equal(log.includes('http'), false);
  },

  async 'cancel-refetch-blocks'() {
    insert({
      id: 'paid-race',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88701',
    });
    insert({
      id: 'draft-race',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88702',
    });
    const seen = {};
    const cancels = [];
    const result = await retention.run({
      checkApi: async () => true,
      cancelOrder: async (orderId) => {
        cancels.push(String(orderId));
        return { status: 'cancelled' };
      },
      getOrder: async (orderId) => {
        const id = String(orderId);
        seen[id] = (seen[id] || 0) + 1;
        if (id === '88701') {
          if (seen[id] === 1) return taggedOrder(orderId, 'paid-race', 'on-hold');
          return taggedOrder(orderId, 'paid-race', 'processing', { date_paid: '2026-10-01T00:00:00' });
        }
        if (seen[id] === 1) return taggedOrder(orderId, 'draft-race', 'pending');
        return taggedOrder(orderId, 'draft-race', 'checkout-draft');
      },
    });
    assert.deepEqual(cancels, []);
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, []);
    assert.equal(row('paid-race').anonymised_at, null);
    assert.equal(row('paid-race').customer_email, SAMPLE);
    assert.equal(row('draft-race').anonymised_at, null);
    assert.equal(row('draft-race').customer_email, SAMPLE);
    assert.equal(row('draft-race').wc_checkout_url, 'https://shop.example/checkout');
  },

  async 'cancel-design-mismatch'() {
    insert({
      id: 'mismatch',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88710',
    });
    let calls = 0;
    const cancels = [];
    const result = await retention.run({
      checkApi: async () => true,
      cancelOrder: async () => {
        cancels.push('called');
        throw new Error('must not cancel');
      },
      getOrder: async (orderId) => {
        calls += 1;
        if (calls === 1) return taggedOrder(orderId, 'mismatch', 'pending');
        return taggedOrder(orderId, 'other-design', 'pending');
      },
    });
    assert.deepEqual(cancels, []);
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, []);
    assert.equal(row('mismatch').anonymised_at, null);
    assert.equal(row('mismatch').customer_email, SAMPLE);
    assert.equal(row('mismatch').status, 'pending');
  },

  async 'cancel-paid-webhook-processed'() {
    insert({
      id: 'pay-hook',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88800',
    });
    const lines = [];
    const info = console.info;
    const error = console.error;
    const log = console.log;
    const push = (...args) => lines.push(args.map(String).join(' '));
    console.info = push;
    console.error = push;
    console.log = push;
    const fetches = [];
    const previous = global.fetch;
    process.env.SMTP_PASS = 're_test_key';
    global.fetch = async (url, opts) => {
      fetches.push({ url: String(url), body: opts && opts.body });
      return {
        ok: true,
        status: 200,
        text: async () => '{}',
        json: async () => ({}),
        arrayBuffer: async () => new ArrayBuffer(0),
      };
    };
    const webhooks = require('../routes/webhooks');
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await listen(app);
    let phase = 'before';
    try {
      const result = await retention.run({
        checkApi: async () => true,
        cancelOrder: async () => {
          const posted = await request(server, '/api/webhooks/woocommerce/order-updated', {
            method: 'POST',
            json: { id: 88800, status: 'processing' },
          });
          assert.equal(posted.status, 200);
          await new Promise((resolve) => setTimeout(resolve, 50));
          assert.equal(row('pay-hook').status, 'paid');
          assert.ok(row('pay-hook').paid_at);
          assert.equal(row('pay-hook').customer_email, SAMPLE);
          phase = 'after';
          return { status: 'cancelled' };
        },
        getOrder: async (orderId) => {
          if (phase === 'after') {
            return taggedOrder(orderId, 'pay-hook', 'processing', { date_paid: '2026-10-14T08:00:00' });
          }
          return taggedOrder(orderId, 'pay-hook', 'pending');
        },
      });
      assert.equal(result.aborted, false);
      assert.equal(result.designIds.includes('pay-hook'), false);
      assert.equal(row('pay-hook').status, 'paid');
      assert.equal(row('pay-hook').anonymised_at, null);
      assert.equal(row('pay-hook').customer_email, SAMPLE);
      const bodies = fetches.map((item) => JSON.parse(item.body));
      const payment = bodies.find((body) => (body.to || []).includes(SAMPLE));
      assert.ok(payment);
      const alert = bodies.find((body) => (body.to || []).includes('info@creaturecycles.co.uk'));
      assert.ok(alert);
      assert.match(`${alert.text}\n${alert.html}`, /j\*\*\*@e\*\*\*\.com/);
      assert.equal(alert.text.includes(SAMPLE), false);
      assert.equal(alert.html.includes(SAMPLE), false);
      assert.equal(/https?:/i.test(`${alert.text}\n${alert.html}`), false);
      assert.equal(`${alert.text}\n${alert.html}`.includes('mailto:'), false);
    } finally {
      console.info = info;
      console.error = error;
      console.log = log;
      global.fetch = previous;
      delete process.env.SMTP_PASS;
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const blob = lines.join('\n');
    assert.match(blob, /Payment confirmed for design pay-hook/);
    assert.equal(blob.includes('retention purge is cancelling this order'), false);
    assert.equal(blob.includes(SAMPLE), false);
  },

  async 'anonymised-own-cancel-no-alert'() {
    insert({
      id: 'own-cancel',
      status: 'pending',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88900',
    });
    let lookups = 0;
    const purged = await retention.run({
      checkApi: async () => true,
      cancelOrder: async () => ({ status: 'cancelled' }),
      getOrder: async (orderId) => {
        lookups += 1;
        if (lookups < 3) return taggedOrder(orderId, 'own-cancel', 'on-hold');
        return taggedOrder(orderId, 'own-cancel', 'cancelled');
      },
    });
    assert.equal(purged.aborted, false);
    assert.deepEqual(purged.designIds, ['own-cancel']);
    assert.equal(row('own-cancel').purge_cancelled_order_id, '88900');
    assert.ok(row('own-cancel').anonymised_at);
    assert.equal(row('own-cancel').retention_alerted_at, null);

    const lines = [];
    const error = console.error;
    const info = console.info;
    const push = (...args) => lines.push(args.map(String).join(' '));
    console.error = push;
    console.info = push;
    let fetches = 0;
    const previous = global.fetch;
    global.fetch = async () => {
      fetches += 1;
      throw new Error('resend should not be called');
    };
    const webhooks = require('../routes/webhooks');
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await listen(app);
    try {
      const cancelled = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88900, status: 'cancelled' },
      });
      assert.equal(cancelled.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 50));
      assert.equal(row('own-cancel').retention_alerted_at, null);
      assert.equal(row('own-cancel').status, 'pending');
      assert.equal(row('own-cancel').customer_email, 'redacted+own-cancel@invalid');
      assert.equal(fetches, 0);

      const paid = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88900, status: 'processing', date_paid_gmt: '2026-10-14T08:00:00' },
      });
      assert.equal(paid.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 50));
      assert.ok(row('own-cancel').retention_alerted_at);
      assert.equal(row('own-cancel').status, 'pending');
      assert.equal(row('own-cancel').customer_email, 'redacted+own-cancel@invalid');
      assert.equal(fetches, 0);
    } finally {
      console.error = error;
      console.info = info;
      global.fetch = previous;
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const blob = lines.join('\n');
    assert.match(blob, /design own-cancel order 88900 status cancelled ignored; retention purge cancelled this order/);
    assert.equal((blob.match(/retention alert not sent \(no API key\)/g) || []).length, 1);
    assert.match(blob, /retention alert not sent \(no API key\) design own-cancel order 88900 status processing/);
    assert.equal(blob.includes(SAMPLE), false);
    assert.equal(blob.includes('http'), false);
  },

  async 'anonymised-foreign-cancel-alerts'() {
    insert({
      id: 'shop-cancel',
      status: 'expired',
      createdSql: "datetime('now', '-400 days')",
      expiresSql: RESUME_DONE,
      wcOrderId: '88910',
      anonymisedSql: "datetime('now', '-1 day')",
    });
    assert.equal(row('shop-cancel').purge_cancelled_order_id, null);
    const lines = [];
    const error = console.error;
    console.error = (...args) => lines.push(args.map(String).join(' '));
    let fetches = 0;
    const previous = global.fetch;
    global.fetch = async () => {
      fetches += 1;
      throw new Error('resend should not be called');
    };
    const webhooks = require('../routes/webhooks');
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await listen(app);
    try {
      const cancelled = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88910, status: 'cancelled' },
      });
      assert.equal(cancelled.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 50));
      assert.ok(row('shop-cancel').retention_alerted_at);
      assert.equal(row('shop-cancel').status, 'expired');
      assert.equal(row('shop-cancel').customer_email, SAMPLE);
      const again = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88910, status: 'cancelled' },
      });
      assert.equal(again.status, 200);
      await new Promise((resolve) => setTimeout(resolve, 50));
      assert.equal(fetches, 0);
    } finally {
      console.error = error;
      global.fetch = previous;
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
    const blob = lines.join('\n');
    assert.equal((blob.match(/retention alert not sent \(no API key\) design shop-cancel order 88910 status cancelled/g) || []).length, 1);
    assert.equal(blob.includes('retention purge cancelled this order'), false);
    assert.equal(blob.includes(SAMPLE), false);
    assert.equal(blob.includes('http'), false);
  },
};

try {
  const fn = cases[workerData.name];
  if (!fn) throw new Error(`unknown retention case ${workerData.name}`);
  await fn();
  parentPort.postMessage({ ok: true });
} catch (err) {
  parentPort.postMessage({
    ok: false,
    message: err && err.message ? err.message : String(err),
    stack: err && err.stack ? err.stack : '',
  });
}
