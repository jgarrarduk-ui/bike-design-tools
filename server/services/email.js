'use strict';

/**
 * Email service — all transactional emails sent by Creature Cycles.
 *
 * Uses nodemailer. Save-design mail is Resend over SMTP (not the Resend SDK).
 * Mail is skipped until SMTP_PASS is set. That value is the Resend API key.
 *
 * Emails in the order lifecycle:
 *   1. sendOrderConfirmation   — immediately after design saved (pre-payment): edit + checkout links
 *   2. sendPaymentConfirmation — after payment, tells customer design is under review (~1 week)
 *   3. sendDesignReview        — admin-triggered: sends review files + Accept button to customer
 *   4. sendDesignAccepted      — auto-triggered when customer accepts: sends final download link
 */

const nodemailer = require('nodemailer');

function createTransport() {
  return nodemailer.createTransport({
    host:   process.env.SMTP_HOST,
    port:   Number(process.env.SMTP_PORT) || 587,
    secure: process.env.SMTP_SECURE === 'true',
    auth: {
      user: process.env.SMTP_USER,
      pass: process.env.SMTP_PASS,
    },
  });
}

function isConfigured() {
  // Host and user may be set before the secret exists. Do not send until
  // SMTP_PASS is present. For Resend that value is the API key.
  const pass = process.env.SMTP_PASS;
  return typeof pass === 'string' && pass.trim() !== '';
}

const FROM    = () => process.env.EMAIL_FROM     || '"Creature Cycles" <info@creaturecycles.co.uk>';
const REPLY   = () => process.env.EMAIL_REPLY_TO || 'info@creaturecycles.co.uk';
const LEAD    = () => process.env.REVIEW_LEAD_TIME_DAYS || '7';

// ── Shared HTML wrapper ───────────────────────────────────────────────────────
function wrapHtml(bodyContent) {
  return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Creature Cycles</title></head>
<body style="font-family:monospace;background:#f4f4f4;padding:40px 0;">
  <table width="600" align="center" style="background:#fff;border-radius:8px;padding:40px;border:1px solid #ddd;">
    <tr><td>
      <h1 style="font-family:monospace;color:#111;font-size:22px;margin-bottom:4px;">Creature Cycles</h1>
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

  const text = `${greeting}

${savedLine}

You can come back to it whenever you like \u2014 the geometry, parts selection and drawings stay with this design. No rush.

Edit or revisit your design:
${editUrl}

Ready to order design files? Continue to checkout for the parts that are live on the shop (chainstay\u2013BB yoke and seatstay yoke today). Dropouts are coming soon.
${checkoutUrl}

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
      <h1 style="font-family:monospace;color:#111;font-size:22px;margin-bottom:4px;">Creature Cycles</h1>
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
    console.warn('[email] SMTP_PASS not set — skipping design saved email to', to);
    return;
  }

  const transport = createTransport();
  const message = designSavedMessage({ customerName, designName, designId, editUrl, checkoutUrl });

  await transport.sendMail({
    from:    FROM(),
    replyTo: REPLY(),
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
    console.warn('[email] SMTP not configured — skipping payment confirmation to', to);
    return;
  }

  const firstName  = customerName.split(' ')[0] || 'there';
  const leadDays   = LEAD();
  const transport  = createTransport();
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
        <strong>Lead time:</strong> You can expect your design review within
        <strong>${leadDays} days</strong>.
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
  Lead time: expect your design review within ${leadDays} days.

Once your design is ready you'll receive an email with the files to review.
You'll have the opportunity to request changes before we finalise everything.

Questions? Just reply to this email.

– Creature Cycles`;

  await transport.sendMail({
    from:    FROM(),
    replyTo: REPLY(),
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
    console.warn('[email] SMTP not configured — skipping review email to', to);
    console.info('[email] Accept URL would have been:', acceptUrl);
    return;
  }

  const firstName = customerName.split(' ')[0] || 'there';
  const transport = createTransport();
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

  await transport.sendMail({
    from:    FROM(),
    replyTo: REPLY(),
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
    console.warn('[email] SMTP not configured — skipping accepted email to', to);
    console.info('[email] Download URL would have been:', downloadUrl);
    return;
  }

  const firstName = customerName.split(' ')[0] || 'there';
  const transport = createTransport();
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

  await transport.sendMail({
    from:    FROM(),
    replyTo: REPLY(),
    to,
    subject: `Creature Cycles — Your final design files (#${shortId})`,
    text,
    html,
  });

  console.log(`[email] Sent final files to ${to} for design ${designId}`);
}

module.exports = {
  isConfigured,
  designSavedMessage,
  sendOrderConfirmation,
  sendPaymentConfirmation,
  sendDesignReview,
  sendDesignAccepted,
};
