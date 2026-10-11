'use strict';

/**
 * Data-retention purge. When a check is uncertain, the row is kept.
 *
 * A design is paid, and stays on the seven-year clock, when any paid signal is
 * present. Paid signals:
 *   - paid_at
 *   - accepted_at, delivered_at, review_sent_at
 *   - review_pdf_base64, review_token
 *   - download_token, download_expires_at
 *   - status paid, in_review, accepted, delivered, or refunded
 *   - the linked Woo order has date_paid_gmt or date_paid, or a paid Woo
 *     status (processing, completed, refunded)
 *
 * The paid clock is the latest of paid_at, review_sent_at, accepted_at,
 * delivered_at, and Woo date_paid_gmt. created_at is not a payment time.
 * With no such timestamp the row is kept.
 *
 * An unpaid row is anonymised only when it is past RETENTION_UNPAID_DAYS
 * and either:
 *   - it has a Woo order whose status is exactly cancelled or trash, or
 *   - it never had a wc_order_id and it is not still resumable
 * pending and checkout_created stay while the resume link is valid, unless
 * that Woo order is cancelled or trash. pending, on-hold, and failed Woo
 * orders are still payable and are never anonymised. A 404 is not a missing
 * order unless Woo returns woocommerce_rest_shop_order_invalid_id and the
 * WC API check passed, and even then the row is kept: only cancelled and
 * trash are dead.
 *
 * A 401, 403, 5xx, network error, generic 404, or failed API check aborts
 * the whole run before any write. If more than RETENTION_PURGE_MAX rows
 * (default 20) or more than half the rows in the scan would be anonymised,
 * the run aborts and writes nothing.
 *
 * The anonymise UPDATE repeats the paid-signal check in its WHERE clause,
 * inside one transaction. A DB lock row stops a second run, including the
 * admin route, from overlapping. After a live run that anonymised at least
 * one row, VACUUM runs with secure_delete on.
 *
 * customer_name and customer_email are NOT NULL, so the purge writes
 * "redacted" and "redacted+<id>@invalid". design_name and the PDFs and
 * tokens are cleared. params and design_revisions stay. The Woo order still
 * holds billing details; this job does not touch it.
 *
 * RETENTION_PURGE_DRY_RUN writes nothing unless the value is exactly "0".
 * Unset, empty, and any other string are a dry run.
 * Day counts below their floors are raised. Non-numeric values log an error
 * and use the default. The timer interval is 0 (off) or clamped to 1–168h.
 */

const db = require('../db');
const cleanup = require('./unpaid-cleanup');

const DEFAULT_UNPAID_DAYS = 90;
const DEFAULT_PAID_DAYS = 2557;
const MIN_UNPAID_DAYS = 30;
const MIN_PAID_DAYS = 2557;
const DEFAULT_INTERVAL_HOURS = 24;
const MIN_INTERVAL_HOURS = 1;
const MAX_INTERVAL_HOURS = 168;
const DEFAULT_PURGE_MAX = 20;
const PURGE_FRACTION = 0.5;
const LOCK_STALE_MS = 30 * 60 * 1000;

const PAID_STATUSES = ['paid', 'in_review', 'accepted', 'delivered', 'refunded'];
const PAID_SIGNAL_COLUMNS = [
  'paid_at',
  'accepted_at',
  'delivered_at',
  'review_sent_at',
  'review_pdf_base64',
  'review_token',
  'download_token',
  'download_expires_at',
];
const WOO_PAID_STATUSES = new Set(['processing', 'completed', 'refunded']);
const DEAD_ORDER_STATUSES = new Set(['cancelled', 'trash']);
const RESUME_STATUSES = new Set(['pending', 'checkout_created']);
const REDACTED_NAME = 'redacted';
const INVALID_ORDER_CODE = 'woocommerce_rest_shop_order_invalid_id';

db.exec(`
  CREATE TABLE IF NOT EXISTS retention_lock (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    acquired_at TEXT
  );
`);

function parseEnvNumber(raw) {
  if (raw == null) return { ok: false, empty: true, text: '' };
  const text = String(raw).trim();
  if (text === '') return { ok: false, empty: true, text: '' };
  if (!/^\d+(\.\d+)?$/.test(text)) return { ok: false, empty: false, text };
  const n = Number(text);
  if (!Number.isFinite(n)) return { ok: false, empty: false, text };
  return { ok: true, empty: false, n, text };
}

function configuredDays(raw, fallback, floor, envName) {
  const parsed = parseEnvNumber(raw);
  if (parsed.empty) return fallback;
  if (!parsed.ok) {
    console.error(`[retention] ${envName}=${parsed.text} is not a number; using ${fallback}`);
    return fallback;
  }
  const days = Math.floor(parsed.n);
  if (days < floor) {
    console.warn(`[retention] ${envName}=${days} is below ${floor}; using ${floor}`);
    return floor;
  }
  return days;
}

function unpaidDays() {
  return configuredDays(
    process.env.RETENTION_UNPAID_DAYS,
    DEFAULT_UNPAID_DAYS,
    MIN_UNPAID_DAYS,
    'RETENTION_UNPAID_DAYS',
  );
}

function paidDays() {
  return configuredDays(
    process.env.RETENTION_PAID_DAYS,
    DEFAULT_PAID_DAYS,
    MIN_PAID_DAYS,
    'RETENTION_PAID_DAYS',
  );
}

function purgeMax() {
  return configuredDays(
    process.env.RETENTION_PURGE_MAX,
    DEFAULT_PURGE_MAX,
    0,
    'RETENTION_PURGE_MAX',
  );
}

function intervalHours() {
  const parsed = parseEnvNumber(process.env.RETENTION_PURGE_INTERVAL_HOURS);
  if (parsed.empty) return DEFAULT_INTERVAL_HOURS;
  if (!parsed.ok) {
    console.error(`[retention] RETENTION_PURGE_INTERVAL_HOURS=${parsed.text} is not a number; using ${DEFAULT_INTERVAL_HOURS}`);
    return DEFAULT_INTERVAL_HOURS;
  }
  if (parsed.n === 0) return 0;
  if (parsed.n > MAX_INTERVAL_HOURS) {
    console.error(`[retention] RETENTION_PURGE_INTERVAL_HOURS=${parsed.n} exceeds ${MAX_INTERVAL_HOURS}; using ${MAX_INTERVAL_HOURS}`);
    return MAX_INTERVAL_HOURS;
  }
  if (parsed.n < MIN_INTERVAL_HOURS) {
    console.error(`[retention] RETENTION_PURGE_INTERVAL_HOURS=${parsed.n} is below ${MIN_INTERVAL_HOURS}; using ${MIN_INTERVAL_HOURS}`);
    return MIN_INTERVAL_HOURS;
  }
  return parsed.n;
}

function dryRun() {
  return String(process.env.RETENTION_PURGE_DRY_RUN ?? '').trim() !== '0';
}

function redactedEmail(id) {
  const safe = String(id).replace(/[^A-Za-z0-9._+-]/g, '');
  return `redacted+${safe || 'design'}@invalid`;
}

function nonEmpty(value) {
  return value != null && String(value).trim() !== '';
}

function parseTime(value) {
  if (!value) return null;
  const text = String(value).trim();
  const hasZone = /(?:Z|[+-]\d\d:?\d\d)$/i.test(text);
  const normalised = hasZone ? text : `${text.replace(' ', 'T')}Z`;
  const ms = Date.parse(normalised);
  return Number.isNaN(ms) ? null : ms;
}

function olderThan(stamp, days, now = Date.now()) {
  const ms = parseTime(stamp);
  if (ms == null) return false;
  return ms + days * 24 * 60 * 60 * 1000 < now;
}

function paidAtFromWoo(value) {
  const ms = parseTime(value);
  if (ms == null) return null;
  return new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
}

function clockStamp(row, gmt) {
  const parsed = [row.paid_at, row.review_sent_at, row.accepted_at, row.delivered_at, gmt]
    .map((value) => ({ value, ms: parseTime(value) }))
    .filter((item) => item.ms != null);
  if (!parsed.length) return null;
  parsed.sort((a, b) => b.ms - a.ms);
  return paidAtFromWoo(parsed[0].value);
}

function hasLocalPaidSignal(row) {
  if (row.paid_at) return true;
  if (PAID_STATUSES.includes(row.status)) return true;
  if (row.accepted_at || row.delivered_at || row.review_sent_at) return true;
  if (row.has_review_pdf) return true;
  if (nonEmpty(row.review_token) || nonEmpty(row.download_token)) return true;
  if (row.download_expires_at) return true;
  return false;
}

function resumable(row) {
  return RESUME_STATUSES.has(row.status) && !cleanup.resumeExpired(row);
}

function loadRows() {
  return db.prepare(`
    SELECT id, status, created_at, resume_expires_at, paid_at, accepted_at, delivered_at,
           review_sent_at,
           CASE WHEN review_pdf_base64 IS NOT NULL THEN 1 ELSE 0 END AS has_review_pdf,
           review_token, download_token, download_expires_at, wc_order_id
    FROM designs
    WHERE anonymised_at IS NULL
    ORDER BY id
  `).all();
}

function needsWoo(row, unpaidWindow, paidWindow) {
  if (!row.wc_order_id || row.paid_at) return false;
  if (olderThan(row.created_at, unpaidWindow)) return true;
  if (!hasLocalPaidSignal(row)) return false;
  const stamp = clockStamp(row, null);
  if (!stamp) return true;
  return olderThan(stamp, paidWindow);
}

async function defaultGetOrder(wcOrderId) {
  const woocommerce = require('./woocommerce');
  return woocommerce.getOrder(wcOrderId);
}

async function defaultCheckApi() {
  const woocommerce = require('./woocommerce');
  return woocommerce.checkApi();
}

function abortError(reason) {
  const err = new Error(reason);
  err.code = 'RETENTION_ABORT';
  err.reason = reason;
  return err;
}

function isHardWooError(err) {
  if (!err || err.code === 'RETENTION_ABORT') return false;
  const status = Number(err.status);
  if (status === 404 && err.code === INVALID_ORDER_CODE) return false;
  return true;
}

function acquireLock() {
  const take = db.transaction(() => {
    db.prepare('INSERT OR IGNORE INTO retention_lock (id, acquired_at) VALUES (1, NULL)').run();
    const row = db.prepare('SELECT acquired_at FROM retention_lock WHERE id = 1').get();
    if (row && row.acquired_at) {
      const acquired = Date.parse(row.acquired_at);
      if (Number.isFinite(acquired) && Date.now() - acquired < LOCK_STALE_MS) return false;
    }
    db.prepare('UPDATE retention_lock SET acquired_at = ? WHERE id = 1').run(new Date().toISOString());
    return true;
  });
  return take.immediate();
}

function releaseLock() {
  db.prepare('UPDATE retention_lock SET acquired_at = NULL WHERE id = 1').run();
}

const anonymiseUnpaid = db.prepare(`
  UPDATE designs
  SET customer_name = ?,
      customer_email = ?,
      design_name = NULL,
      pdf_base64 = NULL,
      review_pdf_base64 = NULL,
      resume_token = NULL,
      download_token = NULL,
      review_token = NULL,
      resume_expires_at = NULL,
      download_expires_at = NULL,
      wc_checkout_url = NULL,
      anonymised_at = CURRENT_TIMESTAMP
  WHERE id = ?
    AND anonymised_at IS NULL
    AND paid_at IS NULL
    AND accepted_at IS NULL
    AND delivered_at IS NULL
    AND review_sent_at IS NULL
    AND review_pdf_base64 IS NULL
    AND (review_token IS NULL OR trim(review_token) = '')
    AND (download_token IS NULL OR trim(download_token) = '')
    AND download_expires_at IS NULL
    AND status NOT IN ('paid', 'in_review', 'accepted', 'delivered', 'refunded')
`);

const anonymisePaid = db.prepare(`
  UPDATE designs
  SET customer_name = ?,
      customer_email = ?,
      design_name = NULL,
      pdf_base64 = NULL,
      review_pdf_base64 = NULL,
      resume_token = NULL,
      download_token = NULL,
      review_token = NULL,
      resume_expires_at = NULL,
      download_expires_at = NULL,
      wc_checkout_url = NULL,
      anonymised_at = CURRENT_TIMESTAMP
  WHERE id = ?
    AND anonymised_at IS NULL
    AND datetime(
      COALESCE(paid_at, review_sent_at, accepted_at, delivered_at),
      '+' || ? || ' days'
    ) < datetime('now')
`);

const stampPaidAt = db.prepare(`
  UPDATE designs
  SET paid_at = ?
  WHERE id = ?
    AND paid_at IS NULL
    AND anonymised_at IS NULL
`);

const backfillLocalPaidAt = db.prepare(`
  UPDATE designs
  SET paid_at = max(
    COALESCE(review_sent_at, '0000'),
    COALESCE(accepted_at, '0000'),
    COALESCE(delivered_at, '0000')
  )
  WHERE anonymised_at IS NULL
    AND paid_at IS NULL
    AND (
      review_sent_at IS NOT NULL
      OR accepted_at IS NOT NULL
      OR delivered_at IS NOT NULL
    )
`);

function logBucket(preview, label, ids) {
  const mode = preview ? 'dry-run ' : '';
  const suffix = ids.length ? `: ${ids.join(', ')}` : '';
  console.log(`[retention] ${mode}${label} ${ids.length}${suffix}`);
}

function emptyResult(extra) {
  return {
    ok: true,
    dryRun: dryRun(),
    aborted: false,
    reason: null,
    unpaidDays: unpaidDays(),
    paidDays: paidDays(),
    count: 0,
    designIds: [],
    writtenIds: [],
    wouldAnonymiseUnpaid: [],
    wouldAnonymisePaid: [],
    skippedPaidSignals: [],
    skippedWooUnknown: [],
    skippedStillPayable: [],
    ...extra,
  };
}

async function runLocked(options) {
  const preview = dryRun();
  const unpaidWindow = unpaidDays();
  const paidWindow = paidDays();
  const limit = purgeMax();
  const getOrder = options.getOrder || defaultGetOrder;
  const rows = loadRows();
  const lookups = new Map();
  const wooRows = rows.filter((row) => needsWoo(row, unpaidWindow, paidWindow));

  if (wooRows.length) {
    const check = options.checkApi || (options.getOrder ? async () => true : defaultCheckApi);
    try {
      await check();
    } catch {
      console.error('[retention] aborted; Woo API check failed');
      throw abortError('woo-api');
    }
  }

  for (const row of wooRows) {
    try {
      const order = await getOrder(row.wc_order_id);
      if (order == null) {
        lookups.set(row.id, { gone: true, order: null });
        console.warn(`[retention] design ${row.id} kept; Woo order ${row.wc_order_id} is not a valid order id`);
      } else {
        lookups.set(row.id, { gone: false, order });
      }
    } catch (err) {
      if (isHardWooError(err)) {
        const status = err && err.status ? err.status : 'network';
        console.error(`[retention] aborted; Woo request failed for order ${row.wc_order_id} (${status})`);
        throw abortError('woo-error');
      }
      throw err;
    }
  }

  const unpaidIds = [];
  const paidIds = [];
  const skippedPaid = [];
  const skippedWoo = [];
  const skippedPayable = [];
  const gmtStamps = [];

  for (const row of rows) {
    const lookup = lookups.get(row.id);
    const localPaid = hasLocalPaidSignal(row);
    const unpaidDue = olderThan(row.created_at, unpaidWindow);
    const order = lookup && lookup.order ? lookup.order : null;
    const gmt = order && nonEmpty(order.date_paid_gmt) ? order.date_paid_gmt : null;
    const wooStatus = order ? String(order.status || '').toLowerCase() : '';
    const wooPaid = Boolean(order) && (
      nonEmpty(order.date_paid_gmt)
      || nonEmpty(order.date_paid)
      || WOO_PAID_STATUSES.has(wooStatus)
    );

    if (lookup && lookup.gone) {
      if (unpaidDue || localPaid) skippedWoo.push(row.id);
      continue;
    }

    const isPaid = localPaid || wooPaid;
    if (isPaid) {
      const stamp = clockStamp(row, gmt);
      if (gmt && !row.paid_at && !preview) gmtStamps.push({ id: row.id, stamp: paidAtFromWoo(gmt) });
      if (stamp && olderThan(stamp, paidWindow)) paidIds.push(row.id);
      else if (unpaidDue || !stamp) skippedPaid.push(row.id);
      continue;
    }

    if (!unpaidDue) continue;

    if (row.wc_order_id) {
      if (!lookup || !order) {
        skippedWoo.push(row.id);
        continue;
      }
      if (DEAD_ORDER_STATUSES.has(wooStatus)) {
        unpaidIds.push(row.id);
        continue;
      }
      skippedPayable.push(row.id);
      continue;
    }

    if (resumable(row)) {
      skippedPayable.push(row.id);
      continue;
    }
    unpaidIds.push(row.id);
  }

  const planned = [...unpaidIds, ...paidIds].sort();
  const fraction = rows.length ? planned.length / rows.length : 0;
  const breaker = planned.length > limit || fraction > PURGE_FRACTION;
  if (preview || breaker) {
    logBucket(preview, 'would-anonymise unpaid', unpaidIds);
    logBucket(preview, 'would-anonymise paid', paidIds);
  }
  logBucket(preview, 'skipped as paid-signals', skippedPaid);
  logBucket(preview, 'skipped as Woo unknown', skippedWoo);
  logBucket(preview, 'skipped as still payable', skippedPayable);

  if (breaker) {
    console.error(`[retention] aborted; would anonymise ${planned.length} of ${rows.length} (limit ${limit}, or more than half)`);
    return emptyResult({
      ok: false,
      aborted: true,
      reason: 'circuit-breaker',
      dryRun: preview,
      unpaidDays: unpaidWindow,
      paidDays: paidWindow,
      designIds: planned,
      wouldAnonymiseUnpaid: unpaidIds,
      wouldAnonymisePaid: paidIds,
      skippedPaidSignals: skippedPaid,
      skippedWooUnknown: skippedWoo,
      skippedStillPayable: skippedPayable,
    });
  }

  let writtenUnpaid = [];
  let writtenPaid = [];
  if (!preview) {
    const apply = db.transaction(() => {
      for (const item of gmtStamps) {
        if (item.stamp) stampPaidAt.run(item.stamp, item.id);
      }
      backfillLocalPaidAt.run();
      const didUnpaid = [];
      for (const id of unpaidIds) {
        const result = anonymiseUnpaid.run(REDACTED_NAME, redactedEmail(id), id);
        if (result.changes === 1) didUnpaid.push(id);
      }
      const didPaid = [];
      for (const id of paidIds) {
        const result = anonymisePaid.run(REDACTED_NAME, redactedEmail(id), id, String(paidWindow));
        if (result.changes === 1) didPaid.push(id);
      }
      return { didUnpaid, didPaid };
    });
    const written = apply();
    writtenUnpaid = written.didUnpaid;
    writtenPaid = written.didPaid;
    if (writtenUnpaid.length + writtenPaid.length > 0) {
      db.exec('VACUUM');
    }
  }

  const designIds = (preview ? planned : [...writtenUnpaid, ...writtenPaid].sort());
  if (!preview) {
    logBucket(false, 'anonymised unpaid', writtenUnpaid);
    logBucket(false, 'anonymised paid', writtenPaid);
  }

  return emptyResult({
    dryRun: preview,
    unpaidDays: unpaidWindow,
    paidDays: paidWindow,
    count: designIds.length,
    designIds,
    writtenIds: preview ? [] : designIds,
    wouldAnonymiseUnpaid: preview ? unpaidIds : writtenUnpaid,
    wouldAnonymisePaid: preview ? paidIds : writtenPaid,
    skippedPaidSignals: skippedPaid,
    skippedWooUnknown: skippedWoo,
    skippedStillPayable: skippedPayable,
  });
}

async function run(options = {}) {
  let held = false;
  try {
    held = acquireLock();
  } catch (err) {
    if (err && (err.code === 'SQLITE_BUSY' || err.code === 'SQLITE_LOCKED')) {
      console.error('[retention] skipped; another purge is already running');
      return emptyResult({ ok: false, aborted: true, reason: 'overlap' });
    }
    throw err;
  }
  if (!held) {
    console.error('[retention] skipped; another purge is already running');
    return emptyResult({ ok: false, aborted: true, reason: 'overlap' });
  }
  try {
    return await runLocked(options);
  } catch (err) {
    if (err && err.code === 'RETENTION_ABORT') {
      return emptyResult({ ok: false, aborted: true, reason: err.reason });
    }
    throw err;
  } finally {
    try { releaseLock(); } catch { /* the connection may already be closed */ }
  }
}

function scheduleRetentionPurge() {
  const hours = intervalHours();
  if (!Number.isFinite(hours) || hours <= 0) {
    console.log('  Retention purge : (not scheduled — RETENTION_PURGE_INTERVAL_HOURS is 0)');
    return;
  }
  const ms = hours * 60 * 60 * 1000;
  const tick = () => {
    Promise.resolve()
      .then(() => run())
      .catch((err) => {
        console.error('[retention] run failed:', err.message);
      });
  };
  setTimeout(tick, 30 * 1000);
  setInterval(tick, ms);
  const mode = dryRun() ? 'dry-run' : 'live';
  console.log(`  Retention purge : every ${hours}h, unpaid ${unpaidDays()}d, paid ${paidDays()}d, ${mode}`);
}

module.exports = {
  DEFAULT_UNPAID_DAYS,
  DEFAULT_PAID_DAYS,
  MIN_UNPAID_DAYS,
  MIN_PAID_DAYS,
  MIN_INTERVAL_HOURS,
  MAX_INTERVAL_HOURS,
  DEFAULT_PURGE_MAX,
  PAID_STATUSES,
  PAID_SIGNAL_COLUMNS,
  DEAD_ORDER_STATUSES,
  REDACTED_NAME,
  unpaidDays,
  paidDays,
  purgeMax,
  intervalHours,
  dryRun,
  redactedEmail,
  run,
  scheduleRetentionPurge,
};
