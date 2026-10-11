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
 * An unpaid row is anonymised only when it is past RETENTION_UNPAID_DAYS,
 * the resume link has expired, and either:
 *   - it has a Woo order whose status is exactly cancelled or trash, or
 *   - it never had a wc_order_id
 * A resume link that is still valid keeps the row even when that Woo order
 * is cancelled or trash. pending, on-hold, and failed Woo orders are still
 * payable and are never anonymised. A confirmed invalid order id is kept.
 *
 * Each run writes at most RETENTION_PURGE_MAX eligible rows (default 20)
 * and leaves the rest for the next run. That cap does not abort the run.
 * Cancelled orders are the normal result of the unpaid cleanup, so a large
 * cancelled backlog is drained in batches rather than aborted. The run
 * aborts with no writes when the Woo API check fails, Woo returns 401 or
 * 403, more than three lookups fail in a row, or more than 20% of the
 * lookups fail (at least two). Any other single-order failure skips that
 * row only.
 *
 * The anonymise UPDATE repeats the selection: same status, same wc_order_id,
 * resume link still expired, and the paid clock uses the latest timestamp.
 * A lock row with an owner id stops a second run, including the admin route.
 * The owner refreshes the lock while it works. A lock older than 30 minutes
 * can be taken over. A run releases only its own lock.
 *
 * After a live run that anonymised at least one row, and only when the
 * database is in WAL mode, the purge runs wal_checkpoint(TRUNCATE).
 * secure_delete stays on. VACUUM is not run: it rewrites every stored PDF.
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

const crypto = require('crypto');
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
const ERROR_FRACTION = 0.2;
const ERROR_STREAK_MAX = 3;
const LOCK_STALE_MS = 30 * 60 * 1000;
const HEARTBEAT_MS = 60 * 1000;

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
const REDACTED_NAME = 'redacted';
const INVALID_ORDER_CODE = 'woocommerce_rest_shop_order_invalid_id';

db.exec(`
  CREATE TABLE IF NOT EXISTS retention_lock (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    acquired_at TEXT,
    owner TEXT
  );
`);
const lockCols = new Set(db.pragma('table_info(retention_lock)').map((col) => col.name));
if (!lockCols.has('owner')) db.exec('ALTER TABLE retention_lock ADD COLUMN owner TEXT');
if (!lockCols.has('acquired_at')) db.exec('ALTER TABLE retention_lock ADD COLUMN acquired_at TEXT');

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

function resumeStillValid(row) {
  return !cleanup.resumeExpired(row);
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

function isAuthError(err) {
  const status = Number(err && err.status);
  return status === 401 || status === 403;
}

function isInvalidOrder(err) {
  return Boolean(err) && Number(err.status) === 404 && err.code === INVALID_ORDER_CODE;
}

function wooFailureStatus(err) {
  if (err && err.status) return err.status;
  return 'network';
}

function acquireLock(owner) {
  const take = db.transaction(() => {
    db.prepare('INSERT OR IGNORE INTO retention_lock (id, acquired_at, owner) VALUES (1, NULL, NULL)').run();
    const row = db.prepare('SELECT acquired_at, owner FROM retention_lock WHERE id = 1').get();
    if (row && row.owner && row.owner !== owner && row.acquired_at) {
      const acquired = Date.parse(row.acquired_at);
      if (Number.isFinite(acquired) && Date.now() - acquired < LOCK_STALE_MS) return false;
    }
    db.prepare('UPDATE retention_lock SET acquired_at = ?, owner = ? WHERE id = 1').run(new Date().toISOString(), owner);
    return true;
  });
  return take.immediate();
}

function heartbeat(owner) {
  const result = db.prepare(`
    UPDATE retention_lock SET acquired_at = ? WHERE id = 1 AND owner = ?
  `).run(new Date().toISOString(), owner);
  return result.changes === 1;
}

function releaseLock(owner) {
  db.prepare(`
    UPDATE retention_lock SET acquired_at = NULL, owner = NULL WHERE id = 1 AND owner = ?
  `).run(owner);
}

function checkpointWal() {
  let mode = '';
  try {
    mode = String(db.pragma('journal_mode', { simple: true }) || '');
  } catch (err) {
    console.error('[retention] wal checkpoint failed:', (err && err.code) || 'error');
    return;
  }
  if (mode.toLowerCase() !== 'wal') return;
  try {
    db.pragma('wal_checkpoint(TRUNCATE)');
  } catch (err) {
    console.error('[retention] wal checkpoint failed:', (err && err.code) || 'error');
  }
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
    AND status = ?
    AND COALESCE(wc_order_id, '') = COALESCE(?, '')
    AND (
      (resume_expires_at IS NOT NULL AND datetime(resume_expires_at) <= datetime('now'))
      OR (
        resume_expires_at IS NULL
        AND created_at IS NOT NULL
        AND datetime(created_at, '+' || ? || ' days') <= datetime('now')
      )
    )
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
      max(
        COALESCE(paid_at, '0'),
        COALESCE(review_sent_at, '0'),
        COALESCE(accepted_at, '0'),
        COALESCE(delivered_at, '0')
      ),
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
    deferredIds: [],
    wouldAnonymiseUnpaid: [],
    wouldAnonymisePaid: [],
    skippedPaidSignals: [],
    skippedWooUnknown: [],
    skippedWooError: [],
    skippedStillPayable: [],
    keptIndefinitely: { invalidOrderId: 0, paidWithoutTimestamp: 0, payableWooOrder: 0 },
    ...extra,
  };
}

function takeBatch(unpaidTargets, paidIds, limit) {
  const unpaid = unpaidTargets.slice(0, limit);
  const paid = paidIds.slice(0, Math.max(0, limit - unpaid.length));
  const deferred = [
    ...unpaidTargets.slice(unpaid.length).map((item) => item.id),
    ...paidIds.slice(paid.length),
  ];
  return { unpaid, paid, deferred };
}

async function runLocked(options, owner) {
  const preview = dryRun();
  const unpaidWindow = unpaidDays();
  const paidWindow = paidDays();
  const limit = purgeMax();
  const resumeTtl = String(cleanup.resumeTtlDays());
  const getOrder = options.getOrder || defaultGetOrder;
  const rows = loadRows();
  const lookups = new Map();
  const wooErrors = new Set();
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

  let errorCount = 0;
  let streak = 0;
  for (const row of wooRows) {
    try {
      const order = await getOrder(row.wc_order_id);
      streak = 0;
      if (order == null) {
        lookups.set(row.id, { gone: true, order: null });
        console.warn(`[retention] design ${row.id} kept; Woo order ${row.wc_order_id} is not a valid order id`);
      } else {
        lookups.set(row.id, { gone: false, order });
      }
    } catch (err) {
      if (isInvalidOrder(err)) {
        streak = 0;
        lookups.set(row.id, { gone: true, order: null });
        console.warn(`[retention] design ${row.id} kept; Woo order ${row.wc_order_id} is not a valid order id`);
        continue;
      }
      if (isAuthError(err)) {
        console.error(`[retention] aborted; Woo auth failed for order ${row.wc_order_id} (${wooFailureStatus(err)})`);
        throw abortError('woo-auth');
      }
      errorCount += 1;
      streak += 1;
      wooErrors.add(row.id);
      console.error(`[retention] skipped design ${row.id}; Woo request failed for order ${row.wc_order_id} (${wooFailureStatus(err)})`);
      const errorFraction = wooRows.length ? errorCount / wooRows.length : 1;
      if (streak > ERROR_STREAK_MAX || (errorCount > 1 && errorFraction > ERROR_FRACTION)) {
        console.error(`[retention] aborted; Woo errors ${errorCount} of ${wooRows.length} (streak ${streak})`);
        throw abortError('woo-error');
      }
    }
  }

  const unpaidTargets = [];
  const paidIds = [];
  const skippedPaid = [];
  const skippedWoo = [];
  const skippedWooError = [];
  const skippedPayable = [];
  const keptInvalid = [];
  const keptNoStamp = [];
  const keptPayableOrder = [];
  const gmtStamps = [];

  for (const row of rows) {
    if (wooErrors.has(row.id)) {
      skippedWooError.push(row.id);
      continue;
    }
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
      if (unpaidDue || localPaid) {
        skippedWoo.push(row.id);
        keptInvalid.push(row.id);
      }
      continue;
    }

    const isPaid = localPaid || wooPaid;
    if (isPaid) {
      const stamp = clockStamp(row, gmt);
      if (gmt && !row.paid_at && !preview) gmtStamps.push({ id: row.id, stamp: paidAtFromWoo(gmt) });
      if (stamp && olderThan(stamp, paidWindow)) paidIds.push(row.id);
      else if (unpaidDue || !stamp) skippedPaid.push(row.id);
      if (!stamp) keptNoStamp.push(row.id);
      continue;
    }

    if (!unpaidDue) continue;

    if (resumeStillValid(row)) {
      skippedPayable.push(row.id);
      continue;
    }

    if (row.wc_order_id) {
      if (!lookup || !order) {
        skippedWoo.push(row.id);
        continue;
      }
      if (DEAD_ORDER_STATUSES.has(wooStatus)) {
        unpaidTargets.push({ id: row.id, status: row.status, wcOrderId: row.wc_order_id });
        continue;
      }
      skippedPayable.push(row.id);
      keptPayableOrder.push(row.id);
      continue;
    }

    unpaidTargets.push({ id: row.id, status: row.status, wcOrderId: row.wc_order_id });
  }

  unpaidTargets.sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
  paidIds.sort();
  const keptIndefinitely = {
    invalidOrderId: keptInvalid.length,
    paidWithoutTimestamp: keptNoStamp.length,
    payableWooOrder: keptPayableOrder.length,
  };
  const keptMode = preview ? 'dry-run ' : '';
  console.log(`[retention] ${keptMode}kept with no end date: invalid-order ${keptInvalid.length}, paid-without-timestamp ${keptNoStamp.length}, payable-order ${keptPayableOrder.length}`);

  const batch = takeBatch(unpaidTargets, paidIds, limit);
  if (preview) {
    logBucket(true, 'would-anonymise unpaid', batch.unpaid.map((item) => item.id));
    logBucket(true, 'would-anonymise paid', batch.paid);
    logBucket(true, 'deferred', batch.deferred);
  }
  logBucket(preview, 'skipped as paid-signals', skippedPaid);
  logBucket(preview, 'skipped as Woo unknown', skippedWoo);
  logBucket(preview, 'skipped as Woo error', skippedWooError);
  logBucket(preview, 'skipped as still payable', skippedPayable);
  if (!preview && batch.deferred.length) logBucket(false, 'deferred', batch.deferred);

  let writtenUnpaid = [];
  let writtenPaid = [];
  if (!preview) {
    if (!heartbeat(owner)) {
      console.error('[retention] skipped; lock lost');
      return emptyResult({ ok: false, aborted: true, reason: 'overlap' });
    }
    const apply = db.transaction(() => {
      for (const item of gmtStamps) {
        if (item.stamp) stampPaidAt.run(item.stamp, item.id);
      }
      backfillLocalPaidAt.run();
      const didUnpaid = [];
      for (const item of batch.unpaid) {
        const result = anonymiseUnpaid.run(
          REDACTED_NAME,
          redactedEmail(item.id),
          item.id,
          item.status,
          item.wcOrderId,
          resumeTtl,
        );
        if (result.changes === 1) didUnpaid.push(item.id);
      }
      const didPaid = [];
      for (const id of batch.paid) {
        const result = anonymisePaid.run(REDACTED_NAME, redactedEmail(id), id, String(paidWindow));
        if (result.changes === 1) didPaid.push(id);
      }
      return { didUnpaid, didPaid };
    });
    const written = apply();
    writtenUnpaid = written.didUnpaid;
    writtenPaid = written.didPaid;
    if (writtenUnpaid.length + writtenPaid.length > 0) checkpointWal();
  }

  const designIds = (preview
    ? [...batch.unpaid.map((item) => item.id), ...batch.paid].sort()
    : [...writtenUnpaid, ...writtenPaid].sort());
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
    deferredIds: batch.deferred,
    wouldAnonymiseUnpaid: preview ? batch.unpaid.map((item) => item.id) : writtenUnpaid,
    wouldAnonymisePaid: preview ? batch.paid : writtenPaid,
    skippedPaidSignals: skippedPaid,
    skippedWooUnknown: skippedWoo,
    skippedWooError,
    skippedStillPayable: skippedPayable,
    keptIndefinitely,
  });
}

async function run(options = {}) {
  const owner = crypto.randomUUID();
  let held = false;
  try {
    held = acquireLock(owner);
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
  const beat = setInterval(() => {
    try { heartbeat(owner); } catch { /* the next write checks ownership */ }
  }, HEARTBEAT_MS);
  if (typeof beat.unref === 'function') beat.unref();
  try {
    return await runLocked(options, owner);
  } catch (err) {
    if (err && err.code === 'RETENTION_ABORT') {
      return emptyResult({ ok: false, aborted: true, reason: err.reason });
    }
    throw err;
  } finally {
    clearInterval(beat);
    try { releaseLock(owner); } catch { /* the connection may already be closed */ }
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
