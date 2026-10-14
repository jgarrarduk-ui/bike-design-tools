'use strict';

/**
 * Log helpers. Customer addresses are masked before they reach stdout.
 * jane.doe@example.com becomes j***@e***.com.
 */

function maskEmail(email) {
  const raw = String(email || '').trim();
  const at = raw.lastIndexOf('@');
  if (at <= 0 || at === raw.length - 1) return '***';
  const local = raw.slice(0, at);
  const domain = raw.slice(at + 1);
  const dot = domain.indexOf('.');
  const host = dot === -1 ? domain : domain.slice(0, dot);
  const rest = dot === -1 ? '' : domain.slice(dot);
  const head = (part) => (part ? `${part[0]}***` : '***');
  return `${head(local)}@${head(host)}${rest}`;
}

function redactText(value) {
  return String(value == null ? '' : value).replace(
    /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g,
    (match) => maskEmail(match),
  );
}

module.exports = {
  maskEmail,
  redactText,
};
