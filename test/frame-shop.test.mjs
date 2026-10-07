import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const FrameShop = require('../frame-shop.js');

const PARAMS = { reach: 450, chainstay_length: 430, ht_angle: 64.5 };

test('default selection is BB yoke and SS yoke', () => {
  assert.deepEqual(FrameShop.defaultProductIds(), [8634, 8635]);
  assert.equal(FrameShop.PARTS.find(part => part.id === 8636).unavailable, true);
  assert.equal(FrameShop.PARTS.some(part => part.id === 8637), false);
});

test('dropouts and unknown ids are never sent', () => {
  assert.deepEqual(FrameShop.productIdsFromSelection([8636, 8634, 9999, 8634]), [8634]);
  assert.deepEqual(FrameShop.productIdsFromSelection([8634, 8635, 8636]), [8634, 8635]);
  assert.deepEqual(FrameShop.productIdsFromSelection([8636]), []);
  assert.deepEqual(FrameShop.productIdsFromSelection([8637]), []);
  assert.deepEqual(FrameShop.productIdsFromSelection([]), []);
});

test('checkout shows BB and SS list prices and hides the full-set total', () => {
  const byId = Object.fromEntries(FrameShop.PARTS.map(part => [part.id, part]));
  assert.equal(byId[8634].price, 58);
  assert.equal(byId[8635].price, 42);
  assert.equal(byId[8636].price, 64);
  assert.equal(FrameShop.partLabel(byId[8634]), 'BB yoke £58');
  assert.equal(FrameShop.partLabel(byId[8635]), 'SS yoke £42');
  assert.equal(FrameShop.partLabel(byId[8636]), 'Dropouts £64');
  assert.equal(FrameShop.selectionTotal([8634, 8635]), 100);
  assert.equal(FrameShop.selectionSaving([8634, 8635]), '');
  assert.equal(FrameShop.selectionLabel([8634, 8635]), 'Selected design files £100');
  assert.equal(FrameShop.selectionTotal([8634, 8635, 8636]), 100);
  assert.equal(FrameShop.selectionSaving([8634, 8635, 8636]), '');
  assert.equal(FrameShop.selectionLabel([8634, 8635, 8636]), 'Selected design files £100');
  assert.equal(FrameShop.selectionTotal([8634]), 58);
  assert.equal(FrameShop.selectionLabel([8635]), 'SS yoke £42');
  assert.equal(FrameShop.selectionLabel([8636]), 'Choose at least one design file.');
  const shown = [
    FrameShop.selectionLabel([8634, 8635]),
    FrameShop.selectionLabel([8634, 8635, 8636]),
    FrameShop.selectionSaving([8634, 8635, 8636]),
    FrameShop.partLabel(byId[8634]),
    FrameShop.partLabel(byId[8635]),
  ];
  for (const label of shown) {
    assert.equal(/128|164|£36|save £|136|£24|three design files/i.test(label), false);
  }
});

test('Frame Designer shop copy keeps dropouts visible and not for sale', () => {
  const html = readFileSync(new URL('../frame-designer.html', import.meta.url), 'utf8');
  const shop = readFileSync(new URL('../frame-shop.js', import.meta.url), 'utf8');
  const readme = readFileSync(new URL('../README.md', import.meta.url), 'utf8');
  const customer = html + '\n' + readme;
  assert.equal(/£136|−£24|-£24|save when all three|Whole rear end £|Whole rear end is optional|save £36|£128/i.test(customer), false);
  assert.equal(/8637/.test(shop), false);
  assert.match(html, /Coming soon/);
  assert.match(html, /is-unavailable/);
  assert.match(html, /input\.disabled = unavailable/);
  assert.match(readme, /Coming soon/);
  assert.match(readme, /£100/);
  assert.match(readme, /never includes 8636/);
  assert.match(html, /Printed 316L is enquire only/);
});

test('design payload is the Phase 2 tools-api body', () => {
  assert.deepEqual(FrameShop.buildDesignPayload({
    customerName: '  Ada Lovelace ',
    customerEmail: ' ada@example.com ',
    params: PARAMS,
    productIds: [8635, 8634],
  }), {
    customerName: 'Ada Lovelace',
    customerEmail: 'ada@example.com',
    params: PARAMS,
    productIds: [8635, 8634],
  });
  assert.deepEqual(FrameShop.buildDesignPayload({
    customerName: 'Ada',
    customerEmail: 'ada@example.com',
    params: PARAMS,
    productIds: [8636, 8634, 8635, 8636],
  }).productIds, [8634, 8635]);
  assert.throws(
    () => FrameShop.buildDesignPayload({
      customerName: 'Ada',
      customerEmail: 'ada@example.com',
      params: PARAMS,
      productIds: [9999],
    }),
    /at least one design file/,
  );
});

test('design id is read from design_id or designId', () => {
  assert.equal(FrameShop.designIdFromResponse({ design_id: 'abc' }), 'abc');
  assert.equal(FrameShop.designIdFromResponse({ designId: 'def' }), 'def');
  assert.equal(FrameShop.designIdFromResponse({ design_id: 'abc', designId: 'def' }), 'abc');
  assert.equal(FrameShop.designIdFromResponse({}), '');
});

test('checkout redirects only to the store order-pay URL or a local placeholder', () => {
  assert.equal(FrameShop.isOrderPayUrl(
    'https://creaturecycles.co.uk/checkout/order-pay/5150/?pay_for_order=true&key=wc_order_5150',
  ), true);
  assert.equal(FrameShop.isOrderPayUrl(
    'https://www.creaturecycles.co.uk/checkout/order-pay/9/',
  ), true);
  assert.equal(FrameShop.isOrderPayUrl(
    'http://127.0.0.1:3001/checkout/order-pay/9/?key=k',
  ), true);
  assert.equal(FrameShop.isOrderPayUrl(
    'http://localhost:3001/order-confirmation?design=abc&status=pending',
  ), true);
  assert.equal(FrameShop.isOrderPayUrl('https://evil.example/checkout/order-pay/1/'), false);
  assert.equal(FrameShop.isOrderPayUrl('javascript:alert(1)'), false);
  assert.equal(FrameShop.isOrderPayUrl('https://creaturecycles.co.uk/cart/'), false);
});

test('an unchanged save is reused; a geometry change posts again', () => {
  const ids = [8634, 8635];
  const snapshot = FrameShop.selectionSnapshot(PARAMS, [8636, 8634, 8635], 'Ada@Example.com');
  const saved = {
    designId: 'design-1',
    checkoutUrl: 'https://creaturecycles.co.uk/checkout/order-pay/10/?key=k',
    snapshot,
  };
  assert.deepEqual(FrameShop.checkoutPlan({
    saved,
    snapshot: FrameShop.selectionSnapshot(PARAMS, ids, 'ada@example.com'),
    productIds: ids,
  }), {
    action: 'redirect',
    designId: 'design-1',
    checkoutUrl: saved.checkoutUrl,
    productIds: ids,
  });
  const changed = FrameShop.checkoutPlan({
    saved,
    snapshot: FrameShop.selectionSnapshot({ ...PARAMS, reach: 460 }, ids, 'ada@example.com'),
    productIds: ids,
  });
  assert.equal(changed.action, 'post');
  assert.deepEqual(changed.productIds, ids);
  assert.equal(FrameShop.checkoutPlan({ saved: null, snapshot, productIds: [] }).action, 'error');
});

test('session design id must match ?design= when the URL carries one', () => {
  const storage = new Map();
  const sessionStorage = {
    getItem: key => (storage.has(key) ? storage.get(key) : null),
    setItem: (key, value) => storage.set(key, value),
  };
  FrameShop.writeSession(sessionStorage, { designId: 'design-1', checkoutUrl: 'https://creaturecycles.co.uk/checkout/order-pay/1/' });
  assert.equal(FrameShop.readSession(sessionStorage, null).designId, 'design-1');
  assert.equal(FrameShop.readSession(sessionStorage, 'design-1').designId, 'design-1');
  assert.equal(FrameShop.readSession(sessionStorage, 'other'), null);
  assert.equal(storage.get(FrameShop.SESSION_KEY).includes('design-1'), true);
});

test('POST /api/designs uses the configured origin and surfaces API errors', async () => {
  assert.equal(
    FrameShop.apiUrl('https://creature-tools-api-production.up.railway.app/'),
    'https://creature-tools-api-production.up.railway.app/api/designs',
  );
  assert.throws(() => FrameShop.apiUrl(''), /not configured/);

  let seen;
  const ok = await FrameShop.postDesign(
    'https://creature-tools-api-production.up.railway.app',
    { productIds: [8634] },
    async (url, opts) => {
      seen = { url, opts };
      return {
        ok: true,
        status: 200,
        json: async () => ({ designId: 'design-9', checkoutUrl: 'https://creaturecycles.co.uk/checkout/order-pay/9/' }),
      };
    },
  );
  assert.equal(seen.url, 'https://creature-tools-api-production.up.railway.app/api/designs');
  assert.equal(seen.opts.method, 'POST');
  assert.deepEqual(JSON.parse(seen.opts.body), { productIds: [8634] });
  assert.equal(ok.designId, 'design-9');

  await assert.rejects(
    () => FrameShop.postDesign('https://example.test', {}, async () => ({
      ok: false,
      status: 400,
      json: async () => ({ error: 'Product 1 is not available.' }),
    })),
    /Product 1 is not available/,
  );

  await assert.rejects(
    () => FrameShop.postDesign('https://example.test', {}, async () => { throw new Error('offline'); }),
    /Could not reach the tools API/,
  );
});
