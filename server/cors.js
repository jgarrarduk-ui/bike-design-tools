'use strict';

const cors = require('cors');

/**
 * Allowlist entries are origins. A trailing slash is stripped and the
 * scheme and host are lowercased, so FRONTEND_URL=HTTPS://CreatureCycles.co.uk/
 * still matches the browser Origin header. The reflected
 * Access-Control-Allow-Origin value is that normalised origin. A longer
 * host such as https://creaturecycles.co.uk.evil.com does not match.
 *
 * Local origins are included only when NODE_ENV is development or dev.
 * Production (NODE_ENV=production) does not allow them.
 *
 * The allowlist is checked first. After that, a request whose Origin
 * equals BASE_URL (the public URL of this API) is let through with no
 * Access-Control-Allow-Origin header. That is the /admin Send Review
 * Email post. If BASE_URL is unset, nothing is treated as same-origin.
 * The Host header and X-Forwarded-Proto are not consulted. If BASE_URL
 * is also on the allowlist, the allowlist wins and the origin is reflected.
 */

const LOCAL_ORIGINS = [
  'http://localhost:3000',
  'http://localhost:5173',
  'http://127.0.0.1:5173',
];

function normaliseOrigin(value) {
  if (typeof value !== 'string') return '';
  return value.trim().replace(/\/+$/, '').toLowerCase();
}

function headerValue(value) {
  if (Array.isArray(value)) return value[0] || '';
  return typeof value === 'string' ? value : '';
}

function publicBaseOrigin(baseUrl) {
  const raw = baseUrl === undefined ? process.env.BASE_URL : baseUrl;
  if (typeof raw !== 'string' || !raw.trim()) return '';
  try {
    return normaliseOrigin(new URL(raw.trim()).origin);
  } catch {
    return normaliseOrigin(raw);
  }
}

function isSameOrigin(req) {
  const origin = normaliseOrigin(headerValue(req.headers.origin));
  if (!origin || origin === 'null') return false;
  const base = publicBaseOrigin();
  return Boolean(base && origin === base);
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
  if (origin == null || origin === '') return true;
  return allowlist.includes(normaliseOrigin(origin));
}

function createCorsMiddleware(allowlist) {
  const allowed = allowlistFrom(allowlist);
  const reflect = cors({
    origin(origin, callback) {
      // Echo the canonical origin. Browsers already send a lowercase
      // scheme and host, so this still matches the request Origin.
      callback(null, normaliseOrigin(headerValue(origin)));
    },
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'X-WC-Webhook-Signature', 'Authorization'],
  });

  return function corsGate(req, res, next) {
    // A shared cache must not reuse a no-ACAO response for another Origin.
    res.vary('Origin');
    if (originAllowed(req.headers.origin, allowed)) return reflect(req, res, next);
    // /admin posts from the API origin. No allow-origin header: the page
    // is not cross-origin. Unset BASE_URL matches nothing.
    if (isSameOrigin(req)) return next();
    const method = String(req.method || '').toUpperCase();
    // Quiet deny for reads: the browser blocks the response because there
    // is no Access-Control-Allow-Origin. curl and other non-browser
    // clients still receive the normal response.
    if (method === 'GET' || method === 'HEAD') return next();
    // Writes and preflight from a refused origin stop here. No
    // Access-Control-Allow-Origin header is set.
    res.status(403).json({ error: 'origin_not_allowed' });
  };
}

module.exports = {
  LOCAL_ORIGINS,
  normaliseOrigin,
  allowlistFrom,
  isDevEnv,
  configuredOrigins,
  originAllowed,
  publicBaseOrigin,
  isSameOrigin,
  createCorsMiddleware,
};
