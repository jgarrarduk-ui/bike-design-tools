'use strict';

/**
 * Email service — all transactional emails sent by Creature Cycles.
 *
 * Sends with the Resend HTTPS API (POST https://api.resend.com/emails).
 * There is no SMTP client: Railway blocks outbound SMTP.
 *
 * The Bearer token is SMTP_PASS when that is set (the existing Railway
 * secret — it already holds the Resend API key). RESEND_API_KEY is used
 * only when SMTP_PASS is empty. SMTP_HOST, SMTP_PORT, SMTP_USER, and
 * SMTP_SECURE are not read. Mail is skipped until a key is present.
 *
 * Emails in the order lifecycle:
 *   1. sendOrderConfirmation   — immediately after design saved (pre-payment): edit + checkout links
 *   2. sendPaymentConfirmation — after payment. Lead time is designFileDeliverySentence()
 *   3. sendDesignReview        — admin-triggered: sends review files + Accept button to customer
 *   4. sendDesignAccepted      — auto-triggered when customer accepts: sends final download link
 */

const RESEND_EMAILS_URL = 'https://api.resend.com/emails';
const RESEND_TIMEOUT_MS = 8000;

function trimmedEnv(name) {
  const value = process.env[name];
  if (typeof value !== 'string') return '';
  return value.trim();
}

function resendApiKey() {
  // SMTP_PASS wins so the Railway secret Layout already set stays the key.
  return trimmedEnv('SMTP_PASS') || trimmedEnv('RESEND_API_KEY');
}

function isConfigured() {
  return resendApiKey() !== '';
}

async function postResend({ to, subject, text, html }) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), RESEND_TIMEOUT_MS);
  // The HTTP server keeps the process alive in production, so this still
  // fires. unref means a background send cannot hold the process open in tests.
  if (typeof timer.unref === 'function') timer.unref();

  try {
    const res = await fetch(RESEND_EMAILS_URL, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${resendApiKey()}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        from: FROM(),
        to: [to],
        subject,
        text,
        html,
        reply_to: REPLY(),
      }),
      signal: controller.signal,
    });

    if (!res.ok) {
      let detail = '';
      try { detail = await res.text(); } catch { /* ignore a closed body */ }
      const snippet = detail.replace(/\s+/g, ' ').trim().slice(0, 300);
      throw new Error(`Resend API ${res.status}${snippet ? `: ${snippet}` : ''}`);
    }

    await res.arrayBuffer().catch(() => {});
  } finally {
    clearTimeout(timer);
  }
}

const FROM    = () => process.env.EMAIL_FROM     || '"Creature Cycles" <info@creaturecycles.co.uk>';
const REPLY   = () => process.env.EMAIL_REPLY_TO || 'info@creaturecycles.co.uk';

// One phrase for the save-design email and the payment email.
// WordPress has the same words on filter creature_fd_lead_time. This process
// cannot read that constant, so Layout keeps FD_LEAD_TIME in step by hand.
// REVIEW_LEAD_TIME_DAYS is the old day count ("7"). It is used only when
// FD_LEAD_TIME is unset, and it is always spoken as working days.
const DEFAULT_FD_LEAD_TIME = '5 working days';

function cleanLeadPhrase(value) {
  const cleaned = String(value || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
  if (!cleaned || cleaned.length > 80) return '';
  return cleaned;
}

function phraseFromLegacyDays(raw) {
  const value = cleanLeadPhrase(raw);
  if (!value) return '';
  if (/working\s+days$/i.test(value)) return value;
  const count = value.replace(/\s+days?$/i, '').trim();
  if (/^\d+$/.test(count)) return `${count} working days`;
  return value;
}

function designFileLeadTime() {
  const configured = cleanLeadPhrase(trimmedEnv('FD_LEAD_TIME'));
  if (configured) return configured;
  const legacy = phraseFromLegacyDays(trimmedEnv('REVIEW_LEAD_TIME_DAYS'));
  if (legacy) return legacy;
  return DEFAULT_FD_LEAD_TIME;
}

function designFileDeliverySentence() {
  return `Design files are delivered within ${designFileLeadTime()} of payment.`;
}

// Absolute URL: email clients do not load relative images.
// Source art is 480×80; 220×37 keeps that ratio.
const EMAIL_LOGO_URL = 'https://creaturecycles.co.uk/wp-content/uploads/2026/10/creature-logo-email-1.png';

function emailLogoHtml() {
  return `<img src="${EMAIL_LOGO_URL}" width="220" height="37" alt="Creature Cycles" style="display:block;width:220px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;margin:0 0 4px;">`;
}

// ── Shared HTML wrapper ───────────────────────────────────────────────────────
function wrapHtml(bodyContent) {
  return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Creature Cycles</title></head>
<body style="font-family:monospace;background:#f4f4f4;padding:40px 0;">
  <table width="600" align="center" style="background:#fff;border-radius:8px;padding:40px;border:1px solid #ddd;">
    <tr><td>
      ${emailLogoHtml()}
      <p style="color:#666;font-size:13px;margin-top:0;">Bespoke Frame Design Files</p>
      <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
      ${bodyContent}
      <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
      <p style="font-size:12px;color:#aaa;">
        Questions? Reply to this email or contact
        <a href="mailto:${REPLY()}" style="color:#888;">${REPLY()}</a>
      </p>
    </td></tr>
  </table>
</body>
</html>`;
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[ch]));
}

const SAVED_SUBJECT = 'Your Creature Cycles design is saved';
const SAVED_PREHEADER = 'Open it again to edit, or continue to checkout when you\u2019re ready.';

function firstNameFrom(customerName) {
  const first = String(customerName || '').trim().split(/\s+/)[0] || '';
  return first;
}

function savedDesignSentence(designName) {
  const name = String(designName || '').trim();
  if (!name) return 'Your frame design is saved with Creature Cycles.';
  return `Your frame design ${name} is saved with Creature Cycles.`;
}

/**
 * Save-design email. Prose is Cadence's draft, approved by James.
 * editUrl reopens Frame Designer. checkoutUrl is the tools-api redirect.
 * No prices. Dropouts stay "coming soon" until 8636 is buyable.
 * An empty first name is "Hi," with no space before the comma.
 */
function designSavedMessage({ customerName, designName, editUrl, checkoutUrl }) {
  const firstName = firstNameFrom(customerName);
  const greeting = firstName ? `Hi ${firstName},` : 'Hi,';
  const savedLine = savedDesignSentence(designName);
  const safeGreeting = escapeHtml(greeting);
  const safeSaved = String(designName || '').trim()
    ? `Your frame design <strong>${escapeHtml(String(designName).trim())}</strong> is saved with Creature Cycles.`
    : escapeHtml(savedLine);
  const safeEdit = escapeHtml(editUrl || '');
  const safeCheckout = escapeHtml(checkoutUrl || '');
  const deliveryLine = designFileDeliverySentence();
  const safeDelivery = escapeHtml(deliveryLine);

  const text = `${greeting}

${savedLine}

You can come back to it whenever you like \u2014 the geometry, parts selection and drawings stay with this design. No rush.

Edit or revisit your design:
${editUrl}

Ready to order design files? Continue to checkout for the parts that are live on the shop (chainstay\u2013BB yoke and seatstay yoke today). Dropouts are coming soon.
${checkoutUrl}

${deliveryLine}

Questions? Reply to this email or use the contact form on creaturecycles.co.uk \u2014 we\u2019re in Corris, Mid Wales.

Thanks,
Creature Cycles
info@creaturecycles.co.uk`;

  const html = `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>${escapeHtml(SAVED_SUBJECT)}</title></head>
<body style="font-family:monospace;background:#f4f4f4;padding:40px 0;margin:0;">
  <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">${escapeHtml(SAVED_PREHEADER)}</div>
  <table width="600" align="center" style="background:#fff;border-radius:8px;padding:40px;border:1px solid #ddd;">
    <tr><td>
      ${emailLogoHtml()}
      <p style="color:#666;font-size:13px;margin-top:0;">Bespoke Frame Design Files</p>
      <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
      <p style="font-size:15px;color:#222;">${safeGreeting}</p>
      <p style="font-size:15px;color:#222;line-height:1.6;">${safeSaved}</p>
      <p style="font-size:15px;color:#222;line-height:1.6;">
        You can come back to it whenever you like \u2014 the geometry, parts selection and drawings stay with this design. No rush.
      </p>
      <p style="font-size:15px;color:#222;line-height:1.6;">
        <strong>Edit or revisit your design</strong><br>
        Open the Frame Designer with this design loaded and keep refining angles, lengths or which parts you want.
      </p>
      <div style="text-align:center;margin:24px 0;">
        <a href="${safeEdit}"
           style="display:inline-block;background:#fff;color:#111;text-decoration:none;
                  padding:12px 28px;border-radius:4px;font-family:monospace;font-size:14px;
                  font-weight:bold;border:2px solid #111;">
          Edit design
        </a>
      </div>
      <p style="font-size:15px;color:#222;line-height:1.6;">
        <strong>Ready to order design files?</strong><br>
        Continue to checkout for the parts that are live on the shop (chainstay\u2013BB yoke and seatstay yoke today). Dropouts are coming soon \u2014 they\u2019ll join the same flow when they\u2019re ready.
      </p>
      <div style="text-align:center;margin:24px 0;">
        <a href="${safeCheckout}"
           style="display:inline-block;background:#111;color:#fff;text-decoration:none;
                  padding:14px 32px;border-radius:4px;font-family:monospace;font-size:15px;
                  font-weight:bold;">
          Take me to checkout
        </a>
      </div>
      <p style="font-size:15px;color:#222;line-height:1.6;">${safeDelivery}</p>
      <p style="font-size:13px;color:#666;line-height:1.6;">
        If a button doesn\u2019t work, copy this link into your browser:<br>
        Edit: <a href="${safeEdit}" style="color:#333;">${safeEdit}</a><br>
        Checkout: <a href="${safeCheckout}" style="color:#333;">${safeCheckout}</a>
      </p>
      <p style="font-size:14px;color:#555;line-height:1.6;">
        Questions? Reply to this email or use the contact form on creaturecycles.co.uk \u2014 we\u2019re in Corris, Mid Wales.
      </p>
      <p style="font-size:14px;color:#222;line-height:1.6;">
        Thanks,<br>
        Creature Cycles<br>
        <a href="mailto:info@creaturecycles.co.uk" style="color:#333;">info@creaturecycles.co.uk</a>
      </p>
    </td></tr>
  </table>
</body>
</html>`;

  return { subject: SAVED_SUBJECT, preheader: SAVED_PREHEADER, text, html };
}

// ── 1. Design saved (pre-payment) — edit link and checkout link ──────────────
async function sendOrderConfirmation({ to, customerName, designName, designId, editUrl, checkoutUrl }) {
  if (!isConfigured()) {
    console.warn('[email] Resend API key not set (SMTP_PASS or RESEND_API_KEY) — skipping design saved email to', to);
    return;
  }

  const message = designSavedMessage({ customerName, designName, designId, editUrl, checkoutUrl });

  await postResend({
    to,
    subject: message.subject,
    text:    message.text,
    html:    message.html,
  });

  console.log(`[email] Sent design saved email to ${to} for design ${designId}`);
}

// ── 2. Payment confirmation (post-payment, design under review) ───────────────
async function sendPaymentConfirmation({ to, customerName, designId }) {
  if (!isConfigured()) {
    console.warn('[email] Resend API key not set (SMTP_PASS or RESEND_API_KEY) — skipping payment confirmation to', to);
    return;
  }

  const firstName  = customerName.split(' ')[0] || 'there';
  const deliveryLine = designFileDeliverySentence();
  const safeDelivery = escapeHtml(deliveryLine);
  const shortId    = designId.slice(0, 8).toUpperCase();

  const html = wrapHtml(`
    <p style="font-size:15px;color:#222;">Hi ${firstName},</p>
    <p style="font-size:15px;color:#222;line-height:1.6;">
      Thank you for your order! Payment has been confirmed and your bespoke bike design
      is now in our review queue.
    </p>
    <div style="background:#f9f9f9;border-left:3px solid #111;padding:16px 20px;margin:24px 0;">
      <p style="margin:0;font-size:14px;color:#333;line-height:1.8;">
        <strong>Design ID:</strong> ${shortId}<br>
        <strong>What happens next:</strong> Our designer will review your specification
        and produce your design files.<br>
        <strong>Lead time:</strong> ${safeDelivery}
      </p>
    </div>
    <p style="font-size:14px;color:#555;line-height:1.6;">
      Once your design is ready, you'll receive another email with your design files
      to review. You'll have the opportunity to request changes before we finalise
      everything.
    </p>
    <p style="font-size:14px;color:#555;">
      If you have any questions in the meantime, just reply to this email.
    </p>
  `);

  const text = `Hi ${firstName},

Thank you for your order! Payment confirmed — your design is now in our review queue.

Design ID: ${shortId}

What happens next:
  Our designer will review your specification and produce your design files.
  Lead time: ${deliveryLine}

Once your design is ready you'll receive an email with the files to review.
You'll have the opportunity to request changes before we finalise everything.

Questions? Just reply to this email.

– Creature Cycles`;

  await postResend({
    to,
    subject: `Creature Cycles — Design #${shortId} is under review`,
    text,
    html,
  });

  console.log(`[email] Sent payment confirmation to ${to} for design ${designId}`);
}

// ── 3. Design review (admin-triggered, customer reviews + can accept) ─────────
async function sendDesignReview({ to, customerName, designId, previewUrl, acceptUrl }) {
  if (!isConfigured()) {
    console.warn('[email] Resend API key not set (SMTP_PASS or RESEND_API_KEY) — skipping review email to', to);
    console.info('[email] Accept URL would have been:', acceptUrl);
    return;
  }

  const firstName = customerName.split(' ')[0] || 'there';
  const shortId   = designId.slice(0, 8).toUpperCase();

  const html = wrapHtml(`
    <p style="font-size:15px;color:#222;">Hi ${firstName},</p>
    <p style="font-size:15px;color:#222;line-height:1.6;">
      Your bespoke bike design is ready for review! Please take a look at the files
      below and let us know if you're happy to proceed or if you'd like any changes.
    </p>

    <div style="text-align:center;margin:32px 0;">
      <a href="${previewUrl}"
         style="display:inline-block;background:#fff;color:#111;text-decoration:none;
                padding:12px 28px;border-radius:4px;font-family:monospace;font-size:14px;
                font-weight:bold;border:2px solid #111;margin:0 8px 12px;">
        Download Review Files
      </a>
      <a href="${acceptUrl}"
         style="display:inline-block;background:#111;color:#fff;text-decoration:none;
                padding:14px 32px;border-radius:4px;font-family:monospace;font-size:15px;
                font-weight:bold;margin:0 8px 12px;">
        Accept Design &amp; Get Final Files
      </a>
    </div>

    <p style="font-size:13px;color:#666;line-height:1.6;">
      <strong>Happy with the design?</strong> Click <em>Accept Design</em> and your
      final files will be emailed to you automatically.
    </p>
    <p style="font-size:13px;color:#666;line-height:1.6;">
      <strong>Want changes?</strong> Simply reply to this email describing what you'd
      like adjusted and we'll revise and send a new review.
    </p>
    <p style="font-size:12px;color:#aaa;">Design ID: <code>${shortId}</code></p>
  `);

  const text = `Hi ${firstName},

Your bespoke bike design is ready for review!

Review your design files:
  ${previewUrl}

Happy with everything? Accept the design here:
  ${acceptUrl}

Clicking the accept link will automatically send your final files.

Want changes? Just reply to this email with what you'd like adjusted.

Design ID: ${shortId}

– Creature Cycles`;

  await postResend({
    to,
    subject: `Creature Cycles — Your design is ready to review (#${shortId})`,
    text,
    html,
  });

  console.log(`[email] Sent design review to ${to} for design ${designId}`);
}

// ── 4. Design accepted — final download email ─────────────────────────────────
async function sendDesignAccepted({ to, customerName, designId, downloadUrl, expiresAt }) {
  if (!isConfigured()) {
    console.warn('[email] Resend API key not set (SMTP_PASS or RESEND_API_KEY) — skipping accepted email to', to);
    console.info('[email] Download URL would have been:', downloadUrl);
    return;
  }

  const firstName = customerName.split(' ')[0] || 'there';
  const shortId   = designId.slice(0, 8).toUpperCase();

  const html = wrapHtml(`
    <p style="font-size:15px;color:#222;">Hi ${firstName},</p>
    <p style="font-size:15px;color:#222;line-height:1.6;">
      Thank you for accepting your design! Your final bespoke bike design files are
      ready to download. The package includes:
    </p>
    <ul style="font-size:14px;color:#444;line-height:2;">
      <li><strong>design.json</strong> — Full parameter set (all geometry values)</li>
      <li><strong>design.pdf</strong> — 2D frame drawing with annotations</li>
    </ul>

    <div style="text-align:center;margin:32px 0;">
      <a href="${downloadUrl}"
         style="background:#111;color:#fff;text-decoration:none;padding:14px 32px;
                border-radius:4px;font-family:monospace;font-size:15px;font-weight:bold;">
        Download Final Design Files
      </a>
    </div>

    <p style="font-size:13px;color:#888;">
      This link expires in ${expiresAt}. Design ID: <code>${shortId}</code>
    </p>
    <p style="font-size:13px;color:#555;">
      Thank you for choosing Creature Cycles. We hope you love your new frame!
    </p>
  `);

  const text = `Hi ${firstName},

Thank you for accepting your design! Your final files are ready to download.

Download here: ${downloadUrl}

This link expires in ${expiresAt}.
Design ID: ${shortId}

Files included:
  - design.json  (full geometry parameters)
  - design.pdf   (2D frame drawing)

Thank you for choosing Creature Cycles!

– Creature Cycles`;

  await postResend({
    to,
    subject: `Creature Cycles — Your final design files (#${shortId})`,
    text,
    html,
  });

  console.log(`[email] Sent final files to ${to} for design ${designId}`);
}

module.exports = {
  isConfigured,
  designFileLeadTime,
  designFileDeliverySentence,
  designSavedMessage,
  sendOrderConfirmation,
  sendPaymentConfirmation,
  sendDesignReview,
  sendDesignAccepted,
};
