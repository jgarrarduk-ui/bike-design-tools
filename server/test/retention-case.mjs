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
delete process.env.ADMIN_PASSWORD;
delete process.env.WC_WEBHOOK_SECRET;
if (workerData.dryRun === null) delete process.env.RETENTION_PURGE_DRY_RUN;
else process.env.RETENTION_PURGE_DRY_RUN = workerData.dryRun == null ? '0' : workerData.dryRun;

const require = createRequire(import.meta.url);
const SAMPLE = 'jane.doe@example.com';

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
    insert({ id: 'unpaid-91', status: 'cancelled', createdSql: "datetime('now', '-91 days')" });
    insert({ id: 'failed-91', status: 'failed', createdSql: "datetime('now', '-91 days')" });
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
      paidSql: "datetime('now', '-2191 days')",
    });
    insert({
      id: 'paid-old',
      status: 'delivered',
      createdSql: "datetime('now', '-10 days')",
      paidSql: "datetime('now', '-2193 days')",
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
      createdSql: "datetime('now', '-2193 days')",
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
      acceptedSql: "datetime('now', '-2193 days')",
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
    insert({ id: 'dry-91', status: 'expired', createdSql: "datetime('now', '-91 days')" });
    insert({
      id: 'dry-paid',
      status: 'delivered',
      createdSql: "datetime('now', '-10 days')",
      paidSql: "datetime('now', '-2193 days')",
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
      paidSql: "datetime('now', '-2193 days')",
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
    const bytes = fs.readFileSync(dbPath);
    assert.equal(bytes.includes(Buffer.from('purge-me@example.com')), false);
    assert.equal(bytes.includes(Buffer.from('Secret Frame Name')), false);
    assert.equal(db.pragma('secure_delete', { simple: true }), 1);
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

  async 'woo-network-aborts'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({
      id: 'woo-down',
      status: 'pending',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88003',
      expiresSql: "datetime('now', '-1 day')",
    });
    let calls = 0;
    const result = await retention.run(woo(async () => {
      calls += 1;
      const err = new Error('connect ECONNREFUSED');
      throw err;
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-error');
    assert.equal(calls, 1);
    assert.equal(row('woo-down').anonymised_at, null);
    assert.equal(row('still-here').anonymised_at, null);
    assert.equal(row('still-here').customer_email, SAMPLE);
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

  async 'generic-404-aborts'() {
    insert({ id: 'still-here', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({
      id: 'bad-route',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88010',
    });
    const result = await retention.run(woo(async () => {
      const err = new Error('not found');
      err.status = 404;
      err.code = 'rest_no_route';
      throw err;
    }));
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'woo-error');
    assert.equal(row('still-here').anonymised_at, null);
    assert.equal(row('bad-route').anonymised_at, null);
  },

  async 'invalid-order-id-is-kept'() {
    insert({ id: 'no-order', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({
      id: 'gone-order',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88011',
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
    keeper('keep-a');
    keeper('keep-b');
    const result = await retention.run(woo(async (orderId) => ({
      id: orderId,
      status: String(orderId) === '88013' ? 'trash' : 'cancelled',
    })));
    assert.equal(result.aborted, false);
    assert.deepEqual(result.designIds, ['woo-cancelled', 'woo-trash']);
    assert.equal(row('woo-cancelled').customer_email, 'redacted+woo-cancelled@invalid');
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
    insert({ id: 'expired-91', status: 'expired', createdSql: "datetime('now', '-91 days')" });
    keeper();
    const result = await retention.run();
    assert.deepEqual(result.designIds, ['expired-91']);
    assert.equal(row('expired-91').customer_email, 'redacted+expired-91@invalid');
    assert.equal(row('expired-91').design_name, null);
    assert.equal(row('expired-91').status, 'expired');
  },

  async 'circuit-fraction'() {
    insert({ id: 'a', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({ id: 'b', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({ id: 'c', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    const result = await retention.run();
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'circuit-breaker');
    assert.equal(row('a').customer_email, SAMPLE);
    assert.equal(row('b').anonymised_at, null);
    assert.equal(row('c').anonymised_at, null);
  },

  async 'circuit-max'() {
    process.env.RETENTION_PURGE_MAX = '1';
    insert({ id: 'a', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    insert({ id: 'b', status: 'expired', createdSql: "datetime('now', '-120 days')" });
    keeper('keep-a');
    keeper('keep-b');
    const result = await retention.run();
    assert.equal(result.aborted, true);
    assert.equal(result.reason, 'circuit-breaker');
    assert.equal(row('a').anonymised_at, null);
    assert.equal(row('b').anonymised_at, null);
  },

  async 'race-recheck'() {
    insert({
      id: 'race-1',
      status: 'expired',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88020',
    });
    keeper();
    const result = await retention.run(woo(async () => {
      db.prepare(`
        UPDATE designs
        SET status = 'paid', paid_at = CURRENT_TIMESTAMP
        WHERE id = 'race-1'
      `).run();
      return { id: 88020, status: 'cancelled' };
    }));
    assert.equal(result.aborted, false);
    assert.equal(result.designIds.includes('race-1'), false);
    const kept = row('race-1');
    assert.equal(kept.status, 'paid');
    assert.ok(kept.paid_at);
    assert.equal(kept.customer_email, SAMPLE);
    assert.equal(kept.anonymised_at, null);
    assert.equal(kept.design_name, "Jane's gravel bike");
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
    assert.match(blob, /design already-gone order 88030 status cancelled ignored/);
    assert.match(blob, /retention alert not sent \(no API key\) design already-gone order 88030/);
    assert.equal(blob.includes(SAMPLE), false);
  },

  async 'admin-route'() {
    insert({ id: 'expired-91', status: 'expired', createdSql: "datetime('now', '-91 days')" });
    keeper();
    const admin = require('../routes/admin');
    const app = express();
    app.use('/api/admin', admin);
    const server = await listen(app);
    try {
      const open = await request(server, '/api/admin/retention-purge', { method: 'POST' });
      assert.equal(open.status, 200);
      assert.equal(open.json.ok, true);
      assert.equal(open.json.dryRun, false);
      assert.deepEqual(open.json.designIds, ['expired-91']);

      process.env.ADMIN_PASSWORD = 'secret';
      const denied = await request(server, '/api/admin/retention-purge', { method: 'POST' });
      assert.equal(denied.status, 401);
      const allowed = await request(server, '/api/admin/retention-purge', {
        method: 'POST',
        headers: { authorization: 'Bearer secret' },
      });
      assert.equal(allowed.status, 200);
      assert.equal(allowed.json.ok, true);

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
      const res = await request(server, '/api/admin/retention-purge', { method: 'POST' });
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
    for (const name of ['paid_at', 'anonymised_at', 'accepted_at', 'design_name', 'revision']) {
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
      delete process.env.RETENTION_PAID_DAYS;
      delete process.env.RETENTION_PURGE_INTERVAL_HOURS;
      delete process.env.RETENTION_PURGE_DRY_RUN;
      assert.equal(retention.unpaidDays(), 90);
      assert.equal(retention.paidDays(), 2192);
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
      process.env.RETENTION_PAID_DAYS = '100';
      assert.equal(retention.paidDays(), 2192);
      process.env.RETENTION_PAID_DAYS = 'nope';
      assert.equal(retention.paidDays(), 2192);

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
    assert.match(blob, /RETENTION_PAID_DAYS=100 is below 2192; using 2192/);
    assert.match(blob, /RETENTION_PURGE_INTERVAL_HOURS=abc is not a number; using 24/);
    assert.match(blob, /RETENTION_PURGE_INTERVAL_HOURS=1000 exceeds 168; using 168/);
    assert.match(blob, /RETENTION_PURGE_INTERVAL_HOURS=0.5 is below 1; using 1/);
  },

  async 'dry-run-default-on'() {
    insert({ id: 'expired-91', status: 'expired', createdSql: "datetime('now', '-91 days')" });
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
