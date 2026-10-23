import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const dbPath = path.join(os.tmpdir(), `creature-admin-auth-${process.pid}-${Date.now()}.db`);
process.env.DB_PATH = dbPath;
delete process.env.WC_URL;
delete process.env.WC_CONSUMER_KEY;
delete process.env.WC_CONSUMER_SECRET;
delete process.env.SMTP_PASS;
delete process.env.RESEND_API_KEY;
delete process.env.RETENTION_PURGE_DRY_RUN;
delete process.env.ADMIN_PASSWORD;

const require = createRequire(import.meta.url);
const db = require('../db');
const admin = require('../routes/admin');

const SECRET = 'pw-do-not-log-9f3c';
const WRONG = 'wrong-do-not-log-9f3c';
const ROUTES = [
  { method: 'GET', path: '/api/admin/orders' },
  { method: 'POST', path: '/api/admin/orders/design-1/send-review' },
  { method: 'POST', path: '/api/admin/cleanup-unpaid' },
  { method: 'POST', path: '/api/admin/retention-purge' },
];

const logs = [];
const originals = {
  log: console.log,
  warn: console.warn,
  error: console.error,
};

function record(level) {
  return (...args) => {
    logs.push(args.map((part) => String(part)).join(' '));
    originals[level](...args);
  };
}

function request(server, { method = 'GET', path: reqPath, headers, body } = {}) {
  return new Promise((resolve, reject) => {
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: reqPath,
      method,
      headers,
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let json = raw;
        if (raw) {
          try { json = JSON.parse(raw); } catch { /* keep text */ }
        }
        resolve({ status: res.statusCode, body: json, raw });
      });
    });
    req.on('error', reject);
    if (body) req.end(body);
    else req.end();
  });
}

function listen(app) {
  return new Promise((resolve) => {
    const server = app.listen(0, '127.0.0.1', () => resolve(server));
  });
}

function close(server) {
  return new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
}

function pdfBody() {
  const boundary = '----creatureadmin';
  const body = Buffer.concat([
    Buffer.from(
      `--${boundary}\r\n` +
      'Content-Disposition: form-data; name="pdf"; filename="review.pdf"\r\n' +
      'Content-Type: application/pdf\r\n\r\n',
    ),
    Buffer.from('%PDF-1.1\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n'),
    Buffer.from(`\r\n--${boundary}--\r\n`),
  ]);
  return {
    body,
    headers: {
      'content-type': `multipart/form-data; boundary=${boundary}`,
      'content-length': body.length,
    },
  };
}

function assertNoSecret(text) {
  assert.equal(text.includes(SECRET), false);
  assert.equal(text.includes(WRONG), false);
}

describe('admin auth fails closed', { concurrency: false }, () => {
  /** @type {http.Server} */
  let server;

  before(async () => {
    console.log = record('log');
    console.warn = record('warn');
    console.error = record('error');
    db.prepare(`
      INSERT INTO designs (id, customer_name, customer_email, params, status)
      VALUES ('design-1', 'Ada Lovelace', 'ada@example.com', '{}', 'paid')
    `).run();
    const app = express();
    app.get('/api/health', (_req, res) => {
      res.json({ status: 'ok' });
    });
    app.use('/api/admin', admin);
    server = await listen(app);
  });

  after(async () => {
    console.log = originals.log;
    console.warn = originals.warn;
    console.error = originals.error;
    if (server) await close(server);
    for (const suffix of ['', '-wal', '-shm']) {
      try { fs.unlinkSync(dbPath + suffix); } catch { /* already gone */ }
    }
  });

  test('unset, empty, and whitespace passwords refuse every admin route', async () => {
    const cases = [undefined, '', ' \t\n '];
    for (const value of cases) {
      if (value === undefined) delete process.env.ADMIN_PASSWORD;
      else process.env.ADMIN_PASSWORD = value;
      for (const route of ROUTES) {
        const res = await request(server, route);
        assert.equal(res.status, 503);
        assert.deepEqual(res.body, { error: 'admin_not_configured' });
        assertNoSecret(res.raw);
      }
      const health = await request(server, { path: '/api/health' });
      assert.equal(health.status, 200);
      assert.equal(health.body.status, 'ok');
    }
    const row = db.prepare(`SELECT status, review_token FROM designs WHERE id = 'design-1'`).get();
    assert.equal(row.status, 'paid');
    assert.equal(row.review_token, null);
  });

  test('a wrong password is 401 and the right password is 200', async () => {
    process.env.ADMIN_PASSWORD = SECRET;
    for (const route of ROUTES) {
      const res = await request(server, {
        ...route,
        headers: { authorization: `Bearer ${WRONG}` },
      });
      assert.equal(res.status, 401);
      assert.deepEqual(res.body, { error: 'Unauthorised' });
      assertNoSecret(res.raw);
    }

    const orders = await request(server, {
      method: 'GET',
      path: '/api/admin/orders',
      headers: { authorization: `Bearer ${SECRET}` },
    });
    assert.equal(orders.status, 200);
    assert.equal(orders.body.orders.length, 1);

    const pdf = pdfBody();
    const review = await request(server, {
      method: 'POST',
      path: '/api/admin/orders/design-1/send-review',
      headers: { ...pdf.headers, authorization: `Bearer ${SECRET}` },
      body: pdf.body,
    });
    assert.equal(review.status, 200);
    assert.equal(review.body.ok, true);
    assertNoSecret(review.raw);

    const cleanup = await request(server, {
      method: 'POST',
      path: '/api/admin/cleanup-unpaid',
      headers: { authorization: `Bearer ${SECRET}` },
    });
    assert.equal(cleanup.status, 200);
    assert.equal(cleanup.body.ok, true);

    const purge = await request(server, {
      method: 'POST',
      path: '/api/admin/retention-purge',
      headers: { authorization: `Bearer ${SECRET}` },
    });
    assert.equal(purge.status, 200);
    assert.equal(purge.body.dryRun, true);
  });

  test('startup names ADMIN_PASSWORD and never writes the value', () => {
    const before = logs.length;
    delete process.env.ADMIN_PASSWORD;
    admin.warnIfAdminUnconfigured();
    process.env.ADMIN_PASSWORD = '';
    admin.warnIfAdminUnconfigured();
    process.env.ADMIN_PASSWORD = ' \t ';
    admin.warnIfAdminUnconfigured();
    process.env.ADMIN_PASSWORD = SECRET;
    admin.warnIfAdminUnconfigured();

    const fresh = logs.slice(before);
    assert.equal(fresh.length, 3);
    for (const line of fresh) {
      assert.match(line, /ADMIN_PASSWORD/);
      assertNoSecret(line);
    }
    assertNoSecret(logs.join('\n'));

    assert.equal(admin.passwordsMatch('short', 'much-longer-value'), false);
    assert.equal(admin.passwordsMatch(SECRET, SECRET), true);
    assert.equal(admin.passwordsMatch(WRONG, SECRET), false);

    const index = fs.readFileSync(new URL('../index.js', import.meta.url), 'utf8');
    assert.match(index, /warnIfAdminUnconfigured\(\)/);
    assert.equal(index.includes('process.exit'), false);
    const html = fs.readFileSync(new URL('../public/admin.html', import.meta.url), 'utf8');
    assert.match(html, /Send Review Email/);
    assert.match(html, /\/api\/admin\/orders\/\$\{currentDesignId\}\/send-review/);
    assert.match(html, /res\.status === 503/);
    assert.match(html, /Authorization': 'Bearer '/);
  });
});
