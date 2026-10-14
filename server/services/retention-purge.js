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
 * A paid signal with no such timestamp is kept for RETENTION_PAID_DAYS from
 * created_at, then anonymised.
 *
 * An unpaid row is anonymised when the resume link has expired and either:
 *   - it is past RETENTION_UNPAID_DAYS and its Woo order is cancelled or
 *     trash, or it never had a wc_order_id, or
 *   - it is past RETENTION_UNRESOLVED_DAYS (default 365, and never shorter
 *     than RETENTION_UNPAID_DAYS) with no paid signal, whatever the Woo
 *     status. That covers pending, on-hold, and failed orders, an invalid
 *     order id, and an orphan.
 * A resume link that is still valid keeps the row. Woo date_paid or
 * date_paid_gmt means the row is paid and stays on the seven-year clock.
 *
 * Each run writes at most RETENTION_PURGE_MAX eligible rows (default 20)
 * and leaves the rest for the next run. That cap does not abort the run.
 * Cancelled orders are the normal result of the unpaid cleanup, so a large
 * cancelled backlog is drained in batches rather than aborted. The run
 * aborts with no writes when the Woo API check fails, Woo returns 401 or
 * 403, more than three lookups fail in a row, or more than 20% of the
 * lookups fail (at least two). The same streak and fraction apply to
 * invalid order ids (RETENTION_INVALID_ID_STREAK / _FRACTION) and to
 * cancel failures. Any other single-order failure skips that row only.
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
 * holds billing details. Before a one-year unresolved wipe of a payable
 * order (pending, on-hold, or failed), this job fetches the order again
 * and cancels it only when it is still payable, has no paid date, and
 * carries this design id. The read-back must be cancelled with no paid
 * date. A paid date skips the row and alerts info@ once. A dry run does
 * not cancel. A cancel failure skips that row. Too many invalid order ids
 * abort the run with no writes.
 *
 * RETENTION_PURGE_DRY_RUN writes nothing unless the value is exactly "0".
 * Unset, empty, and any other string are a dry run.
 * Day counts below their floors are raised. Non-numeric values log an error
 * and use the default. The timer interval is 0 (off) or clamped to 1–168h.
 */

const crypto = require('crypto');
const db = require('../db');
const cleanup = require('./unpaid-cleanup');
const email = require('./email');
const { maskEmail } = require('./redact');

const DEFAULT_UNPAID_DAYS = 90;
const DEFAULT_UNRESOLVED_DAYS = 365;
const DEFAULT_PAID_DAYS = 2557;
const MIN_UNPAID_DAYS = 30;
const MIN_UNRESOLVED_DAYS = 365;
const MIN_PAID_DAYS = 2557;
const DEFAULT_INTERVAL_HOURS = 24;
const MIN_INTERVAL_HOURS = 1;
const MAX_INTERVAL_HOURS = 168;
const DEFAULT_PURGE_MAX = 20;
const ERROR_FRACTION = 0.2;
const ERROR_STREAK_MAX = 3;
const DEFAULT_INVALID_ID_STREAK = 3;
const DEFAULT_INVALID_ID_FRACTION = 0.2;
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
const PAYABLE_ORDER_STATUSES = new Set(['pending', 'on-hold', 'failed']);
const REDACTED_NAME = 'redacted';
const INVALID_ORDER_CODE = 'woocommerce_rest_shop_order_invalid_id';
const cancellingOrders = new Set();

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

function unresolvedDays() {
  const unpaid = unpaidDays();
  const floor = Math.max(MIN_UNRESOLVED_DAYS, unpaid);
  const fallback = Math.max(DEFAULT_UNRESOLVED_DAYS, unpaid);
  return configuredDays(
    process.env.RETENTION_UNRESOLVED_DAYS,
    fallback,
    floor,
    'RETENTION_UNRESOLVED_DAYS',
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

function invalidIdStreak() {
  return configuredDays(
    process.env.RETENTION_INVALID_ID_STREAK,
    DEFAULT_INVALID_ID_STREAK,
    0,
    'RETENTION_INVALID_ID_STREAK',
  );
}

function invalidIdFraction() {
  const parsed = parseEnvNumber(process.env.RETENTION_INVALID_ID_FRACTION);
  if (parsed.empty) return DEFAULT_INVALID_ID_FRACTION;
  if (!parsed.ok || parsed.n > 1) {
    console.error(`[retention] RETENTION_INVALID_ID_FRACTION=${parsed.text} is not a fraction from 0 to 1; using ${DEFAULT_INVALID_ID_FRACTION}`);
    return DEFAULT_INVALID_ID_FRACTION;
  }
  return parsed.n;
}

function overThreshold(count, streak, total, streakMax, fractionMax) {
  if (streak > streakMax) return true;
  if (count <= 1) return false;
  const fraction = total > 0 ? count / total : 1;
  return fraction > fractionMax;
}

function isRetentionCancel(wcOrderId) {
  return cancellingOrders.has(String(wcOrderId || ''));
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
           review_token, download_token, download_expires_at, wc_order_id,
           customer_email
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

async function defaultCancelOrder(wcOrderId) {
  const woocommerce = require('./woocommerce');
  return woocommerce.cancelOrder(wcOrderId);
}

function orderHasPaidDate(order) {
  return Boolean(order) && (nonEmpty(order.date_paid) || nonEmpty(order.date_paid_gmt));
}

function designIdOnOrder(order) {
  const woocommerce = require('./woocommerce');
  return woocommerce.designIdFromOrder(order);
}

function payableForCancel(order, designId) {
  if (!order) return false;
  const status = String(order.status || '').toLowerCase();
  if (!PAYABLE_ORDER_STATUSES.has(status)) return false;
  if (orderHasPaidDate(order)) return false;
  return designIdOnOrder(order) === designId;
}

function cancelBlockReason(order, designId) {
  if (!order) return 'missing order';
  const status = String(order.status || '').toLowerCase();
  if (!PAYABLE_ORDER_STATUSES.has(status)) return `status ${status || 'unknown'}`;
  if (orderHasPaidDate(order)) return 'paid date';
  if (designIdOnOrder(order) !== designId) return 'design mismatch';
  return 'row changed';
}

async function alertCancelPaid(item, order) {
  const claim = db.prepare(`
    UPDATE designs
    SET retention_alerted_at = CURRENT_TIMESTAMP
    WHERE id = ?
      AND retention_alerted_at IS NULL
      AND anonymised_at IS NULL
  `).run(item.id);
  if (claim.changes !== 1) return;
  try {
    await email.sendRetentionCancelAlert({
      designId: item.id,
      wcOrderId: item.wcOrderId,
      wooStatus: order && order.status,
      customerEmail: item.email,
    });
  } catch {
    db.prepare('UPDATE designs SET retention_alerted_at = NULL WHERE id = ?').run(item.id);
    console.error(`[retention] admin alert failed for design ${item.id} order ${item.wcOrderId}`);
  }
}

function unpaidStillEligible(item) {
  const fresh = db.prepare(`
    SELECT status, created_at, resume_expires_at, paid_at, accepted_at, delivered_at,
           review_sent_at,
           CASE WHEN review_pdf_base64 IS NOT NULL THEN 1 ELSE 0 END AS has_review_pdf,
           review_token, download_token, download_expires_at, wc_order_id, anonymised_at
    FROM designs
    WHERE id = ?
  `).get(item.id);
  if (!fresh || fresh.anonymised_at) return false;
  if (fresh.status !== item.status) return false;
  if (String(fresh.wc_order_id || '') !== String(item.wcOrderId || '')) return false;
  if (hasLocalPaidSignal(fresh)) return false;
  if (resumeStillValid(fresh)) return false;
  return true;
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

const anonymisePaidFromCreated = db.prepare(`
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
    AND status = ?
    AND paid_at IS NULL
    AND accepted_at IS NULL
    AND delivered_at IS NULL
    AND review_sent_at IS NULL
    AND datetime(created_at, '+' || ? || ' days') < datetime('now')
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
    unresolvedDays: unresolvedDays(),
    paidDays: paidDays(),
    count: 0,
    designIds: [],
    writtenIds: [],
    deferredIds: [],
    wouldAnonymiseUnpaid: [],
    wouldAnonymiseUnresolved: [],
    wouldAnonymisePaid: [],
    skippedPaidSignals: [],
    skippedWooUnknown: [],
    skippedWooError: [],
    skippedStillPayable: [],
    wouldCancel: [],
    cancelledOrderIds: [],
    ...extra,
  };
}

function takeBatch(groups, limit) {
  const chosen = [];
  let room = limit;
  const deferred = [];
  for (const group of groups) {
    const take = room > 0 ? group.slice(0, room) : [];
    chosen.push(...take);
    deferred.push(...group.slice(take.length).map((item) => item.id));
    room -= take.length;
  }
  return { chosen, deferred };
}

async function runLocked(options, owner) {
  const preview = dryRun();
  const unpaidWindow = unpaidDays();
  const unresolvedWindow = unresolvedDays();
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
  let invalidCount = 0;
  let invalidStreak = 0;
  const invalidStreakLimit = invalidIdStreak();
  const invalidFractionLimit = invalidIdFraction();

  function noteInvalid(row) {
    invalidCount += 1;
    invalidStreak += 1;
    streak = 0;
    lookups.set(row.id, { gone: true, order: null });
    console.warn(`[retention] design ${row.id} Woo order ${row.wc_order_id} is not a valid order id`);
    if (overThreshold(invalidCount, invalidStreak, wooRows.length, invalidStreakLimit, invalidFractionLimit)) {
      console.error(`[retention] aborted; invalid order ids ${invalidCount} of ${wooRows.length} (streak ${invalidStreak})`);
      throw abortError('invalid-id');
    }
  }

  for (const row of wooRows) {
    try {
      const order = await getOrder(row.wc_order_id);
      if (order == null) {
        noteInvalid(row);
        continue;
      }
      streak = 0;
      invalidStreak = 0;
      lookups.set(row.id, { gone: false, order });
    } catch (err) {
      if (err && err.code === 'RETENTION_ABORT') throw err;
      if (isInvalidOrder(err)) {
        noteInvalid(row);
        continue;
      }
      invalidStreak = 0;
      if (isAuthError(err)) {
        console.error(`[retention] aborted; Woo auth failed for order ${row.wc_order_id} (${wooFailureStatus(err)})`);
        throw abortError('woo-auth');
      }
      errorCount += 1;
      streak += 1;
      wooErrors.add(row.id);
      console.error(`[retention] skipped design ${row.id}; Woo request failed for order ${row.wc_order_id} (${wooFailureStatus(err)})`);
      if (overThreshold(errorCount, streak, wooRows.length, ERROR_STREAK_MAX, ERROR_FRACTION)) {
        console.error(`[retention] aborted; Woo errors ${errorCount} of ${wooRows.length} (streak ${streak})`);
        throw abortError('woo-error');
      }
    }
  }

  const unpaidTargets = [];
  const unresolvedTargets = [];
  const paidTargets = [];
  const paidFromCreated = [];
  const skippedPaid = [];
  const skippedWoo = [];
  const skippedWooError = [];
  const skippedPayable = [];
  const gmtStamps = [];

  function target(row) {
    return { id: row.id, status: row.status, wcOrderId: row.wc_order_id || null };
  }

  for (const row of rows) {
    if (wooErrors.has(row.id)) {
      skippedWooError.push(row.id);
      continue;
    }
    const lookup = lookups.get(row.id);
    const localPaid = hasLocalPaidSignal(row);
    const unpaidDue = olderThan(row.created_at, unpaidWindow);
    const unresolvedDue = olderThan(row.created_at, unresolvedWindow);
    const order = lookup && lookup.order ? lookup.order : null;
    const gmt = order && nonEmpty(order.date_paid_gmt) ? order.date_paid_gmt : null;
    const wooStatus = order ? String(order.status || '').toLowerCase() : '';
    const wooPaid = Boolean(order) && (
      nonEmpty(order.date_paid_gmt)
      || nonEmpty(order.date_paid)
      || WOO_PAID_STATUSES.has(wooStatus)
    );

    const isPaid = localPaid || wooPaid;
    if (isPaid) {
      const stamp = clockStamp(row, gmt);
      if (gmt && !row.paid_at && !preview) gmtStamps.push({ id: row.id, stamp: paidAtFromWoo(gmt) });
      if (stamp && olderThan(stamp, paidWindow)) paidTargets.push({ id: row.id, kind: 'paid' });
      else if (!stamp && olderThan(row.created_at, paidWindow)) {
        paidFromCreated.push({ id: row.id, status: row.status, kind: 'paid-created' });
      } else skippedPaid.push(row.id);
      continue;
    }

    if (!unpaidDue && !unresolvedDue) continue;

    if (resumeStillValid(row)) {
      skippedPayable.push(row.id);
      continue;
    }

    if (lookup && lookup.gone) {
      if (unresolvedDue) unresolvedTargets.push({ ...target(row), kind: 'unresolved' });
      else skippedWoo.push(row.id);
      continue;
    }

    if (row.wc_order_id) {
      if (!lookup || !order) {
        skippedWoo.push(row.id);
        continue;
      }
      if (unpaidDue && DEAD_ORDER_STATUSES.has(wooStatus)) {
        unpaidTargets.push({ ...target(row), kind: 'unpaid' });
        continue;
      }
      if (unresolvedDue) {
        unresolvedTargets.push({
          ...target(row),
          kind: 'unresolved',
          needsCancel: PAYABLE_ORDER_STATUSES.has(wooStatus),
          email: row.customer_email,
        });
        continue;
      }
      skippedPayable.push(row.id);
      continue;
    }

    unpaidTargets.push({ ...target(row), kind: 'unpaid' });
  }

  const byId = (a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
  unpaidTargets.sort(byId);
  unresolvedTargets.sort(byId);
  paidTargets.sort(byId);
  paidFromCreated.sort(byId);

  const batch = takeBatch([unpaidTargets, unresolvedTargets, paidTargets, paidFromCreated], limit);
  let chosen = batch.chosen;
  const cancelOrder = options.cancelOrder || defaultCancelOrder;
  if (!preview) {
    if (!heartbeat(owner)) {
      console.error('[retention] skipped; lock lost');
      return emptyResult({ ok: false, aborted: true, reason: 'overlap' });
    }
    chosen = chosen.filter((item) => !item.needsCancel || unpaidStillEligible(item));
  }
  const cancelCandidates = chosen.filter((item) => item.needsCancel);
  const confirmed = [];
  if (!preview && cancelCandidates.length) {
    let cancelErrors = 0;
    let cancelStreak = 0;
    for (const item of cancelCandidates) {
      const orderKey = String(item.wcOrderId);
      cancellingOrders.add(orderKey);
      try {
        const latest = await getOrder(item.wcOrderId);
        if (!payableForCancel(latest, item.id) || !unpaidStillEligible(item)) {
          cancellingOrders.delete(orderKey);
          console.error(`[retention] skipped design ${item.id}; Woo order ${item.wcOrderId} not cancelled (${cancelBlockReason(latest, item.id)})`);
          continue;
        }
        await cancelOrder(item.wcOrderId);
        const again = await getOrder(item.wcOrderId);
        const status = again ? String(again.status || '').toLowerCase() : '';
        if (status === 'cancelled' && !orderHasPaidDate(again)) {
          confirmed.push(item.id);
          cancelStreak = 0;
          continue;
        }
        if (orderHasPaidDate(again) || WOO_PAID_STATUSES.has(status)) {
          cancellingOrders.delete(orderKey);
          cancelStreak = 0;
          skippedPaid.push(item.id);
          console.error(`[retention] skipped design ${item.id}; Woo order ${item.wcOrderId} read back paid (${status || 'unknown'}) ${maskEmail(item.email)}`);
          await alertCancelPaid(item, again);
          continue;
        }
        const err = new Error('Woo order did not read back as cancelled');
        err.status = again == null ? 404 : 409;
        throw err;
      } catch (err) {
        cancellingOrders.delete(orderKey);
        if (err && err.code === 'RETENTION_ABORT') throw err;
        if (isAuthError(err)) {
          console.error(`[retention] aborted; Woo auth failed cancelling order ${item.wcOrderId} (${wooFailureStatus(err)})`);
          throw abortError('woo-auth');
        }
        cancelErrors += 1;
        cancelStreak += 1;
        skippedWooError.push(item.id);
        console.error(`[retention] skipped design ${item.id}; Woo cancel failed for order ${item.wcOrderId} (${wooFailureStatus(err)}) ${maskEmail(item.email)}`);
        if (overThreshold(cancelErrors, cancelStreak, cancelCandidates.length, ERROR_STREAK_MAX, ERROR_FRACTION)) {
          console.error(`[retention] aborted; Woo cancel errors ${cancelErrors} of ${cancelCandidates.length} (streak ${cancelStreak})`);
          throw abortError('woo-error');
        }
      }
    }
    const confirmedSet = new Set(confirmed);
    chosen = chosen.filter((item) => !item.needsCancel || confirmedSet.has(item.id));
  }

  const idsOf = (kind) => chosen.filter((item) => item.kind === kind).map((item) => item.id);
  const batchUnpaid = idsOf('unpaid');
  const batchUnresolved = idsOf('unresolved');
  const batchPaid = [...idsOf('paid'), ...idsOf('paid-created')];
  const wouldCancel = cancelCandidates.map((item) => item.id);
  if (preview) {
    logBucket(true, 'would-anonymise unpaid', batchUnpaid);
    logBucket(true, 'would-anonymise paid', batchPaid);
    logBucket(true, 'would-cancel', wouldCancel);
    logBucket(true, 'deferred', batch.deferred);
  }
  if (preview) logBucket(true, `unresolved past ${unresolvedWindow}d`, batchUnresolved);
  logBucket(preview, 'skipped as paid-signals', skippedPaid);
  logBucket(preview, 'skipped as Woo unknown', skippedWoo);
  logBucket(preview, 'skipped as Woo error', skippedWooError);
  logBucket(preview, 'skipped as still payable', skippedPayable);
  if (!preview && batch.deferred.length) logBucket(false, 'deferred', batch.deferred);
  if (!preview && confirmed.length) logBucket(false, 'cancelled before wipe', confirmed);

  let writtenUnpaid = [];
  let writtenUnresolved = [];
  let writtenPaid = [];
  if (!preview) {
    const apply = db.transaction(() => {
      for (const item of gmtStamps) {
        if (item.stamp) stampPaidAt.run(item.stamp, item.id);
      }
      backfillLocalPaidAt.run();
      const didUnpaid = [];
      const didUnresolved = [];
      const didPaid = [];
      for (const item of chosen) {
        if (item.kind === 'unpaid' || item.kind === 'unresolved') {
          const result = anonymiseUnpaid.run(
            REDACTED_NAME,
            redactedEmail(item.id),
            item.id,
            item.status,
            item.wcOrderId,
            resumeTtl,
          );
          if (result.changes !== 1) continue;
          if (item.kind === 'unpaid') didUnpaid.push(item.id);
          else didUnresolved.push(item.id);
        } else if (item.kind === 'paid') {
          const result = anonymisePaid.run(REDACTED_NAME, redactedEmail(item.id), item.id, String(paidWindow));
          if (result.changes === 1) didPaid.push(item.id);
        } else {
          const result = anonymisePaidFromCreated.run(
            REDACTED_NAME,
            redactedEmail(item.id),
            item.id,
            item.status,
            String(paidWindow),
          );
          if (result.changes === 1) didPaid.push(item.id);
        }
      }
      return { didUnpaid, didUnresolved, didPaid };
    });
    const written = apply();
    writtenUnpaid = written.didUnpaid;
    writtenUnresolved = written.didUnresolved;
    writtenPaid = written.didPaid;
    if (writtenUnpaid.length + writtenUnresolved.length + writtenPaid.length > 0) checkpointWal();
  }

  const designIds = (preview
    ? [...batchUnpaid, ...batchUnresolved, ...batchPaid].sort()
    : [...writtenUnpaid, ...writtenUnresolved, ...writtenPaid].sort());
  if (!preview) {
    logBucket(false, 'anonymised unpaid', writtenUnpaid);
    logBucket(false, 'anonymised paid', writtenPaid);
    logBucket(false, `unresolved past ${unresolvedWindow}d`, writtenUnresolved);
  }

  return emptyResult({
    dryRun: preview,
    unpaidDays: unpaidWindow,
    unresolvedDays: unresolvedWindow,
    paidDays: paidWindow,
    count: designIds.length,
    designIds,
    writtenIds: preview ? [] : designIds,
    deferredIds: batch.deferred,
    wouldAnonymiseUnpaid: preview ? batchUnpaid : writtenUnpaid,
    wouldAnonymiseUnresolved: preview ? batchUnresolved : writtenUnresolved,
    wouldAnonymisePaid: preview ? batchPaid : writtenPaid,
    skippedPaidSignals: skippedPaid,
    skippedWooUnknown: skippedWoo,
    skippedWooError,
    skippedStillPayable: skippedPayable,
    wouldCancel: preview ? wouldCancel : [],
    cancelledOrderIds: preview ? [] : confirmed,
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
    cancellingOrders.clear();
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
  console.log(`  Retention purge : every ${hours}h, unpaid ${unpaidDays()}d, unresolved ${unresolvedDays()}d, paid ${paidDays()}d, ${mode}`);
}

module.exports = {
  DEFAULT_UNPAID_DAYS,
  DEFAULT_UNRESOLVED_DAYS,
  DEFAULT_PAID_DAYS,
  MIN_UNPAID_DAYS,
  MIN_UNRESOLVED_DAYS,
  MIN_PAID_DAYS,
  MIN_INTERVAL_HOURS,
  MAX_INTERVAL_HOURS,
  DEFAULT_PURGE_MAX,
  PAID_STATUSES,
  PAID_SIGNAL_COLUMNS,
  DEAD_ORDER_STATUSES,
  REDACTED_NAME,
  unpaidDays,
  unresolvedDays,
  paidDays,
  purgeMax,
  invalidIdStreak,
  invalidIdFraction,
  intervalHours,
  dryRun,
  redactedEmail,
  isRetentionCancel,
  run,
  scheduleRetentionPurge,
};
