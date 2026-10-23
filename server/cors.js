'use strict';

const cors = require('cors');

/**
 * Allowlist entries are origins. A trailing slash is stripped so
 * FRONTEND_URL=https://creaturecycles.co.uk/ still matches the browser
 * Origin header. The header itself is compared exactly: a longer host
 * such as https://creaturecycles.co.uk.evil.com does not match.
 *
 * Local origins are included only when NODE_ENV is development or dev.
 * Production (NODE_ENV=production) does not allow them.
 */

const LOCAL_ORIGINS = [
  'http://localhost:3000',
  'http://localhost:5173',
  'http://127.0.0.1:5173',
];

function normaliseOrigin(value) {
  if (typeof value !== 'string') return '';
  return value.trim().replace(/\/+$/, '');
}

function allowlistFrom(values) {
  const out = [];
  for (const value of values || []) {
    const origin = normaliseOrigin(value);
    if (!origin || out.includes(origin)) continue;
    out.push(origin);
  }
  return out;
}

function isDevEnv(nodeEnv) {
  const raw = nodeEnv == null ? process.env.NODE_ENV : nodeEnv;
  const env = String(raw || '').trim().toLowerCase();
  return env === 'development' || env === 'dev';
}

function configuredOrigins(options) {
  const opts = options || {};
  const frontend = Object.prototype.hasOwnProperty.call(opts, 'frontendUrl')
    ? opts.frontendUrl
    : process.env.FRONTEND_URL;
  const values = [frontend];
  if (isDevEnv(Object.prototype.hasOwnProperty.call(opts, 'nodeEnv') ? opts.nodeEnv : undefined)) {
    values.push(...LOCAL_ORIGINS);
  }
  return allowlistFrom(values);
}

function originAllowed(origin, allowlist) {
  if (!origin) return true;
  return allowlist.includes(origin);
}

function createCorsMiddleware(allowlist) {
  const allowed = allowlistFrom(allowlist);
  const reflect = cors({
    origin: true,
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'X-WC-Webhook-Signature', 'Authorization'],
  });

  return function corsGate(req, res, next) {
    if (originAllowed(req.headers.origin, allowed)) return reflect(req, res, next);
    if (String(req.method || '').toUpperCase() === 'OPTIONS') {
      res.status(403).json({ error: 'origin_not_allowed' });
      return;
    }
    // Quiet deny: the browser blocks the read because there is no
    // Access-Control-Allow-Origin. curl and other non-browser clients
    // still receive the normal response.
    next();
  };
}

module.exports = {
  LOCAL_ORIGINS,
  normaliseOrigin,
  allowlistFrom,
  isDevEnv,
  configuredOrigins,
  originAllowed,
  createCorsMiddleware,
};
