'use strict';

const Database = require('better-sqlite3');
const path = require('path');
const fs = require('fs');

const DB_PATH = process.env.DB_PATH || './data/designs.db';

// Ensure the data directory exists
const dbDir = path.dirname(path.resolve(DB_PATH));
if (!fs.existsSync(dbDir)) fs.mkdirSync(dbDir, { recursive: true });

const db = new Database(DB_PATH);

// WAL mode for better concurrent read performance
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

db.exec(`
  CREATE TABLE IF NOT EXISTS designs (
    id                   TEXT PRIMARY KEY,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    customer_name        TEXT NOT NULL,
    customer_email       TEXT NOT NULL,
    params               TEXT NOT NULL,          -- JSON string of bike parameters
    pdf_base64           TEXT,                   -- PDF generated client-side, stored as base64
    status               TEXT NOT NULL DEFAULT 'pending',
                                                 -- pending | checkout_created | paid | in_review | accepted | delivered | failed
    wc_order_id          TEXT,
    wc_checkout_url      TEXT,
    download_token       TEXT,
    download_expires_at  DATETIME,
    delivered_at         DATETIME
  );

  CREATE INDEX IF NOT EXISTS idx_designs_email        ON designs (customer_email);
  CREATE INDEX IF NOT EXISTS idx_designs_status       ON designs (status);
  CREATE INDEX IF NOT EXISTS idx_designs_download_tok ON designs (download_token);
  CREATE INDEX IF NOT EXISTS idx_designs_wc_id        ON designs (wc_order_id);
`);

// Migrations — add columns introduced after initial schema
const existingCols = db.pragma('table_info(designs)').map(c => c.name);
if (!existingCols.includes('review_token')) {
  db.exec('ALTER TABLE designs ADD COLUMN review_token TEXT');
  db.exec('CREATE INDEX IF NOT EXISTS idx_designs_review_tok ON designs (review_token)');
}
if (!existingCols.includes('review_pdf_base64')) {
  db.exec('ALTER TABLE designs ADD COLUMN review_pdf_base64 TEXT');
}
if (!existingCols.includes('review_sent_at')) {
  db.exec('ALTER TABLE designs ADD COLUMN review_sent_at DATETIME');
}
if (!existingCols.includes('accepted_at')) {
  db.exec('ALTER TABLE designs ADD COLUMN accepted_at DATETIME');
}

module.exports = db;
