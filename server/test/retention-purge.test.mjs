import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, describe, test } from 'node:test';
import express from 'express';

const dbPath = path.join(os.tmpdir(), `creature-retention-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;
delete process.env.RETENTION_UNPAID_DAYS;
delete process.env.RETENTION_PAID_DAYS;
delete process.env.RETENTION_PURGE_DRY_RUN;
delete process.env.RETENTION_PURGE_INTERVAL_HOURS;

const require = createRequire(import.meta.url);
const db = require('../db');
const retention = require('../services/retention-purge');
const designs = require('../routes/designs');
const download = require('../routes/download');
const review = require('../routes/review');
const email = require('../services/email');
const webhooks = require('../routes/webhooks');

const SAMPLE_EMAIL = 'jane.doe@example.com';

function insert({
  id,
  status,
  createdSql,
  paidSql = null,
  email = SAMPLE_EMAIL,
  name = 'Jane Doe',
  wcOrderId = null,
  acceptedSql = null,
  downloadToken = null,
}) {
  db.prepare(`
    INSERT INTO designs (
      id, created_at, paid_at, customer_name, customer_email, params,
      pdf_base64, status, resume_token, wc_checkout_url, resume_expires_at,
      wc_order_id, accepted_at, download_token
    ) VALUES (
      ?, ${createdSql}, ${paidSql || 'NULL'}, ?, ?, '{"reach":450}',
      'cGRm', ?, 'resume-' || ?, 'https://shop.example/checkout', datetime('now', '+1 day'),
      ?, ${acceptedSql || 'NULL'}, ?
    )
  `).run(id, name, email, status, id, wcOrderId, downloadToken);
  db.prepare(`
    INSERT INTO design_revisions (design_id, revision, params)
    VALUES (?, 0, '{"reach":450}')
  `).run(id);
}

function row(id) {
  return db.prepare('SELECT * FROM designs WHERE id = ?').get(id);
}

function request(server, urlPath, { method = 'GET', json } = {}) {
  return new Promise((resolve, reject) => {
    const payload = json == null ? null : Buffer.from(JSON.stringify(json));
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: urlPath,
      method,
      headers: payload ? {
        'content-type': 'application/json',
        'content-length': payload.length,
      } : undefined,
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        resolve({ status: res.statusCode, body: Buffer.concat(chunks).toString('utf8') });
      });
    });
    req.on('error', reject);
    if (payload) req.end(payload);
    else req.end();
  });
}

describe('retention purge', { concurrency: false }, () => {
  after(() => {
    for (const suffix of ['', '-wal', '-shm']) {
      try { fs.unlinkSync(dbPath + suffix); } catch { /* already gone */ }
    }
  });

  test('an unpaid design at 89 days stays and one at 91 days is anonymised', async () => {
    assert.equal(retention.unpaidDays(), 90);
    insert({ id: 'unpaid-89', status: 'pending', createdSql: "datetime('now', '-89 days')" });
    insert({ id: 'unpaid-91', status: 'cancelled', createdSql: "datetime('now', '-91 days')" });
    insert({ id: 'failed-91', status: 'failed', createdSql: "datetime('now', '-91 days')" });

    const result = await retention.run();
    assert.equal(result.dryRun, false);
    assert.deepEqual(result.designIds, ['failed-91', 'unpaid-91']);
    assert.deepEqual(result.wouldAnonymiseUnpaid, ['failed-91', 'unpaid-91']);

    const young = row('unpaid-89');
    assert.equal(young.customer_email, SAMPLE_EMAIL);
    assert.equal(young.customer_name, 'Jane Doe');
    assert.equal(young.resume_token, 'resume-unpaid-89');
    assert.equal(young.anonymised_at, null);

    const old = row('unpaid-91');
    assert.equal(old.customer_name, 'redacted');
    assert.equal(old.customer_email, 'redacted+unpaid-91@invalid');
    assert.equal(old.pdf_base64, null);
    assert.equal(old.review_pdf_base64, null);
    assert.equal(old.resume_token, null);
    assert.equal(old.download_token, null);
    assert.equal(old.review_token, null);
    assert.equal(old.resume_expires_at, null);
    assert.equal(old.download_expires_at, null);
    assert.equal(old.wc_checkout_url, null);
    assert.ok(old.anonymised_at);
    assert.equal(old.params, '{"reach":450}');
    assert.equal(old.status, 'cancelled');
    const revision = db.prepare(`SELECT params FROM design_revisions WHERE design_id = 'unpaid-91'`).get();
    assert.equal(revision.params, '{"reach":450}');
  });

  test('a paid design is kept until 6 years and a missing paid date uses created_at', async () => {
    assert.equal(retention.paidDays(), 2192);
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
    assert.deepEqual(result.designIds, ['paid-no-stamp', 'paid-old']);
    assert.deepEqual(result.wouldAnonymisePaid, ['paid-no-stamp', 'paid-old']);
    assert.ok(result.skippedPaidSignals.includes('paid-young'));
    assert.ok(result.skippedPaidSignals.includes('refunded-recent'));
    assert.equal(row('paid-young').customer_email, SAMPLE_EMAIL);
    assert.equal(row('paid-young').anonymised_at, null);
    assert.equal(row('refunded-recent').customer_email, SAMPLE_EMAIL);
    assert.equal(row('paid-old').customer_email, 'redacted+paid-old@invalid');
    assert.equal(row('paid-no-stamp').customer_name, 'redacted');
  });

  test('a second run does not write the anonymised row again', async () => {
    const before = row('unpaid-91');
    const result = await retention.run();
    const after = row('unpaid-91');
    assert.deepEqual(result.designIds, []);
    assert.equal(after.anonymised_at, before.anonymised_at);
    assert.equal(after.customer_email, before.customer_email);
    assert.equal(after.customer_name, 'redacted');
  });

  test('dry run reports a breakdown and changes nothing', async () => {
    insert({ id: 'dry-91', status: 'expired', createdSql: "datetime('now', '-91 days')" });
    process.env.RETENTION_PURGE_DRY_RUN = '1';
    const lines = [];
    const log = console.log;
    console.log = (...args) => lines.push(args.map(String).join(' '));
    try {
      const result = await retention.run();
      assert.equal(result.dryRun, true);
      assert.deepEqual(result.designIds, ['dry-91']);
      assert.deepEqual(result.wouldAnonymiseUnpaid, ['dry-91']);
      const blob = lines.join('\n');
      assert.match(blob, /\[retention\] dry-run would-anonymise unpaid 1: dry-91/);
      assert.match(blob, /\[retention\] dry-run skipped as paid-signals \d+:/);
      assert.match(blob, /\[retention\] dry-run skipped as Woo unknown \d+/);
      assert.match(blob, /\[retention\] dry-run skipped as still payable \d+/);
      assert.equal(blob.includes(SAMPLE_EMAIL), false);
      assert.equal(blob.includes('Jane Doe'), false);
      const kept = row('dry-91');
      assert.equal(kept.customer_email, SAMPLE_EMAIL);
      assert.equal(kept.pdf_base64, 'cGRm');
      assert.equal(kept.resume_token, 'resume-dry-91');
      assert.equal(kept.wc_checkout_url, 'https://shop.example/checkout');
      assert.equal(kept.anonymised_at, null);
    } finally {
      console.log = log;
      delete process.env.RETENTION_PURGE_DRY_RUN;
    }
  });

  test('resume, checkout, and download reject an anonymised design without a 500', async () => {
    const app = express();
    app.use('/api/designs', designs);
    app.use('/api/download', download);
    app.use('/api/review', review);
    const server = await new Promise((resolve) => {
      const listening = app.listen(0, '127.0.0.1', () => resolve(listening));
    });
    try {
      const resume = await request(server, '/api/designs/unpaid-91?resume=resume-unpaid-91');
      const checkout = await request(server, '/api/designs/unpaid-91/checkout?resume=resume-unpaid-91');
      const file = await request(server, '/api/download/download-unpaid-91');
      const preview = await request(server, '/api/review/preview/review-unpaid-91');
      assert.equal(resume.status, 404);
      assert.equal(checkout.status, 404);
      assert.equal(file.status, 404);
      assert.equal(preview.status, 404);
      assert.equal(resume.body.includes(SAMPLE_EMAIL), false);
      assert.equal(checkout.body.includes('Jane Doe'), false);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
  });

  test('log output does not contain the sample email', async () => {
    const lines = [];
    const warn = console.warn;
    const error = console.error;
    console.warn = (...args) => lines.push(args.map(String).join(' '));
    console.error = (...args) => lines.push(args.map(String).join(' '));
    const previousFetch = global.fetch;
    delete process.env.SMTP_PASS;
    delete process.env.RESEND_API_KEY;
    try {
      await email.sendOrderConfirmation({
        to: SAMPLE_EMAIL,
        customerName: 'Jane Doe',
        designId: 'design-log',
        editUrl: 'https://example.invalid/edit',
        checkoutUrl: 'https://example.invalid/pay',
      });

      process.env.SMTP_PASS = 're_test_key';
      global.fetch = async () => ({
        ok: false,
        status: 422,
        text: async () => `Invalid to field ${SAMPLE_EMAIL}`,
        arrayBuffer: async () => new ArrayBuffer(0),
      });
      await assert.rejects(
        () => email.sendPaymentConfirmation({
          to: SAMPLE_EMAIL,
          customerName: 'Jane Doe',
          designId: 'design-log',
        }),
        (err) => {
          lines.push(String(err && err.message));
          assert.equal(String(err.message).includes(SAMPLE_EMAIL), false);
          assert.match(String(err.message), /j\*\*\*@e\*\*\*\.com/);
          return true;
        },
      );
    } finally {
      console.warn = warn;
      console.error = error;
      global.fetch = previousFetch;
      delete process.env.SMTP_PASS;
    }

    const blob = lines.join('\n');
    assert.equal(blob.includes(SAMPLE_EMAIL), false);
    assert.match(blob, /j\*\*\*@e\*\*\*\.com/);
  });

  test('a design that failed after accept is kept', async () => {
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
    assert.equal(kept.paid_at, null);
    assert.ok(kept.accepted_at);
    assert.equal(kept.download_token, 'dl-failed-after-accept');
    assert.equal(kept.customer_email, SAMPLE_EMAIL);
    assert.equal(kept.anonymised_at, null);
  });

  test('a paid order cancelled via the webhook keeps paid_at and is not purged', async () => {
    const id = 'paid-then-cancelled';
    insert({
      id,
      status: 'paid',
      createdSql: "datetime('now', '-200 days')",
      paidSql: "datetime('now', '-200 days')",
      wcOrderId: '88001',
    });
    const before = row(id).paid_at;
    const app = express();
    app.use('/api/webhooks', webhooks);
    const server = await new Promise((resolve) => {
      const listening = app.listen(0, '127.0.0.1', () => resolve(listening));
    });
    try {
      const res = await request(server, '/api/webhooks/woocommerce/order-updated', {
        method: 'POST',
        json: { id: 88001, status: 'cancelled' },
      });
      assert.equal(res.status, 200);
      let updated = row(id);
      for (let i = 0; i < 40 && updated.status !== 'cancelled'; i += 1) {
        await new Promise((resolve) => setTimeout(resolve, 15));
        updated = row(id);
      }
      assert.equal(updated.status, 'cancelled');
      assert.equal(updated.paid_at, before);
      const result = await retention.run();
      assert.equal(result.designIds.includes(id), false);
      assert.ok(result.skippedPaidSignals.includes(id));
      const kept = row(id);
      assert.equal(kept.paid_at, before);
      assert.equal(kept.customer_email, SAMPLE_EMAIL);
      assert.equal(kept.anonymised_at, null);
    } finally {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
  });

  test('backfills paid_at from Woo date_paid and does not anonymise the design', async () => {
    insert({
      id: 'backfill-paid',
      status: 'pending',
      createdSql: "datetime('now', '-91 days')",
      wcOrderId: '88002',
    });
    const datePaid = '2024-06-01T12:00:00';
    const getOrder = async (orderId) => {
      assert.equal(String(orderId), '88002');
      return { id: 88002, status: 'completed', date_paid: datePaid };
    };
    process.env.RETENTION_PURGE_DRY_RUN = '1';
    try {
      const preview = await retention.run({ getOrder });
      assert.equal(preview.designIds.includes('backfill-paid'), false);
      assert.ok(preview.skippedPaidSignals.includes('backfill-paid'));
      assert.equal(row('backfill-paid').paid_at, null);
    } finally {
      delete process.env.RETENTION_PURGE_DRY_RUN;
    }
    const result = await retention.run({ getOrder });
    assert.equal(result.designIds.includes('backfill-paid'), false);
    assert.equal(row('backfill-paid').paid_at, '2024-06-01 12:00:00');
    assert.equal(row('backfill-paid').customer_email, SAMPLE_EMAIL);
    assert.equal(row('backfill-paid').status, 'pending');
    assert.equal(row('backfill-paid').anonymised_at, null);
  });

  test('skips a design when Woo cannot be checked', async () => {
    insert({
      id: 'woo-down',
      status: 'pending',
      createdSql: "datetime('now', '-120 days')",
      wcOrderId: '88003',
    });
    const result = await retention.run({
      getOrder: async () => {
        throw new Error('connect ECONNREFUSED');
      },
    });
    assert.equal(result.designIds.includes('woo-down'), false);
    assert.ok(result.skippedWooUnknown.includes('woo-down'));
    const kept = row('woo-down');
    assert.equal(kept.customer_email, SAMPLE_EMAIL);
    assert.equal(kept.paid_at, null);
    assert.equal(kept.anonymised_at, null);
  });

  test('a pending design with a live Woo order is skipped', async () => {
    insert({
      id: 'still-payable',
      status: 'checkout_created',
      createdSql: "datetime('now', '-91 days')",
      wcOrderId: '88004',
    });
    const result = await retention.run({
      getOrder: async () => ({ id: 88004, status: 'pending', date_paid: null }),
    });
    assert.equal(result.designIds.includes('still-payable'), false);
    assert.ok(result.skippedStillPayable.includes('still-payable'));
    const kept = row('still-payable');
    assert.equal(kept.resume_token, 'resume-still-payable');
    assert.equal(kept.customer_email, SAMPLE_EMAIL);
    assert.equal(kept.anonymised_at, null);
  });

  test('an expired design is purged after 90 days', async () => {
    insert({
      id: 'expired-91',
      status: 'expired',
      createdSql: "datetime('now', '-91 days')",
    });
    const result = await retention.run();
    assert.ok(result.wouldAnonymiseUnpaid.includes('expired-91'));
    assert.ok(result.designIds.includes('expired-91'));
    assert.equal(row('expired-91').customer_name, 'redacted');
    assert.equal(row('expired-91').customer_email, 'redacted+expired-91@invalid');
    assert.equal(row('expired-91').status, 'expired');
    assert.ok(row('expired-91').anonymised_at);
  });

  test('retention day floors clamp and do not wipe a young design', async () => {
    insert({
      id: 'floor-young',
      status: 'expired',
      createdSql: "datetime('now', '-10 days')",
    });
    const lines = [];
    const warn = console.warn;
    console.warn = (...args) => lines.push(args.map(String).join(' '));
    try {
      delete process.env.RETENTION_UNPAID_DAYS;
      delete process.env.RETENTION_PAID_DAYS;
      assert.equal(retention.unpaidDays(), 90);
      assert.equal(retention.paidDays(), 2192);

      process.env.RETENTION_UNPAID_DAYS = '0';
      assert.equal(retention.unpaidDays(), 30);
      process.env.RETENTION_UNPAID_DAYS = '10';
      assert.equal(retention.unpaidDays(), 30);
      process.env.RETENTION_PAID_DAYS = '0';
      assert.equal(retention.paidDays(), 2192);
      process.env.RETENTION_PAID_DAYS = '100';
      assert.equal(retention.paidDays(), 2192);

      process.env.RETENTION_UNPAID_DAYS = '0';
      const result = await retention.run();
      assert.equal(result.unpaidDays, 30);
      assert.equal(result.paidDays, 2192);
      assert.equal(result.designIds.includes('floor-young'), false);
      assert.equal(row('floor-young').anonymised_at, null);
      assert.equal(row('floor-young').customer_email, SAMPLE_EMAIL);
    } finally {
      console.warn = warn;
      delete process.env.RETENTION_UNPAID_DAYS;
      delete process.env.RETENTION_PAID_DAYS;
    }
    const blob = lines.join('\n');
    assert.match(blob, /RETENTION_UNPAID_DAYS=0 is below 30; using 30/);
    assert.match(blob, /RETENTION_UNPAID_DAYS=10 is below 30; using 30/);
    assert.match(blob, /RETENTION_PAID_DAYS=0 is below 2192; using 2192/);
    assert.match(blob, /RETENTION_PAID_DAYS=100 is below 2192; using 2192/);
  });
});
