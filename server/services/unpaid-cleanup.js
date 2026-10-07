'use strict';

/**
 * Unpaid frame-design cleanup.
 *
 * A save, and a later edit that changes geometry or parts, each create a new
 * pending Woo order. The previous unpaid order is left in place. This job
 * retires those scrapped orders after the resume-token TTL (default 90 days):
 *
 *   - designs still pending or checkout_created past resume_expires_at
 *     (or created_at + TTL when the expiry column is empty)
 *     move to status "expired" and get deleted_at. The row stays.
 *   - the matching Woo order is cancelled only when it is still pending.
 *
 * Nothing here touches a paid design. Schedule it with
 * UNPAID_CLEANUP_INTERVAL_HOURS, or POST /api/admin/cleanup-unpaid.
 * WordPress can cancel the same pending orders from mu-plugins/
 * creature-unpaid-design-orders.php if this process is not running.
 */

const db = require('../db');
const woocommerce = require('./woocommerce');

const DEFAULT_TTL_DAYS = 90;

function resumeTtlDays() {
  const raw = process.env.RESUME_TOKEN_TTL_DAYS;
  if (raw == null || String(raw).trim() === '') return DEFAULT_TTL_DAYS;
  const n = Number(raw);
  if (!Number.isFinite(n) || n <= 0) return DEFAULT_TTL_DAYS;
  return Math.floor(n);
}

function resumeExpired(design, nowSql) {
  if (!design) return true;
  const days = String(resumeTtlDays());
  const now = nowSql || "datetime('now')";
  if (design.resume_expires_at) {
    const row = db.prepare(`SELECT datetime(?) <= ${now} AS expired`).get(design.resume_expires_at);
    return !!(row && row.expired);
  }
  if (!design.created_at) return false;
  const row = db.prepare(`
    SELECT datetime(?, '+' || ? || ' days') <= ${now} AS expired
  `).get(design.created_at, days);
  return !!(row && row.expired);
}

function expiredUnpaidDesigns() {
  const days = String(resumeTtlDays());
  return db.prepare(`
    SELECT * FROM designs
    WHERE status IN ('pending', 'checkout_created')
      AND deleted_at IS NULL
      AND (
        (resume_expires_at IS NOT NULL AND datetime(resume_expires_at) <= datetime('now'))
        OR (
          resume_expires_at IS NULL
          AND created_at IS NOT NULL
          AND datetime(created_at, '+' || ? || ' days') <= datetime('now')
        )
      )
  `).all(days);
}

async function expireDesign(design, cancelOrder) {
  let woo = { skipped: 'no-order' };
  if (design.wc_order_id && cancelOrder) {
    try {
      woo = await cancelOrder(design.wc_order_id);
    } catch (err) {
      woo = { error: err.message };
      console.warn('[cleanup] Woo cancel failed for', design.id + ':', err.message);
    }
  }

  db.prepare(`
    UPDATE designs
    SET status = 'expired',
        deleted_at = CURRENT_TIMESTAMP
    WHERE id = ?
      AND status IN ('pending', 'checkout_created')
  `).run(design.id);

  return { designId: design.id, wcOrderId: design.wc_order_id || null, woo };
}

/**
 * @param {{ cancelOrder?: (id: string) => Promise<object> }} [opts]
 */
async function run(opts = {}) {
  const cancelOrder = Object.prototype.hasOwnProperty.call(opts, 'cancelOrder')
    ? opts.cancelOrder
    : (woocommerce.isConfigured() ? woocommerce.cancelPendingOrder : null);
  const rows = expiredUnpaidDesigns();
  const expired = [];
  for (const design of rows) {
    expired.push(await expireDesign(design, cancelOrder));
  }
  if (expired.length) {
    console.log(`[cleanup] expired ${expired.length} unpaid design(s)`);
  }
  return { ttlDays: resumeTtlDays(), expired };
}

function scheduleUnpaidCleanup() {
  const hours = Number(process.env.UNPAID_CLEANUP_INTERVAL_HOURS);
  if (!Number.isFinite(hours) || hours <= 0) {
    console.log('  Unpaid cleanup : (not scheduled — set UNPAID_CLEANUP_INTERVAL_HOURS)');
    return;
  }
  const ms = hours * 60 * 60 * 1000;
  const tick = () => {
    run().catch((err) => console.error('[cleanup] run failed:', err.message));
  };
  setTimeout(tick, 30 * 1000);
  setInterval(tick, ms);
  console.log(`  Unpaid cleanup : every ${hours}h, TTL ${resumeTtlDays()}d`);
}

module.exports = {
  DEFAULT_TTL_DAYS,
  resumeTtlDays,
  resumeExpired,
  expiredUnpaidDesigns,
  run,
  scheduleUnpaidCleanup,
};
