'use strict';

const cors = require('cors');

/**
 * Allowlist entries are origins. A trailing slash is stripped so
 * FRONTEND_URL=https://creaturecycles.co.uk/ still matches the browser
 * Origin header. The header itself is compared exactly: a longer host
 * such as https://creaturecycles.co.uk.evil.com does not match.
 */
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

function originAllowed(origin, allowlist) {
  if (!origin) return true;
  return allowlist.includes(origin);
}

function createCorsMiddleware(allowlist) {
  const allowed = allowlistFrom(allowlist);
  return cors({
    origin: (origin, cb) => {
      if (originAllowed(origin, allowed)) return cb(null, true);
      cb(new Error(`CORS: origin ${origin} not allowed`));
    },
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'X-WC-Webhook-Signature', 'Authorization'],
  });
}

module.exports = {
  normaliseOrigin,
  allowlistFrom,
  originAllowed,
  createCorsMiddleware,
};
