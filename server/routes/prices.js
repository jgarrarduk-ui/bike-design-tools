'use strict';

/**
 * GET /api/prices
 *
 * Public catalogue read for Frame Designer. No WooCommerce writes.
 *
 * tools-api does not rate-limit its other public routes (health, designs,
 * download, review). Design-change 429s are the shop's, not this process.
 * This route follows that: no extra limiter.
 *
 * CORS is the app-wide policy in index.js (FRONTEND_URL and local dev origins).
 */

const express = require('express');
const prices = require('../services/prices');

const router = express.Router();

router.get('/', async (_req, res) => {
  const result = await prices.getPrices();
  res.set('Cache-Control', result.status === 200 ? 'public, max-age=60' : 'no-store');
  res.status(result.status).json(result.body);
});

module.exports = router;
