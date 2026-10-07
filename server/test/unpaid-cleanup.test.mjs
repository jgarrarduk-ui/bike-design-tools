import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, describe, test } from 'node:test';

const dbPath = path.join(os.tmpdir(), `creature-cleanup-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.RESUME_TOKEN_TTL_DAYS;

const require = createRequire(import.meta.url);
const db = require('../db');
const cleanup = require('../services/unpaid-cleanup');

function insert({ id, status, createdSql, expiresSql, wcOrderId = null }) {
  db.prepare(`
    INSERT INTO designs (
      id, created_at, customer_name, customer_email, params, status,
      wc_order_id, resume_token, resume_expires_at
    ) VALUES (?, ${createdSql}, 'Smoke', ?, '{}', ?, ?, 'tok', ${expiresSql})
  `).run(id, `${id}@example.com`, status, wcOrderId);
}

describe('unpaid design cleanup', { concurrency: false }, () => {
  after(() => {
    for (const suffix of ['', '-wal', '-shm']) {
      try { fs.unlinkSync(dbPath + suffix); } catch { /* already gone */ }
    }
  });

  test('expires scrapped unpaid designs after 90 days and cancels only pending Woo orders', async () => {
    assert.equal(cleanup.resumeTtlDays(), 90);

    insert({
      id: 'old-pending',
      status: 'pending',
      createdSql: "datetime('now', '-100 days')",
      expiresSql: "datetime('now', '-1 day')",
      wcOrderId: '81',
    });
    insert({
      id: 'old-by-created',
      status: 'checkout_created',
      createdSql: "datetime('now', '-100 days')",
      expiresSql: 'NULL',
      wcOrderId: '82',
    });
    insert({
      id: 'fresh',
      status: 'checkout_created',
      createdSql: "datetime('now')",
      expiresSql: "datetime('now', '+90 days')",
      wcOrderId: '83',
    });
    insert({
      id: 'paid-old',
      status: 'paid',
      createdSql: "datetime('now', '-200 days')",
      expiresSql: "datetime('now', '-100 days')",
      wcOrderId: '84',
    });

    const cancelled = [];
    const result = await cleanup.run({
      cancelOrder: async (wcOrderId) => {
        cancelled.push(String(wcOrderId));
        if (wcOrderId === '82') return { skipped: 'processing' };
        return { cancelled: true };
      },
    });

    assert.deepEqual(result.expired.map((row) => row.designId).sort(), ['old-by-created', 'old-pending']);
    assert.deepEqual(cancelled.sort(), ['81', '82']);

    const old = db.prepare(`SELECT status, deleted_at FROM designs WHERE id = 'old-pending'`).get();
    assert.equal(old.status, 'expired');
    assert.ok(old.deleted_at);
    assert.equal(db.prepare(`SELECT status FROM designs WHERE id = 'fresh'`).get().status, 'checkout_created');
    assert.equal(db.prepare(`SELECT status FROM designs WHERE id = 'paid-old'`).get().status, 'paid');
    assert.equal(cleanup.resumeExpired(db.prepare(`SELECT * FROM designs WHERE id = 'old-pending'`).get()), true);
    assert.equal(cleanup.resumeExpired(db.prepare(`SELECT * FROM designs WHERE id = 'fresh'`).get()), false);

    const again = await cleanup.run({ cancelOrder: async () => { throw new Error('should not run'); } });
    assert.deepEqual(again.expired, []);
  });
});
