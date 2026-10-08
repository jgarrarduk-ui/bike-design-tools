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
                                                 -- pending | checkout_created | paid | in_review | accepted | delivered | failed | expired
    wc_order_id          TEXT,
    wc_checkout_url      TEXT,
    download_token       TEXT,
    download_expires_at  DATETIME,
    delivered_at         DATETIME,
    resume_token         TEXT,                   -- unguessable; required with the design id to reopen
    product_ids          TEXT,                   -- JSON array from resolveProductIds at save
    design_name          TEXT,                   -- optional frame name for the save email
    resume_expires_at    DATETIME,               -- unpaid resume links last RESUME_TOKEN_TTL_DAYS (default 90)
    deleted_at           DATETIME                -- set when an unpaid design is expired; row is kept
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
// Resume links work while the design is unpaid and resume_expires_at is still
// in the future (90 days from create, unless RESUME_TOKEN_TTL_DAYS overrides it).
if (!existingCols.includes('resume_token')) {
  db.exec('ALTER TABLE designs ADD COLUMN resume_token TEXT');
}
if (!existingCols.includes('product_ids')) {
  db.exec('ALTER TABLE designs ADD COLUMN product_ids TEXT');
}
if (!existingCols.includes('design_name')) {
  db.exec('ALTER TABLE designs ADD COLUMN design_name TEXT');
}
if (!existingCols.includes('resume_expires_at')) {
  db.exec('ALTER TABLE designs ADD COLUMN resume_expires_at DATETIME');
}
if (!existingCols.includes('deleted_at')) {
  db.exec('ALTER TABLE designs ADD COLUMN deleted_at DATETIME');
}
if (!existingCols.includes('revision')) {
  db.exec('ALTER TABLE designs ADD COLUMN revision INTEGER NOT NULL DEFAULT 0');
}
db.exec('CREATE INDEX IF NOT EXISTS idx_designs_resume_tok ON designs (resume_token)');
db.exec(`
  CREATE TABLE IF NOT EXISTS design_revisions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    design_id   TEXT NOT NULL,
    revision    INTEGER NOT NULL,
    params      TEXT NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
  );
  CREATE INDEX IF NOT EXISTS idx_design_revisions_design ON design_revisions (design_id, revision);
`);

module.exports = db;
