import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { Worker } from 'node:worker_threads';

const names = [
  'unpaid-window',
  'paid-clock',
  'uses-latest-paid-signal',
  'dry-run',
  'endpoint-404s',
  'log-mask-and-links',
  'failed-after-accept',
  'webhook-cancel-keeps-paid',
  'backfill-gmt',
  'woo-row-error-skips',
  'woo-auth-aborts',
  'woo-error-streak-aborts',
  'woo-error-fraction-aborts',
  'api-check-aborts',
  'generic-404-skips-row',
  'invalid-order-id-is-kept',
  'payable-orders-kept',
  'cancelled-order-is-purged',
  'resumable-without-order-kept',
  'expired-resume-without-order-purged',
  'expired-purged',
  'cancelled-backlog-drains',
  'unresolved-cutoff',
  'cancel-order-puts-cancelled',
  'cancel-then-wipe',
  'cancel-fails-skips',
  'cancel-error-aborts',
  'cancel-dry-run',
  'cancel-webhook-harmless',
  'invalid-id-aborts',
  'invalid-id-fraction-aborts',
  'paid-status-no-stamp',
  'unresolved-batch',
  'circuit-max',
  'race-recheck',
  'overlap-lock',
  'anonymised-webhook',
  'admin-route',
  'admin-overlap',
  'migration',
  'env-parsing',
  'dry-run-default-on',
  'woo-error-redacts-email',
  'anonymised-change-link',
  'review-html-escape',
];

function runCase(name, dryRun) {
  const dbPath = path.join(os.tmpdir(), `creature-retention-${name}-${process.pid}-${Date.now()}.db`);
  return new Promise((resolve, reject) => {
    const worker = new Worker(new URL('./retention-case.mjs', import.meta.url), {
      workerData: { name, dbPath, dryRun: dryRun === undefined ? '0' : dryRun },
    });
    let settled = false;
    const finish = (fn, value) => {
      if (settled) return;
      settled = true;
      for (const suffix of ['', '-wal', '-shm']) {
        try { fs.unlinkSync(dbPath + suffix); } catch { /* already gone */ }
      }
      fn(value);
    };
    worker.on('message', (message) => {
      if (message && message.ok) finish(resolve);
      else finish(reject, new Error(message && message.stack ? message.stack : 'retention case failed'));
    });
    worker.on('error', (err) => finish(reject, err));
    worker.on('exit', (code) => {
      if (!settled && code !== 0) finish(reject, new Error(`retention case ${name} exited ${code}`));
    });
  });
}

for (const name of names) {
  const dryRun = name === 'dry-run' || name === 'cancel-dry-run' ? '1' : name === 'dry-run-default-on' ? null : '0';
  test(name, async () => {
    await runCase(name, dryRun);
  });
}

test('retention cases are listed', () => {
  assert.equal(names.length, 45);
});
