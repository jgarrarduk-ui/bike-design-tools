import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const require = createRequire(import.meta.url);
const { allowlistFrom, createCorsMiddleware, originAllowed } = require('../cors');

const SHOP = 'https://creaturecycles.co.uk';
const EVIL = 'https://creaturecycles.co.uk.evil.com';

function request(server, headers) {
  return new Promise((resolve, reject) => {
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: '/api/health',
      method: 'GET',
      headers,
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        resolve({
          status: res.statusCode,
          headers: res.headers,
          body: Buffer.concat(chunks).toString('utf8'),
        });
      });
    });
    req.on('error', reject);
    req.end();
  });
}

describe('CORS origins', () => {
  /** @type {http.Server} */
  let server;
  const allowlist = allowlistFrom([
    `${SHOP}/`,
    'http://localhost:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
  ]);

  before(async () => {
    const app = express();
    app.use(createCorsMiddleware(allowlist));
    app.get('/api/health', (_req, res) => {
      res.json({ status: 'ok' });
    });
    app.use((err, _req, res, _next) => {
      res.status(500).json({ error: 'Internal server error.' });
    });
    server = await new Promise((resolve) => {
      const listening = app.listen(0, '127.0.0.1', () => resolve(listening));
    });
  });

  after(async () => {
    if (!server) return;
    await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
  });

  test('the app allowlist is matched exactly', () => {
    const index = fs.readFileSync(new URL('../index.js', import.meta.url), 'utf8');
    assert.match(index, /createCorsMiddleware\(allowedOrigins\)/);
    assert.equal(/origin\.startsWith\(/.test(index), false);
    assert.equal(originAllowed(SHOP, allowlist), true);
    assert.equal(originAllowed('http://localhost:3000', allowlist), true);
    assert.equal(originAllowed(EVIL, allowlist), false);
    assert.equal(originAllowed('http://localhost:3000.evil.com', allowlist), false);
    assert.equal(originAllowed('', allowlist), true);
    assert.equal(originAllowed(undefined, allowlist), true);
  });

  test('an allowlisted origin is reflected exactly', async () => {
    const res = await request(server, { origin: SHOP });
    assert.equal(res.status, 200);
    assert.equal(res.headers['access-control-allow-origin'], SHOP);
    assert.equal(JSON.parse(res.body).status, 'ok');
  });

  test('a prefix lookalike origin is rejected', async () => {
    const res = await request(server, { origin: EVIL });
    assert.equal(res.status, 500);
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.equal(res.body.includes(EVIL), false);
    assert.equal(res.body.includes(SHOP), false);

    const local = await request(server, { origin: 'http://localhost:3000.evil.com' });
    assert.equal(local.status, 500);
    assert.equal(local.headers['access-control-allow-origin'], undefined);
  });

  test('a request with no origin is allowed', async () => {
    const res = await request(server);
    assert.equal(res.status, 200);
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.equal(JSON.parse(res.body).status, 'ok');
  });
});
