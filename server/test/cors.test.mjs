import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import { createRequire } from 'node:module';
import { after, before, describe, test } from 'node:test';
import express from 'express';

const require = createRequire(import.meta.url);
const {
  LOCAL_ORIGINS,
  configuredOrigins,
  createCorsMiddleware,
  originAllowed,
} = require('../cors');

const SHOP = 'https://creaturecycles.co.uk';
const EVIL = 'https://creaturecycles.co.uk.evil.com';
const OTHER = 'https://www.creaturecycles.co.uk';

function request(server, { method = 'GET', headers, path = '/api/health' } = {}) {
  return new Promise((resolve, reject) => {
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path,
      method,
      headers,
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let body = raw;
        if (raw) {
          try { body = JSON.parse(raw); } catch { /* keep text */ }
        }
        resolve({ status: res.statusCode, headers: res.headers, body });
      });
    });
    req.on('error', reject);
    req.end();
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

function appFor(allowlist) {
  const app = express();
  app.use(createCorsMiddleware(allowlist));
  app.get('/api/health', (_req, res) => {
    res.json({ status: 'ok' });
  });
  app.use((err, _req, res, _next) => {
    res.status(500).json({ error: 'Internal server error.' });
  });
  return app;
}

describe('CORS origins', () => {
  /** @type {http.Server} */
  let server;
  const production = configuredOrigins({ frontendUrl: `${SHOP}/`, nodeEnv: 'production' });

  before(async () => {
    server = await listen(appFor(production));
  });

  after(async () => {
    if (server) await close(server);
  });

  test('the app allowlist is matched exactly and localhost is dev-only', () => {
    const index = fs.readFileSync(new URL('../index.js', import.meta.url), 'utf8');
    assert.match(index, /configuredOrigins\(\)/);
    assert.match(index, /createCorsMiddleware\(allowedOrigins\)/);
    assert.equal(/origin\.startsWith\(/.test(index), false);
    assert.equal(index.includes('http://localhost:3000'), false);
    assert.equal(index.includes('http://127.0.0.1:5173'), false);

    assert.deepEqual(production, [SHOP]);
    assert.equal(originAllowed(SHOP, production), true);
    assert.equal(originAllowed(EVIL, production), false);
    assert.equal(originAllowed('http://localhost:3000', production), false);
    assert.equal(originAllowed(undefined, production), true);

    for (const nodeEnv of ['development', 'dev']) {
      const dev = configuredOrigins({ frontendUrl: SHOP, nodeEnv });
      assert.deepEqual(dev, [SHOP, ...LOCAL_ORIGINS]);
    }
    assert.deepEqual(configuredOrigins({ frontendUrl: SHOP, nodeEnv: '' }), [SHOP]);
    assert.deepEqual(configuredOrigins({ frontendUrl: SHOP, nodeEnv: 'production' }), [SHOP]);
  });

  test('an allowlisted origin is reflected exactly', async () => {
    const res = await request(server, { headers: { origin: SHOP } });
    assert.equal(res.status, 200);
    assert.equal(res.headers['access-control-allow-origin'], SHOP);
    assert.equal(res.body.status, 'ok');
  });

  test('a refused origin continues with no allow-origin header', async () => {
    const res = await request(server, { headers: { origin: EVIL } });
    assert.equal(res.status, 200);
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.equal(res.body.status, 'ok');
    assert.equal(JSON.stringify(res.body).includes(EVIL), false);

    const www = await request(server, { headers: { origin: OTHER } });
    assert.equal(www.status, 200);
    assert.equal(www.headers['access-control-allow-origin'], undefined);

    const sandboxed = await request(server, { headers: { origin: 'null' } });
    assert.equal(sandboxed.status, 200);
    assert.equal(sandboxed.headers['access-control-allow-origin'], undefined);
  });

  test('a request with no origin is allowed', async () => {
    const res = await request(server);
    assert.equal(res.status, 200);
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.equal(res.body.status, 'ok');
  });

  test('an allowed preflight reflects the origin', async () => {
    const res = await request(server, {
      method: 'OPTIONS',
      headers: {
        origin: SHOP,
        'access-control-request-method': 'POST',
        'access-control-request-headers': 'content-type',
      },
    });
    assert.equal(res.status, 204);
    assert.equal(res.headers['access-control-allow-origin'], SHOP);
    assert.match(res.headers['access-control-allow-methods'], /\bPOST\b/);
    assert.match(res.headers['access-control-allow-headers'], /Content-Type/i);
  });

  test('a refused preflight is 403 with no allow-origin header', async () => {
    const res = await request(server, {
      method: 'OPTIONS',
      headers: {
        origin: OTHER,
        'access-control-request-method': 'POST',
        'access-control-request-headers': 'content-type',
      },
    });
    assert.equal(res.status, 403);
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.deepEqual(res.body, { error: 'origin_not_allowed' });
  });

  test('a prefix-attack preflight is 403 with no allow-origin header', async () => {
    const res = await request(server, {
      method: 'OPTIONS',
      headers: {
        origin: EVIL,
        'access-control-request-method': 'POST',
      },
    });
    assert.equal(res.status, 403);
    assert.equal(res.headers['access-control-allow-origin'], undefined);
    assert.deepEqual(res.body, { error: 'origin_not_allowed' });
    assert.equal(JSON.stringify(res.body).includes(EVIL), false);
  });

  test('localhost is allowed only in development', async () => {
    const devServer = await listen(appFor(configuredOrigins({
      frontendUrl: SHOP,
      nodeEnv: 'development',
    })));
    try {
      const prod = await request(server, { headers: { origin: 'http://localhost:3000' } });
      assert.equal(prod.status, 200);
      assert.equal(prod.headers['access-control-allow-origin'], undefined);
      assert.equal(prod.body.status, 'ok');

      const dev = await request(devServer, { headers: { origin: 'http://localhost:3000' } });
      assert.equal(dev.status, 200);
      assert.equal(dev.headers['access-control-allow-origin'], 'http://localhost:3000');

      const prefix = await request(devServer, {
        method: 'OPTIONS',
        headers: {
          origin: 'http://localhost:3000.evil.com',
          'access-control-request-method': 'POST',
        },
      });
      assert.equal(prefix.status, 403);
      assert.equal(prefix.headers['access-control-allow-origin'], undefined);
    } finally {
      await close(devServer);
    }
  });
});
