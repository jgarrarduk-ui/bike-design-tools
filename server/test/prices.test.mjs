import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import { createRequire } from 'node:module';
import { after, afterEach, before, beforeEach, describe, test } from 'node:test';
import express from 'express';

const require = createRequire(import.meta.url);
const prices = require('../services/prices');
const pricesRouter = require('../routes/prices');

const CATALOGUE = {
  WC_URL: 'https://shop.example',
  WC_CONSUMER_KEY: 'ck_test',
  WC_CONSUMER_SECRET: 'cs_test',
  WC_PRODUCT_IDS: '8634,8635,8636,8637',
  WC_PRODUCT_ID: '8634',
  WC_PRODUCT_PRICE: '49.00',
};

const ENV_KEYS = [
  'WC_URL', 'WC_CONSUMER_KEY', 'WC_CONSUMER_SECRET',
  'WC_PRODUCT_ID', 'WC_PRODUCT_IDS', 'WC_PRODUCT_PRICE', 'WC_API_VERSION',
  'WC_PRICE_TIMEOUT_MS', 'WC_PRICE_BACKOFF_MS',
];

async function withEnv(vars, fn) {
  const prev = new Map(ENV_KEYS.map(key => [key, process.env[key]]));
  for (const key of ENV_KEYS) delete process.env[key];
  for (const [key, value] of Object.entries(vars)) {
    if (value == null) delete process.env[key];
    else process.env[key] = String(value);
  }
  try {
    return await fn();
  } finally {
    for (const [key, value] of prev) {
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
  }
}

function json(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

function product(id, price, extra = {}) {
  const names = { 8634: 'BB yoke', 8635: 'SS yoke', 8636: 'Dropouts', 8637: 'Whole rear end' };
  return {
    id,
    name: names[id] || 'Other',
    price,
    tax_status: 'taxable',
    tax_class: '',
    status: 'publish',
    stock_status: 'instock',
    ...extra,
  };
}

function baseProducts() {
  return [
    product(8634, '50.00'),
    product(8635, '36.00'),
    product(8636, '64.00'),
    product(8637, '100.00'),
    product(9999, '12.00', { name: 'Other', status: 'publish' }),
  ];
}

const GENERAL_NO_TAX = [
  { id: 'woocommerce_currency', value: 'GBP' },
  { id: 'woocommerce_default_country', value: 'GB' },
  { id: 'woocommerce_calc_taxes', value: 'no' },
];

function vatSettings(includeTax) {
  return [
    { id: 'woocommerce_prices_include_tax', value: includeTax ? 'yes' : 'no' },
    { id: 'woocommerce_tax_round_at_subtotal', value: 'no' },
  ];
}

const GB_VAT = [
  { id: 1, country: 'GB', state: '', postcode: '', city: '', rate: '20.0000', name: 'VAT', compound: false, shipping: true, class: 'standard' },
  { id: 2, country: 'US', state: 'CA', rate: '8.2500', compound: false, class: 'standard' },
];

function get(server, urlPath) {
  return new Promise((resolve, reject) => {
    const addr = server.address();
    const req = http.request({
      hostname: '127.0.0.1',
      port: addr.port,
      path: urlPath,
      method: 'GET',
    }, (res) => {
      const chunks = [];
      res.on('data', (chunk) => chunks.push(chunk));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        let body = raw;
        try { body = JSON.parse(raw); } catch { /* keep text */ }
        resolve({ status: res.statusCode, headers: res.headers, body });
      });
    });
    req.on('error', reject);
    req.end();
  });
}

const app = express();
app.use('/api/prices', pricesRouter);

describe('GET /api/prices', { concurrency: false }, () => {
  /** @type {http.Server} */
  let server;
  let now;
  let state;
  let calls;
  let originalFetch;

  before(async () => {
    server = await new Promise((resolve) => {
      const listening = app.listen(0, '127.0.0.1', () => resolve(listening));
    });
  });

  after(async () => {
    global.fetch = originalFetch;
    prices.resetForTests();
    if (server) {
      await new Promise((resolve, reject) => server.close((err) => (err ? reject(err) : resolve())));
    }
  });

  beforeEach(() => {
    prices.resetForTests();
    now = 1_700_000_000_000;
    prices.setNowForTests(() => now);
    state = {
      fail: false,
      products: baseProducts(),
      general: GENERAL_NO_TAX,
      taxSettings: vatSettings(true),
      rates: GB_VAT,
      productFetches: 0,
      hang: false,
    };
    calls = [];
    originalFetch = global.fetch;
    global.fetch = async (url, opts = {}) => {
      const href = String(url);
      const method = opts.method || 'GET';
      calls.push({ href, method, body: opts.body || null });
      if (method !== 'GET' || opts.body) throw new Error(`Woo write ${method} ${href}`);
      if (state.hang) {
        if (href.includes('/products?')) state.productFetches += 1;
        await new Promise((resolve, reject) => {
          const signal = opts.signal;
          if (!signal) {
            reject(new Error('price fetch has no timeout'));
            return;
          }
          const fail = () => reject(signal.reason || new Error('The operation was aborted'));
          if (signal.aborted) fail();
          else signal.addEventListener('abort', fail, { once: true });
        });
        return json({});
      }
      if (state.fail) return json({ message: 'shop down' }, 500);
      if (href.includes('/products?')) {
        state.productFetches += 1;
        const parsed = new URL(href);
        assert.equal(parsed.searchParams.get('status'), 'any');
        assert.equal(parsed.searchParams.get('_fields'), 'id,name,price,tax_status,tax_class,status,stock_status');
        assert.deepEqual(
          (parsed.searchParams.get('include') || '').split(',').sort(),
          ['8634', '8635', '8636'],
        );
        return json(state.products);
      }
      if (href.includes('/settings/general')) return json(state.general);
      if (href.includes('/settings/tax')) return json(state.taxSettings);
      if (href.includes('/taxes?')) return json(state.rates);
      throw new Error(`unexpected Woo GET ${href}`);
    };
  });

  afterEach(() => {
    global.fetch = originalFetch;
    prices.resetForTests();
  });

  test('the route is mounted after CORS and before the static files', () => {
    const index = fs.readFileSync(new URL('../index.js', import.meta.url), 'utf8');
    const corsAt = index.indexOf('app.use(cors(');
    const pricesAt = index.indexOf("app.use('/api/prices'");
    const staticAt = index.indexOf('express.static');
    assert.ok(corsAt >= 0 && pricesAt > corsAt && staticAt > pricesAt);
  });

  test('a cache hit within the TTL does not refetch, and only FD ids are returned', async () => {
    await withEnv(CATALOGUE, async () => {
      const first = await get(server, '/api/prices');
      now += prices.TTL_MS - 1;
      const second = await get(server, '/api/prices');

      assert.equal(first.status, 200);
      assert.equal(first.headers['cache-control'], 'public, max-age=60');
      assert.equal(first.body.currency, 'GBP');
      assert.equal(state.productFetches, 1);
      assert.equal(second.status, 200);
      assert.equal(second.body.fetched_at, first.body.fetched_at);
      assert.deepEqual(second.body.products, first.body.products);
      assert.deepEqual(Object.keys(first.body.products).sort(), ['8634', '8635', '8636']);
      assert.equal(first.body.products['8634'].price, '50.00');
      assert.equal(first.body.products['8634'].name, 'BB yoke');
      assert.equal(first.body.products['8634'].available, true);
      assert.equal(first.body.products['8635'].price, '36.00');
      assert.equal(first.body.products['8636'].price, '64.00');
      assert.equal(first.body.products['8637'], undefined);
      assert.equal(JSON.stringify(first.body).includes('100.00'), false);
      assert.equal(JSON.stringify(first.body).includes('49.00'), false);
      assert.equal(calls.every(call => call.method === 'GET' && call.body == null), true);
      assert.equal(calls.some(call => call.href.includes('/settings/tax')), false);
    });
  });

  test('a missing FD product is unavailable and has no invented price', async () => {
    state.products = [
      product(8634, ''),
      product(8635, '36.00'),
      product(8637, '100.00'),
    ];
    await withEnv(CATALOGUE, async () => {
      const res = await get(server, '/api/prices');
      assert.equal(res.status, 200);
      assert.deepEqual(Object.keys(res.body.products).sort(), ['8634', '8635', '8636']);
      assert.equal(res.body.products['8634'].available, false);
      assert.equal('price' in res.body.products['8634'], false);
      assert.equal(res.body.products['8636'].available, false);
      assert.equal('price' in res.body.products['8636'], false);
      assert.equal(res.body.products['8635'].price, '36.00');
      assert.equal(JSON.stringify(res.body).includes('58'), false);
      assert.equal(JSON.stringify(res.body).includes('100.00'), false);
    });
  });

  test('the TTL expiry refetches', async () => {
    await withEnv(CATALOGUE, async () => {
      const first = await get(server, '/api/prices');
      now += prices.TTL_MS;
      state.products = [
        product(8634, '51.00'),
        product(8635, '36.00'),
        product(8636, '64.00'),
        product(8637, '100.00'),
      ];
      const second = await get(server, '/api/prices');
      assert.equal(state.productFetches, 2);
      assert.equal(second.status, 200);
      assert.equal(second.body.products['8634'].price, '51.00');
      assert.notEqual(second.body.fetched_at, first.body.fetched_at);
      assert.deepEqual(Object.keys(second.body.products).sort(), ['8634', '8635', '8636']);
    });
  });

  test('a Woo error under an hour serves the last good result', async () => {
    await withEnv(CATALOGUE, async () => {
      const first = await get(server, '/api/prices');
      now += prices.STALE_MS - 1;
      state.fail = true;
      const stale = await get(server, '/api/prices');
      assert.equal(stale.status, 200);
      assert.equal(stale.headers['cache-control'], 'public, max-age=60');
      assert.equal(stale.body.fetched_at, first.body.fetched_at);
      assert.equal(stale.body.products['8634'].price, '50.00');
      assert.equal(calls.filter(call => call.href.includes('/products?')).length, 2);
    });
  });

  test('a Woo error after an hour, or with no cache, is 503', async () => {
    await withEnv(CATALOGUE, async () => {
      state.fail = true;
      const cold = await get(server, '/api/prices');
      assert.equal(cold.status, 503);
      assert.deepEqual(cold.body, { error: 'prices_unavailable' });
      assert.equal(cold.headers['cache-control'], 'no-store');

      state.fail = false;
      now += prices.priceBackoffMs();
      const warm = await get(server, '/api/prices');
      assert.equal(warm.status, 200);
      now += prices.STALE_MS;
      state.fail = true;
      const expired = await get(server, '/api/prices');
      assert.equal(expired.status, 503);
      assert.deepEqual(expired.body, { error: 'prices_unavailable' });
    });
  });

  test('concurrent callers share one Woo fetch', async () => {
    let release;
    let productFetches = 0;
    const gate = new Promise((resolve) => { release = resolve; });
    global.fetch = async (url, opts = {}) => {
      const href = String(url);
      const method = opts.method || 'GET';
      if (method !== 'GET' || opts.body) throw new Error(`Woo write ${method}`);
      if (href.includes('/products?')) {
        productFetches += 1;
        await gate;
        return json(baseProducts());
      }
      if (href.includes('/settings/general')) return json(GENERAL_NO_TAX);
      throw new Error(`unexpected Woo GET ${href}`);
    };

    await withEnv(CATALOGUE, async () => {
      const left = prices.getPrices();
      const right = prices.getPrices();
      assert.equal(productFetches, 1);
      release();
      const [a, b] = await Promise.all([left, right]);
      assert.equal(productFetches, 1);
      assert.equal(a.status, 200);
      assert.equal(b.status, 200);
      assert.equal(a.body.fetched_at, b.body.fetched_at);
      assert.deepEqual(Object.keys(a.body.products).sort(), ['8634', '8635', '8636']);
      assert.equal(a.body.products['8637'], undefined);
    });
  });

  test('a timeout serves a cached result under an hour old', async () => {
    await withEnv({ ...CATALOGUE, WC_PRICE_TIMEOUT_MS: '40' }, async () => {
      const first = await get(server, '/api/prices');
      assert.equal(first.status, 200);
      assert.equal(prices.priceTimeoutMs(), 40);
      now += prices.TTL_MS;
      state.hang = true;
      const stale = await get(server, '/api/prices');
      assert.equal(stale.status, 200);
      assert.equal(stale.headers['cache-control'], 'public, max-age=60');
      assert.equal(stale.body.fetched_at, first.body.fetched_at);
      assert.equal(stale.body.products['8634'].price, '50.00');
      assert.equal(state.productFetches, 2);
    });
  });

  test('a timeout with no cache returns 503', async () => {
    await withEnv({ ...CATALOGUE, WC_PRICE_TIMEOUT_MS: '40' }, async () => {
      state.hang = true;
      const cold = await get(server, '/api/prices');
      assert.equal(cold.status, 503);
      assert.deepEqual(cold.body, { error: 'prices_unavailable' });
      assert.equal(cold.headers['cache-control'], 'no-store');
      assert.equal(JSON.stringify(cold.body).includes('50'), false);
      const during = state.productFetches;
      const again = await get(server, '/api/prices');
      assert.equal(again.status, 503);
      assert.equal(state.productFetches, during);
    });
  });

  test('a failed fetch does not call Woo during backoff, then refetches', async () => {
    await withEnv(CATALOGUE, async () => {
      assert.equal(prices.priceTimeoutMs(), prices.DEFAULT_TIMEOUT_MS);
      assert.equal(prices.priceBackoffMs(), prices.DEFAULT_BACKOFF_MS);
      const first = await get(server, '/api/prices');
      assert.equal(first.status, 200);
      now += prices.TTL_MS;
      state.fail = true;
      const failed = await get(server, '/api/prices');
      assert.equal(failed.status, 200);
      assert.equal(failed.body.products['8634'].price, '50.00');
      const fetches = state.productFetches;
      now += prices.priceBackoffMs() - 1;
      const during = await get(server, '/api/prices');
      assert.equal(during.status, 200);
      assert.equal(during.body.fetched_at, first.body.fetched_at);
      assert.equal(state.productFetches, fetches);
      now += 1;
      state.fail = false;
      state.products = [
        product(8634, '52.00'),
        product(8635, '36.00'),
        product(8636, '64.00'),
      ];
      const again = await get(server, '/api/prices');
      assert.equal(state.productFetches, fetches + 1);
      assert.equal(again.status, 200);
      assert.equal(again.body.products['8634'].price, '52.00');
      now += prices.TTL_MS;
      state.fail = true;
      await get(server, '/api/prices');
      const afterSecondFail = state.productFetches;
      now += 1000;
      const held = await get(server, '/api/prices');
      assert.equal(held.status, 200);
      assert.equal(held.body.products['8634'].price, '52.00');
      assert.equal(state.productFetches, afterSecondFail);
    });
  });

  test('backoff is clamped to 30–60 seconds', async () => {
    await withEnv({ ...CATALOGUE, WC_PRICE_BACKOFF_MS: '1000' }, async () => {
      assert.equal(prices.priceBackoffMs(), 30000);
    });
    await withEnv({ ...CATALOGUE, WC_PRICE_BACKOFF_MS: '120000' }, async () => {
      assert.equal(prices.priceBackoffMs(), 60000);
    });
    await withEnv({ ...CATALOGUE, WC_PRICE_BACKOFF_MS: 'not-a-number' }, async () => {
      assert.equal(prices.priceBackoffMs(), 45000);
    });
  });

  test('a draft or private product is unavailable and has no price', async () => {
    state.products = [
      product(8634, '50.00', { status: 'draft' }),
      product(8635, '36.00', { status: 'private' }),
      product(8636, '64.00'),
    ];
    await withEnv(CATALOGUE, async () => {
      const res = await get(server, '/api/prices');
      assert.equal(res.status, 200);
      assert.equal(res.body.products['8634'].available, false);
      assert.equal('price' in res.body.products['8634'], false);
      assert.equal(res.body.products['8635'].available, false);
      assert.equal('price' in res.body.products['8635'], false);
      assert.equal(res.body.products['8636'].price, '64.00');
      assert.equal(res.body.products['8636'].available, true);
      assert.equal(JSON.stringify(res.body).includes('50.00'), false);
      assert.equal(JSON.stringify(res.body).includes('36.00'), false);
    });
  });

  test('an out of stock product is unavailable and has no price', async () => {
    state.products = [
      product(8634, '50.00', { stock_status: 'outofstock' }),
      product(8635, '36.00', { stock_status: 'instock' }),
      product(8636, '64.00', { stock_status: 'outofstock' }),
    ];
    await withEnv(CATALOGUE, async () => {
      const res = await get(server, '/api/prices');
      assert.equal(res.status, 200);
      assert.equal(res.body.products['8634'].available, false);
      assert.equal('price' in res.body.products['8634'], false);
      assert.equal(res.body.products['8635'].price, '36.00');
      assert.equal(res.body.products['8635'].available, true);
      assert.equal(res.body.products['8636'].available, false);
      assert.equal('price' in res.body.products['8636'], false);
      assert.equal(JSON.stringify(res.body).includes('50.00'), false);
      assert.equal(JSON.stringify(res.body).includes('64.00'), false);
    });
  });

  test('inclusive tax charges the stored price and exclusive tax adds base VAT', async () => {
    state.general = [
      { id: 'woocommerce_currency', value: 'GBP' },
      { id: 'woocommerce_default_country', value: 'GB:LND' },
      { id: 'woocommerce_calc_taxes', value: 'yes' },
      { id: 'woocommerce_store_postcode', value: 'SW1A 1AA' },
    ];
    state.taxSettings = vatSettings(true);
    await withEnv(CATALOGUE, async () => {
      const included = await get(server, '/api/prices');
      assert.equal(included.status, 200);
      assert.equal(included.body.products['8634'].price, '50.00');
      assert.equal(included.body.products['8635'].price, '36.00');
      assert.equal(included.body.products['8636'].price, '64.00');
    });

    prices.resetForTests();
    prices.setNowForTests(() => now);
    state.taxSettings = vatSettings(false);
    state.products = [
      product(8634, '50.00'),
      product(8635, '36.00'),
      product(8636, '64.00', { tax_status: 'none' }),
    ];
    await withEnv(CATALOGUE, async () => {
      const excluded = await get(server, '/api/prices');
      assert.equal(excluded.status, 200);
      assert.equal(excluded.body.products['8634'].price, '60.00');
      assert.equal(excluded.body.products['8635'].price, '43.20');
      assert.equal(excluded.body.products['8636'].price, '64.00');
      assert.equal(calls.some(call => call.href.includes('/taxes?')), true);
      assert.equal(calls.every(call => call.method === 'GET'), true);
    });
  });
});
