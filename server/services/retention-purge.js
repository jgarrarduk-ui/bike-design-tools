'use strict';

/**
 * Data-retention purge.
 *
 * Unpaid designs (anything that was never paid: pending, checkout_created,
 * failed, expired, cancelled, and any other non-paid status) are anonymised
 * in place once created_at is older than RETENTION_UNPAID_DAYS (default 90).
 *
 * Paid designs (paid, in_review, accepted, delivered, refunded) are kept for
 * RETENTION_PAID_DAYS (default 2192, about six years) from paid_at, or from
 * created_at when payment was never stamped.
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
 * RETENTION_PURGE_DRY_RUN=1 logs the count and design ids and writes nothing.
 * Logs never include a name or an email address.
 */

const db = require('../db');

const DEFAULT_UNPAID_DAYS = 90;
const DEFAULT_PAID_DAYS = 2192;
const DEFAULT_INTERVAL_HOURS = 24;

const PAID_STATUSES = ['paid', 'in_review', 'accepted', 'delivered', 'refunded'];

const REDACTED_NAME = 'redacted';

function positiveInt(raw, fallback) {
  if (raw == null || String(raw).trim() === '') return fallback;
  const n = Number(raw);
  if (!Number.isFinite(n) || n < 0) return fallback;
  return Math.floor(n);
}

function unpaidDays() {
  return positiveInt(process.env.RETENTION_UNPAID_DAYS, DEFAULT_UNPAID_DAYS);
}

function paidDays() {
  return positiveInt(process.env.RETENTION_PAID_DAYS, DEFAULT_PAID_DAYS);
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

function dueDesigns() {
  const paidList = PAID_STATUSES.map((status) => `'${status}'`).join(', ');
  return db.prepare(`
    SELECT id FROM designs
    WHERE anonymised_at IS NULL
      AND created_at IS NOT NULL
      AND (
        (
          status IN (${paidList})
          AND datetime(COALESCE(paid_at, created_at), '+' || ? || ' days') < datetime('now')
        )
        OR (
          status NOT IN (${paidList})
          AND datetime(created_at, '+' || ? || ' days') < datetime('now')
        )
      )
    ORDER BY id
  `).all(String(paidDays()), String(unpaidDays()));
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

function run() {
  const ids = dueDesigns().map((row) => row.id);
  const preview = dryRun();
  if (!preview) {
    for (const id of ids) anonymise(id);
  }
  const verb = preview ? 'dry-run would anonymise' : 'anonymised';
  console.log(`[retention] ${verb} ${ids.length} design(s)${ids.length ? `: ${ids.join(', ')}` : ''}`);
  return {
    dryRun: preview,
    unpaidDays: unpaidDays(),
    paidDays: paidDays(),
    count: ids.length,
    designIds: ids,
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
    try {
      run();
    } catch (err) {
      console.error('[retention] run failed:', err.message);
    }
  };
  setTimeout(tick, 30 * 1000);
  setInterval(tick, ms);
  const mode = dryRun() ? 'dry-run' : 'live';
  console.log(`  Retention purge : every ${hours}h, unpaid ${unpaidDays()}d, paid ${paidDays()}d, ${mode}`);
}

module.exports = {
  DEFAULT_UNPAID_DAYS,
  DEFAULT_PAID_DAYS,
  PAID_STATUSES,
  REDACTED_NAME,
  unpaidDays,
  paidDays,
  intervalHours,
  dryRun,
  redactedEmail,
  dueDesigns,
  run,
  scheduleRetentionPurge,
};
