'use strict';

/**
 * Data-retention purge.
 *
 * A design is paid, and stays on the six-year clock, when any paid signal is
 * present. Status alone is not enough, and a non-paid status does not make a
 * design unpaid. Paid signals:
 *   - paid_at
 *   - accepted_at, delivered_at, review_sent_at
 *   - review_pdf_base64, review_token
 *   - download_token, download_expires_at (a download link was issued)
 *   - status was a paid-family value: paid, in_review, accepted, delivered,
 *     refunded
 *   - the linked Woo order has date_paid
 *
 * That keeps a design set to failed after accept (the final-files email
 * failed; accepted_at and download_token are still set) and a paid order
 * later cancelled, refunded, or failed. Only a design with none of those
 * signals is eligible at RETENTION_UNPAID_DAYS.
 *
 * Rows with wc_order_id and no paid_at are checked against Woo before a
 * purge decision, in batches. date_paid is copied onto paid_at on a live
 * run. If Woo cannot be checked, that row is skipped. A dry run classifies
 * from date_paid and does not write. Never purge on an unknown order.
 *
 * An unpaid row is anonymised only after unpaid-cleanup has finished with
 * it (status expired or cancelled) or the Woo order is cancelled, failed,
 * or missing. pending and checkout_created rows whose order is still
 * pending are left alone, so a payable checkout is not orphaned.
 *
 * The paid clock is paid_at, otherwise the Woo date_paid found this run,
 * otherwise created_at. RETENTION_UNPAID_DAYS below 30 is raised to 30.
 * RETENTION_PAID_DAYS below 2192 is raised to 2192. A warning is logged.
 * Unset values stay 90 and 2192 with no warning.
 *
 * customer_name and customer_email are NOT NULL. SQLite cannot drop that
 * with ALTER TABLE, and rebuilding the table would rewrite every stored PDF.
 * The purge writes the placeholders "redacted" and "redacted+<id>@invalid"
 * instead. The address is not deliverable, and the id keeps each row distinct
 * on the email index. pdf and tokens are set to NULL. Geometry params and
 * design_revisions stay. anonymised_at makes a second run a no-op.
 *
 * The Request-a-change token lives on the Woo order, not in this database.
 * This job clears the tokens stored on the design row: resume, download, and
 * review, plus their expiries.
 *
 * Schedule matches unpaid cleanup: a first run shortly after boot, then every
 * RETENTION_PURGE_INTERVAL_HOURS (default 24). 0 disables the timer.
 * RETENTION_PURGE_DRY_RUN=1 logs the breakdown and design ids and writes
 * nothing, including the paid_at backfill. Logs never include a name or an
 * email address.
 */

const db = require('../db');

const DEFAULT_UNPAID_DAYS = 90;
const DEFAULT_PAID_DAYS = 2192;
const MIN_UNPAID_DAYS = 30;
const MIN_PAID_DAYS = 2192;
const DEFAULT_INTERVAL_HOURS = 24;
const WOO_BATCH = 50;

const PAID_STATUSES = ['paid', 'in_review', 'accepted', 'delivered', 'refunded'];

// Columns that mean the customer paid or we issued review or delivery files.
// resume_token, pdf_base64, and wc_checkout_url are not paid signals.
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

const CLEANUP_DONE = new Set(['expired', 'cancelled', 'canceled']);
const DEAD_ORDER_STATUSES = new Set(['cancelled', 'canceled', 'failed', 'trash']);

const REDACTED_NAME = 'redacted';

function configuredDays(raw, fallback, floor, envName) {
  if (raw == null || String(raw).trim() === '') return fallback;
  const n = Number(raw);
  if (!Number.isFinite(n) || n < 0) return fallback;
  const days = Math.floor(n);
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

function intervalHours() {
  const raw = process.env.RETENTION_PURGE_INTERVAL_HOURS;
  if (raw == null || String(raw).trim() === '') return DEFAULT_INTERVAL_HOURS;
  const n = Number(raw);
  if (!Number.isFinite(n) || n < 0) return DEFAULT_INTERVAL_HOURS;
  return n;
}

function dryRun() {
  const raw = String(process.env.RETENTION_PURGE_DRY_RUN || '').trim().toLowerCase();
  return raw === '1' || raw === 'true' || raw === 'yes';
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

function hasLocalPaidSignal(row) {
  if (row.paid_at) return true;
  if (PAID_STATUSES.includes(row.status)) return true;
  if (row.accepted_at || row.delivered_at || row.review_sent_at) return true;
  if (row.has_review_pdf) return true;
  if (nonEmpty(row.review_token) || nonEmpty(row.download_token)) return true;
  if (row.download_expires_at) return true;
  return false;
}

function loadRows() {
  return db.prepare(`
    SELECT id, status, created_at, paid_at, accepted_at, delivered_at, review_sent_at,
           CASE
             WHEN review_pdf_base64 IS NOT NULL AND length(review_pdf_base64) > 0 THEN 1
             ELSE 0
           END AS has_review_pdf,
           review_token, download_token, download_expires_at, wc_order_id
    FROM designs
    WHERE anonymised_at IS NULL
    ORDER BY id
  `).all();
}

async function defaultGetOrder(wcOrderId) {
  const woocommerce = require('./woocommerce');
  if (!woocommerce.isConfigured()) {
    const err = new Error('WooCommerce is not configured');
    err.code = 'WOO_UNCONFIGURED';
    throw err;
  }
  return woocommerce.getOrder(wcOrderId);
}

async function lookupOrders(rows, getOrder) {
  const needing = rows.filter((row) => row.wc_order_id && !row.paid_at);
  const map = new Map();
  for (let i = 0; i < needing.length; i += WOO_BATCH) {
    const batch = needing.slice(i, i + WOO_BATCH);
    for (const row of batch) {
      try {
        const order = await getOrder(row.wc_order_id);
        map.set(row.id, { order: order || null, unknown: false });
      } catch {
        // Fail safe: this design is not purged on this run. The error body
        // can echo an order, so it is not logged.
        console.warn(`[retention] design ${row.id} skipped; Woo order ${row.wc_order_id} could not be checked`);
        map.set(row.id, { order: null, unknown: true });
      }
    }
  }
  return map;
}

function anonymise(id) {
  const result = db.prepare(`
    UPDATE designs
    SET customer_name = ?,
        customer_email = ?,
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
  `).run(REDACTED_NAME, redactedEmail(id), id);
  return result.changes === 1;
}

function logBucket(preview, label, ids) {
  const mode = preview ? 'dry-run ' : '';
  const suffix = ids.length ? `: ${ids.join(', ')}` : '';
  console.log(`[retention] ${mode}${label} ${ids.length}${suffix}`);
}

async function run(options = {}) {
  const preview = dryRun();
  const unpaidWindow = unpaidDays();
  const paidWindow = paidDays();
  const getOrder = options.getOrder || defaultGetOrder;
  const rows = loadRows();
  const lookups = await lookupOrders(rows, getOrder);

  const unpaidIds = [];
  const paidIds = [];
  const skippedPaid = [];
  const skippedWoo = [];
  const skippedPayable = [];

  const stampPaidAt = db.prepare(`
    UPDATE designs
    SET paid_at = ?
    WHERE id = ?
      AND paid_at IS NULL
  `);

  for (const row of rows) {
    const lookup = lookups.get(row.id);
    const localPaid = hasLocalPaidSignal(row);
    const unpaidDue = olderThan(row.created_at, unpaidWindow);

    if (lookup && lookup.unknown) {
      const stamp = row.paid_at || row.created_at;
      if (localPaid && !olderThan(stamp, paidWindow)) {
        if (unpaidDue) skippedPaid.push(row.id);
      } else if (unpaidDue || localPaid) {
        skippedWoo.push(row.id);
      }
      continue;
    }

    let paidStamp = row.paid_at || null;
    const datePaid = lookup && lookup.order ? lookup.order.date_paid : null;
    const wooPaid = nonEmpty(datePaid);
    if (wooPaid) {
      const normalised = paidAtFromWoo(datePaid) || paidAtFromWoo(row.created_at);
      if (normalised) {
        paidStamp = paidStamp || normalised;
        if (!preview && !row.paid_at) stampPaidAt.run(normalised, row.id);
      }
    }

    if (localPaid || wooPaid) {
      const stamp = paidStamp || row.created_at;
      if (olderThan(stamp, paidWindow)) paidIds.push(row.id);
      else if (unpaidDue) skippedPaid.push(row.id);
      continue;
    }

    if (!unpaidDue) continue;

    const order = lookup ? lookup.order : null;
    const wooStatus = order ? String(order.status || '').toLowerCase() : '';
    const orderMissing = !row.wc_order_id || (lookup && !lookup.order);
    const orderDead = DEAD_ORDER_STATUSES.has(wooStatus);
    if (CLEANUP_DONE.has(row.status) || orderMissing || orderDead) {
      unpaidIds.push(row.id);
      continue;
    }
    skippedPayable.push(row.id);
  }

  const designIds = [...unpaidIds, ...paidIds].sort();
  if (!preview) {
    for (const id of designIds) anonymise(id);
  }

  logBucket(preview, preview ? 'would-anonymise unpaid' : 'anonymised unpaid', unpaidIds);
  logBucket(preview, preview ? 'would-anonymise paid' : 'anonymised paid', paidIds);
  logBucket(preview, 'skipped as paid-signals', skippedPaid);
  logBucket(preview, 'skipped as Woo unknown', skippedWoo);
  logBucket(preview, 'skipped as still payable', skippedPayable);

  return {
    dryRun: preview,
    unpaidDays: unpaidWindow,
    paidDays: paidWindow,
    count: designIds.length,
    designIds,
    wouldAnonymiseUnpaid: unpaidIds,
    wouldAnonymisePaid: paidIds,
    skippedPaidSignals: skippedPaid,
    skippedWooUnknown: skippedWoo,
    skippedStillPayable: skippedPayable,
  };
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
  PAID_STATUSES,
  PAID_SIGNAL_COLUMNS,
  REDACTED_NAME,
  unpaidDays,
  paidDays,
  intervalHours,
  dryRun,
  redactedEmail,
  run,
  scheduleRetentionPurge,
};
