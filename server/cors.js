'use strict';

const cors = require('cors');

/**
 * Allowlist entries are origins. A trailing slash is stripped and the
 * scheme and host are lowercased, so FRONTEND_URL=HTTPS://CreatureCycles.co.uk/
 * still matches the browser Origin header. A longer host such as
 * https://creaturecycles.co.uk.evil.com does not match.
 *
 * Local origins are included only when NODE_ENV is development or dev.
 * Production (NODE_ENV=production) does not allow them.
 *
 * A same-origin request is not a CORS request. It is let through with no
 * Access-Control-Allow-Origin header when Origin equals either:
 *   - the origin of this request (req.protocol + the Host header).
 *     req.protocol honours X-Forwarded-Proto only when Express trust proxy
 *     is enabled. This app does not set trust proxy, so a client-supplied
 *     X-Forwarded-Proto is ignored. X-Forwarded-Host is never read.
 *   - BASE_URL, the public URL of this API. Behind a TLS terminator the
 *     browser Origin is https while req.protocol stays http, so /admin
 *     matches the configured public origin.
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

function requestOrigin(req) {
  const host = headerValue(typeof req.get === 'function' ? req.get('host') : req.headers.host).trim();
  if (!host) return '';
  const proto = String(req.protocol || 'http').trim().toLowerCase();
  return normaliseOrigin(`${proto}://${host}`);
}

function isSameOrigin(req) {
  const origin = normaliseOrigin(headerValue(req.headers.origin));
  if (!origin || origin === 'null') return false;
  const own = requestOrigin(req);
  if (own && origin === own) return true;
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
    origin: true,
    methods: ['GET', 'POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'X-WC-Webhook-Signature', 'Authorization'],
  });

  return function corsGate(req, res, next) {
    // A shared cache must not reuse a no-ACAO response for another Origin.
    res.vary('Origin');
    // Same-origin writes (the /admin page) carry an Origin header but are
    // not cross-origin. Pass them through with no allow-origin header.
    if (isSameOrigin(req)) return next();
    if (originAllowed(req.headers.origin, allowed)) return reflect(req, res, next);
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
  requestOrigin,
  isSameOrigin,
  createCorsMiddleware,
};
