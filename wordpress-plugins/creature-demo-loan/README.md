# De-Reacher demo loan

Postal demo loan for the De-Reacher. The rider pays £25 at checkout, the card is saved with the WooCommerce Stripe Gateway, and later charges (the one bill for late fees, damage and missing parts, keep, deemed purchase) go through a payment link first. The saved card is charged only after the notice window, and only in Stripe test mode unless live charges are turned on in `wp-config.php`.

This plugin follows the published demo terms, version 5 (dated 1 November 2026).

WooCommerce and WooCommerce Stripe Gateway 11.x must already be active. This is a normal plugin. It is not a must-use file.

## Install on staging

From this directory:

```bash
sh wordpress-plugins/creature-demo-loan/build-zip.sh
```

That writes `wordpress-plugins/creature-demo-loan.zip` (gitignored). In wp-admin go to **Plugins → Add New → Upload Plugin**, upload that zip, and activate **Creature Cycles De-Reacher Demo Loan**.

Optional: copy `optional-mu-loader/creature-demo-loan-loader.php` (the same file as `wordpress-plugins/mu-plugins/creature-demo-loan-loader.php`) into `wp-content/mu-plugins/` on its own, not into a subfolder. The loader includes the plugin from `wp-content/plugins/creature-demo-loan/` when you do not want an activate click. If both the loader and an active plugin are present, the plugin loads once.

Do not install this on the live shop until James has turned Stripe test mode off on purpose. Live charges stay off unless `wp-config.php` defines `CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES` as boolean `true` before plugins load. Customer mail stays redirected to `demo-loan-test@creaturecycles.co.uk` with a `[TEST]` subject prefix unless `CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL` is boolean `true`.

### The 22:00 job needs a real cron on live

The plugin runs one daily job at 22:00 site time (Europe/London). It sends the working-day-4 alert and, on working day 5, the late-fee-only bill (terms 4.4 and 6.4(b)). WP-Cron and the Action Scheduler runner only start when someone loads a page. On a quiet site the 22:00 job can run late, or not at all that day. Before going live, do one of these:

1. Set `define( 'DISABLE_WP_CRON', true );` in `wp-config.php` and add a server cron that calls `wp-cron.php` every 5 to 15 minutes, for example `*/10 * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron > /dev/null` (use the real site address), or `wp cron event run --due-now`.
2. Or run the Action Scheduler runner from the server (`wp action-scheduler run`) on the same schedule.

**Detection.** Each run works out which London date it is for: a run that starts at or after 22:00 is for that day, an earlier run is for the day before. The plugin stores the last date covered (option `creature_demo_loan_daily_covered`). If a run starts after midnight London on the following day, or a scheduled day had no run, it runs every missed date in order with that date as the working day (fallback decisions are made on working-day dates, not on the time the run started), sets a late-run flag (option `creature_demo_loan_late_run`), emails the shop once for that occurrence (`admin_daily_job_late`) and shows an admin notice for 7 days. It catches up at most 14 days. It can only notice a missed run once some later run happens: if the job never runs again, nothing is flagged. It cannot undo a bill or alert that went out late, and a bill raised as of the working day but sent after midnight is still a late email in the rider's inbox.

## What Layout still sets

The demo product defaults to **8946** when the constant, the filter and the config setting are all empty. To use other ids:

```php
add_filter( 'creature_demo_loan_product_ids', function () {
    return array( 1234 );
} );
```

Or define `CREATURE_DEMO_LOAN_PRODUCT_IDS` as a comma-separated list in `wp-config.php` before plugins load. An empty constant is ignored. Staging has no `wp-config.php` access, so the companion plugin can set the same filter from wp-admin:

```bash
sh wordpress-plugins/creature-demo-loan-config/build-zip.sh
```

That writes `wordpress-plugins/creature-demo-loan-config.zip` (gitignored). Upload it the same way and open **WooCommerce → Demo loan**. The screen (`manage_woocommerce`) sets the product ids (blank: 8946), the coupon product ids, the bundle product id, the terms PDF and model cancellation form for the booking confirmation, and the test redirect address. It cannot turn on live charges or real customer email. If a constant and the screen are both set, the constant wins.

The £25 code is restricted to product **6687** (De-Reacher stem). Terms 3A.1(a) also describe the bundle. Add that product id with `creature_demo_loan_coupon_product_ids` when the bundle should accept the code. The default stays 6687 if the filter returns an empty list.

Stripe must be in test mode, with a secret key that starts with `sk_test_`. Checkout on a demo-only basket shows Stripe only, requires an account, and forces the card to be saved for later off-session use.

After payment, open the order and run **Start demo loan**. Dispatch date blank uses today. Delivery date blank uses dispatch plus 2 England-and-Wales working days and is marked as an estimate. The due date is delivery plus 20 days (21 days inclusive). When the loan has a tracked delivery date (entered at start, or later to replace the estimate), the rider is emailed the due date, the end of grace and the deemed purchase date (`dates_on_delivery`, terms 3.2), once.

## Booking confirmation files

The booking confirmation (the WooCommerce order confirmation for a demo order) gets the terms PDF and the model cancellation form attached (terms 11.1, Appendix B). Set them under **WooCommerce → Demo loan** as a media library ID or a file path, or return them from the `creature_demo_loan_confirmation_files` filter as an array keyed `terms_pdf` and `cancellation_form`.

A file is attached only if it exists, is a document (`pdf`, `doc`, `docx`, `odt`, `rtf`) and sits inside the uploads folder. Add other folders with `creature_demo_loan_attachment_roots`. This stops a path typed into the settings screen attaching a server file such as `wp-config.php` to a customer email. With no file set, nothing is attached and shop managers see an admin notice on the WooCommerce screens.

The confirmation can also carry extra content: the consent record (version, date, wording) and the rider's answer to the express request. That is the `booking_confirmation` copy slot, which is empty until Cadence writes it. The answer is read from the order meta `_creature_demo_express_request` (`yes` or `no`). Nothing in this plugin sets that meta yet.

## My account: the Demo loan tab (terms 3A.1 and 3.2)

The plugin adds a **Demo loan** item to the WooCommerce My account menu (above Log out), at the endpoint `/my-account/demo-loan/`. The item appears only for a customer with at least one demo order, in any state. The tab lists the logged-in customer's demo orders: active loans (on loan, overdue, return booked, with a due date) with the dates, keep price and choices; demo-deemed kits with the dates and keep price but no choices; demo-inspect kits, listed with no choices; and booked kits the kit has not reached yet (processing or on hold, no due date), with the keep price only. Each active loan shows its due date, the last day of grace and the deemed purchase date (all as `30 November 2026`), the keep price (from the same `loan_keep_price_pence()` path as the emails: a fallback booking shows the £212 ceiling), the return and keep buttons, and a link to the order. The Demo loan block on the order's own page stays. When the crash flag is on, Keep is hidden and `crash_hold_notice` is shown in the tab, in the order's Demo loan section and on the pay page.

The endpoint is registered with `add_rewrite_endpoint` on `init`, and the rewrite rules are flushed on `wp_loaded` (and on activation), once per plugin version (option `creature_demo_loan_rewrite_version`). If the tab 404s after an upload that did not change the version, open Settings → Permalinks and save once.

The date and price rows are only shown when their label slot has wording (`my_account_label_due`, `my_account_label_grace_end`, `my_account_label_deemed_on`, `my_account_label_keep_price`). All five new slots are empty until Cadence writes them, so until then the tab shows the heading, the choice buttons, the crash notice and the order link, but no dates or price.

Re-offering Keep after a crash inspection is still manual: `choice_allowed()` is unchanged.

## Loan

| Status | Slug |
| --- | --- |
| On loan | `demo-loan` |
| Return booked | `demo-booked` |
| Returned (inspecting) | `demo-inspect` |
| Returned | `demo-returned` |
| Overdue | `demo-overdue` |
| Kept (purchased) | `demo-kept` |
| Deemed purchase | `demo-deemed` |
| Closed | `demo-closed` |

The orders list has a **Due date** column (HPOS and the legacy posts screen).

Reminders run once a day on Action Scheduler (`creature_demo_loan_daily`), or WP-Cron if Action Scheduler is not loaded: 5 days before the due date, on the due date, at the end of the 5-day grace period, and 10 days after the due date. The first two include the two choice links. Every date a customer sees reads like "30 November 2026". Stored dates stay Y-m-d. Every customer email carries `Reply-To: info@creaturecycles.co.uk`.

Choices (terms 3A.1), from the email link or My Account → view order. The link needs the account nonce or the order token, and the logged-in customer must own the order. There are two:

1. **Return it.** Status becomes Return booked. The shop is emailed once to send the Royal Mail QR, and the rider is emailed once. When the kit is back and inspected, one £25 coupon is emailed on **every** inspected return, including when no choice was made (terms 3A.5): fixed cart, single use, individual use, billing email only, product 6687, 60 days from issue. No coupon after a keep payment or a deemed purchase. The retired third choice, `buy`, reads as return: an order that stored `buy` is a return, and an old `buy` link records return.
2. **Keep it.** A pending order is emailed with a payment link for the loan's keep price, less late fees already paid. The keep price is worked out at booking: the bundle product's price that day (default product 8425, set by `CREATURE_DEMO_LOAN_BUNDLE_PRODUCT_ID`, the `creature_demo_loan_bundle_product_id` filter, or the config screen), less the £25 demo fee and £12 for used stock, never more than £212, floored at £0. It is stored on the order as `_creature_demo_bundle_price_pence` and `_creature_demo_bundle_price_source`: `bundle` for a usable price, `fallback` when the product has no usable price, and `fallback_below_floor` when the bundle price is at or below £37 (the demo fee plus used stock), which is a price error. Either fallback gives the £212 ceiling as the keep price. Unpaid late fees are not added. Paying it marks the loan Kept (purchased), records the unit sold, fires `creature_demo_loan_unit_removed_from_fleet`, and does not issue a coupon. An unpaid keep link lapses after 7 calendar days and the earlier choice (or "no choice", which means return) is restored.

No stored choice is treated as return. Return can be chosen until the return scan, or until the due date if `creature_demo_loan_buy_return_switch_until` is `due_date`. Keep stays available until the deemed-purchase day.

The **return dispatch date** (terms 3.3) is the earlier of the **Proof of posting date** and the first scan, using whichever is set. The order screen has both fields side by side, and a **Kit received date**. With neither date set nothing is billed for lateness. Late fees are £3 a day from day 6 after the due date through the dispatch date, capped at £24. A dispatch date on or after day 14 raises the deemed purchase instead: the loan's keep price less late fees already paid. A kit posted by the end of day 13 is not a deemed purchase, even if Royal Mail scans it later. Paying the deemed purchase marks the unit sold. If the kit then comes back, is inspected, and the refund flag allows it, the plugin records a refund of the deemed amount less the rest of the £24 cap and any damage and missing-parts amounts saved on the order.

**One bill** (terms 4.4 and Appendix C). Nothing is charged at the scan. When inspection is recorded, the plugin raises one charge order with one line each for the late fee, damage and missing parts, and emails it once. The £24 late-fee cap applies to its line, and the overall cap (the loan's keep price, never more than £212) applies to the whole bill, filled late fee first, then damage, then missing parts. Raising the bill a second time does nothing.

A damage or missing-parts line needs the inspection findings (terms 6.4(b)). The order screen has a findings field and up to 6 photo slots (a media library ID or a full https link each). The bill email fills `{findings}` and `{photos}`. Without findings the bill is refused, nothing is sent, and the inspection stays open to try again. Photos are not enforced. James is still to confirm the evidence process.

The bill is due within 5 working days of the kit arriving back (Monday to Friday, with no bank-holiday table). The kit counts as arrived on the Kit received date the shop enters. A scan never starts the clock: with no date there is no day-4 alert and no fallback bill, and the shop is told once. The daily job emails info@ on working day 4 if the bill is not raised yet. Once working day 5 has passed with no inspection, it raises a late-fee-only bill, and no damage or missing-parts charge can be raised on that loan afterwards, because the terms allow one bill. The £25 code is still issued on the inspected return.

Before any saved-card charge the plugin emails the amount, the reason, and the payment link, and logs that notice on the order. The charge runs only when 7 calendar days have passed since the notice, the charge is unpaid, any dispute is resolved by upholding it, and Stripe is in test mode with an `sk_test_` key. A dispute blocks the charge. Upholding or waiving it emails the final decision, and an upheld charge waits 7 calendar days from that decision email (terms 4.4(b)), not from the first notice. `authentication_required` emails the payment link and does not mark the charge paid. A declined card emails the payment link too (`charge_declined`, terms 4.5), once per charge. The amount is still owed. Card numbers and secret keys are redacted out of notes and mail. A demo-only checkout must bill and ship to the UK (England, Scotland, Wales and Northern Ireland). Jersey, Guernsey and Isle of Man postcodes are refused. Other orders are not restricted.

**Admin steps for a cancellation or refund (terms 3A.4).** For an 11.3 cancellation and for a 10.4 refund, tick **Refunded under 10.4/11 (no £25 code)** on the order screen and update the order. The pro-rata refund itself stays manual. Without the tick the rider is sent a £25 code when the kit is inspected.

**Deemed purchase, then the kit comes back** (terms 8.5(b) and 6.4(b)). Enter the **Kit received date** before anything is deducted: a damage or missing-parts amount on the deemed refund is refused without it, and so is a damage or missing-parts line on a normal bill. A deduction has to be raised within 5 working days of that date (the fifth day itself is in time). After that the refund goes ahead without it. The shop gets the working-day-4 alert for these kits too. The deduction notice is its own slot, `deduction_notice_deemed`. It is empty until Cadence writes it, and while it is empty the deduction is not sent and the order says so (the refund waits). It has no payment link, because the money is already held. Seven days after the notice, or after the dispute decision, the shop gets one alert to run **Refund deemed purchase** again to settle it. The 30-days-from-payment rule for the refund itself (8.5(c)) is unchanged.

## Consent

New orders store the consent version `version-5`: the wording is the terms' Appendix A, and the admin screen and the booking confirmation read it back as "Terms version 5". `v5b` also reads back as "Terms version 5". `v5` (an older draft with a fixed "£212") and `v4` read back as the text they stored. A version string the plugin does not know falls back to `version-5`.

## James’s flags

Each default matches the v5 terms.

| Filter | Default | Effect |
| --- | --- | --- |
| `creature_demo_loan_notice_days` | `7` | Calendar days after the charge notice before the saved card may be charged. |
| `creature_demo_loan_buy_return_switch_until` | `return_scan` | `due_date` stops choosing return after the due date. |
| `creature_demo_loan_keep_lapse_days` | `7` | Calendar days before an unpaid keep link lapses. |
| `creature_demo_loan_keep_choice_open` | `true` | Return `false` to close the keep choice early. |
| `creature_demo_loan_refund_includes_keep_choosers` | `true` | A post-deemed refund applies to anyone who sends the kit back. `false` limits it to riders who never chose keep. |

Other hooks: `creature_demo_loan_confirmation_files`, `creature_demo_loan_attachment_roots`, `creature_demo_loan_copy_catalogue` (change a copy slot without editing the file), and one action per email slot, `creature_demo_loan_mail_{slot}`, fired with the row, the order and the merge values each time an email is sent.

## Checks

```bash
php wordpress-plugins/mu-plugins/tests/creature-demo-loan-test.php
php wordpress-plugins/mu-plugins/tests/creature-demo-loan-config-test.php
```

Staging walkthrough (creates orders, does not charge Stripe unless you opt in):

```bash
wp eval-file wordpress-plugins/creature-demo-loan/bin/staging-walkthrough.php
```

## Copy

Customer wording is Cadence's, per slot, in `includes/class-creature-demo-loan-copy.php`. A slot with no wording is an empty string and is never sent as a blank email. Cadence's v3 wording (`demo-loan-copy-v3`) is in, including `express_request_checkbox` (the "Send my kit now" label), `crash_hold_notice` and `deduction_notice_deemed` (the notice for a damage or missing-parts deduction from a deemed refund, with no pay link). Cadence's v4 wording (`demo-loan-copy-v4`) is in too: the four Demo loan tab labels, `my_account_no_loans` (it assumes the tab lists booked, deemed and inspecting kits), and the "in My account, under Demo loan" wording. Cadence's v5 addendum (`demo-loan-copy-v5`) is in too. The final decision email has three wordings for each kind, picked in code (there is no single `dispute_decision` or `dispute_decision_deemed` slot now). A deemed-refund deduction uses `dispute_decision_deemed_upheld` (`{kept}` above £0.00 and `{released}` £0.00), `dispute_decision_deemed_partial` (both above £0.00) or `dispute_decision_deemed_waived` (`{kept}` £0.00). It has no pay link and no saved-card line, and the waived wording has no evidence block and no refund date. An ordinary charge uses `dispute_decision_upheld`, `dispute_decision_partial` (upheld with `{released}` above £0.00) or `dispute_decision_waived`. On the ordinary email `{kept}` is the amount to pay. The variants write the outcome in words and do not use `{decision}`. Tags: `{kept}` (what is finally kept, £0.00 on a waiver), `{released}` (what is refunded or cut), `{amount}`, `{reason}`, `{findings}`, `{photos}`, `{link}`, `{price}` and `{decision_reasons}`. No customer slot is empty. On an ordinary waiver the unpaid charge order is cancelled and `{link}` is blank, so the earlier pay link stops working. A reduced ordinary charge is not re-noticed: the notice amount and date follow the cut and no second `charge_notice_*` email goes out. If any of the three deemed wordings is emptied, **Uphold** and **Waive** on a deduction do nothing except an order note and one shop email: the dispute stays open and no 7-day clock starts. **Uphold** and **Waive** also need the order screen's **Reasons for decision** box filled in (Appendix D). With it blank nothing is recorded or sent, and an order note says why. The reasons are stored on the charge order and fill `{decision_reasons}`, which sits on its own line after "because:" in every variant. The box's help text reads "This is shown to the customer after ‘because:’. Write it as a full sentence." `admin_bill_due` (the working day 4 alert to the shop) is Cadence's v3 wording, with `{deadline}` as a UK date. The other `admin_*` alerts are plain internal wording. The consent wording is checked byte for byte against `wordpress-plugins/mu-plugins/tests/fixtures/appendix-a-consent.txt`.
