# De-Reacher demo loan

Postal demo loan for the De-Reacher. The rider pays £25 at checkout, the card is saved with the WooCommerce Stripe Gateway, and later charges (late fees, keep, deemed purchase, damage or missing parts) go through a payment link first. The saved card is charged only after the notice window, and only in Stripe test mode unless live charges are turned on in `wp-config.php`.

WooCommerce and WooCommerce Stripe Gateway 11.x must already be active. This is a normal plugin. It is not a must-use file.

## Install on staging

From this directory:

```bash
sh wordpress-plugins/creature-demo-loan/build-zip.sh
```

That writes `wordpress-plugins/creature-demo-loan.zip` (gitignored). In wp-admin go to **Plugins → Add New → Upload Plugin**, upload that zip, and activate **Creature Cycles De-Reacher Demo Loan**.

Optional: copy `optional-mu-loader/creature-demo-loan-loader.php` (the same file as `wordpress-plugins/mu-plugins/creature-demo-loan-loader.php`) into `wp-content/mu-plugins/` on its own, not into a subfolder. The loader includes the plugin from `wp-content/plugins/creature-demo-loan/` when you do not want an activate click. If both the loader and an active plugin are present, the plugin loads once.

Do not install this on the live shop until James has turned Stripe test mode off on purpose. Live charges stay off unless `wp-config.php` defines `CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES` as boolean `true` before plugins load. Customer mail stays redirected to `demo-loan-test@creaturecycles.co.uk` with a `[TEST]` subject prefix unless `CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL` is boolean `true`.

## What Layout still sets

Demo product ids are empty until you set them. Nothing is treated as a demo basket until then.

```php
add_filter( 'creature_demo_loan_product_ids', function () {
    return array( 1234 );
} );
```

Or define `CREATURE_DEMO_LOAN_PRODUCT_IDS` as a comma-separated list in `wp-config.php` before plugins load. Staging has no `wp-config.php` access, so the companion plugin can set the same filter from wp-admin:

```bash
sh wordpress-plugins/creature-demo-loan-config/build-zip.sh
```

That writes `wordpress-plugins/creature-demo-loan-config.zip` (gitignored). Upload it the same way and open **WooCommerce → Demo loan**. The screen (`manage_woocommerce`) sets the product ids, the coupon product ids, and the test redirect address. It cannot turn on live charges or real customer email. If a constant and the screen are both set, the constant wins.

The £25 code is restricted to product **6687** (De-Reacher stem). Terms 3A.1(a) also describe the bundle. Add that product id with `creature_demo_loan_coupon_product_ids` when the bundle should accept the code. The default stays 6687 if the filter returns an empty list.

Stripe must be in test mode, with a secret key that starts with `sk_test_`. Checkout on a demo-only basket shows Stripe only, requires an account, and forces the card to be saved for later off-session use.

After payment, open the order and run **Start demo loan**. Dispatch date blank uses today. Delivery date blank uses dispatch plus 2 England-and-Wales working days. The due date is delivery plus 20 days (21 days inclusive).

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

Reminders run once a day on Action Scheduler (`creature_demo_loan_daily`), or WP-Cron if Action Scheduler is not loaded: 5 days before the due date, on the due date, at the end of the 5-day grace period, and 10 days after the due date. The first two include the three choice links.

Choices, from the email link or My Account → view order. The link needs the account nonce or the order token, and the logged-in customer must own the order.

1. **Buy a new one.** Choice stays `buy`. The return is booked and the shop is emailed to send the Royal Mail QR. When the kit is scanned and inspected, one £25 coupon is emailed: fixed cart, single use, individual use, billing email only, product 6687, 60 days from issue. No coupon after a keep payment or a deemed purchase.
2. **Return it, and don’t buy.** Status becomes Return booked. The shop is emailed once. No coupon.
3. **Keep this kit.** A pending order is emailed with a payment link for £212, less late fees already paid. Unpaid late fees are not added. Paying it marks the loan Kept (purchased), records the unit sold, fires `creature_demo_loan_unit_removed_from_fleet`, and does not issue a coupon. An unpaid keep link lapses after 7 calendar days and the earlier choice (or “no choice”, which means return) is restored.

No stored choice is treated as return. Buy and return can be switched until the return scan, or until the due date if `creature_demo_loan_buy_return_switch_until` is `due_date`. Keep stays available until the deemed-purchase day.

Late fees are £3 a day from day 6 after the due date through day 13, capped at £24, and charged once as a single total when the return is scanned. A scan on or after day 14 raises the deemed purchase instead: £212 less late fees already paid. Paying that marks the unit sold. If the kit then comes back, is inspected, and the refund flag allows it, the plugin records a refund of the deemed amount less the rest of the £24 cap and any damage amount saved on the order.

Before any saved-card charge the plugin emails the amount, the reason, and the payment link, and logs that notice on the order. The charge runs only when 7 calendar days have passed since the notice, the charge is unpaid, any dispute is resolved by upholding it, and Stripe is in test mode with an `sk_test_` key. A dispute blocks the charge. Upholding or waiving it emails the decision. `authentication_required` emails the payment link and does not mark the charge paid. Card numbers and secret keys are redacted out of notes and mail. A demo-only checkout must bill and ship to the UK (England, Scotland, Wales and Northern Ireland). Jersey, Guernsey and Isle of Man postcodes are refused. Other orders are not restricted.

## James’s flags

Each default matches the v5 terms.

| Filter | Default | Effect |
| --- | --- | --- |
| `creature_demo_loan_notice_days` | `7` | Calendar days after the charge notice before the saved card may be charged. |
| `creature_demo_loan_buy_return_switch_until` | `return_scan` | `due_date` stops buy/return switching after the due date. |
| `creature_demo_loan_keep_lapse_days` | `7` | Calendar days before an unpaid keep link lapses. |
| `creature_demo_loan_keep_choice_open` | `true` | Return `false` to close the keep choice early. |
| `creature_demo_loan_refund_includes_keep_choosers` | `true` | A post-deemed refund applies to anyone who sends the kit back. `false` limits it to riders who never chose keep. |

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

Every customer string is marked `[COPY: Cadence]`. The slot keys live in `includes/class-creature-demo-loan-copy.php`.
