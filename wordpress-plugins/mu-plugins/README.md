# Whole rear end discount

Must-use WordPress plugin for Creature Cycles. When a cart or order contains all three of these products, and those lines share the same `design_id`, WooCommerce adds one fee:

| Product | ID | Catalogue price |
| --- | --- | --- |
| BB yoke | 8634 | £48 |
| SS yoke | 8635 | £48 |
| Dropouts | 8636 | £64 |

The fee is labelled **Whole rear end discount** and is **−£24** per complete set, so those three lines total **£136** instead of £160. Quantity above 1 uses the smallest quantity of the three parts that share that `design_id` (two of each is −£48). A missing part, or three parts whose `design_id` values differ, leaves the total unchanged.

Leave draft product 8637 unpublished. This plugin never creates or edits products.

## Install

Copy `creature-rear-end-discount.php` into `wp-content/mu-plugins/` on the shop. Create that folder if it is not there.

WordPress auto-loads PHP files placed directly in `mu-plugins/`. Copy the PHP file into that folder on its own, and leave this README in the repo.

It loads on the next request, with no activate step. Under **Plugins → Must-Use** it is listed as “Creature Cycles Whole Rear End Discount”. WooCommerce must already be active.

To remove it, delete that file from `mu-plugins/`.

## Unpaid design orders

`creature-unpaid-design-orders.php` is a second must-use file. Copy it into `wp-content/mu-plugins/` the same way. Once a day, WP-Cron cancels WooCommerce orders that are still **pending**, were created more than 90 days ago, and have order meta `design_id` or `creature_design_id`. Paid orders are not touched. The tools-api does the same cancel when `UNPAID_CLEANUP_INTERVAL_HOURS` is set, and it also expires the resume token. Either path is enough for the Woo order. Running both is safe: a cancelled order is no longer pending.

Default window is 90 days, matching the resume token. Filter `creature_unpaid_design_order_ttl_days` to shorten it. Layout installs this file; it does not publish products.

## Frame Designer only purchase

`creature-fd-only-purchase.php` is the catalogue guard. Copy it into `wp-content/mu-plugins/` the same way. WooCommerce must already be active. It loads on the next request, with no activate step. Under **Plugins → Must-Use** it is listed as “Creature Cycles Frame Designer Only Purchase”.

The BB yoke (8634), SS yoke (8635), and dropouts (8636) cannot be bought from the product page, the shop loop, `?add-to-cart=`, or the Store API basket unless the line carries `design_id`. The price stays. Astra calls `woocommerce_template_single_add_to_cart()` itself, so the swap is on `woocommerce_simple_add_to_cart` (priority 5, and the other product-type actions). 8634 and 8635 show **Open Frame Designer**, linking to `/apps/frame-designer.html`, in place of the quantity box and Add to basket. 8636 keeps a disabled **Coming soon** button; the product’s own Coming soon copy is not rewritten. Other products are not touched, so they still render one buy form.

Astra’s on-card button (`ast-on-card-button`) is replaced the same way. WooPayments express checkout (card, Google Pay, Apple Pay, and WooPay) and PayPal smart buttons are turned off on these product pages only.

Frame Designer itself does not use this basket. Save and Continue to shop create the pending order through the tools-api (`POST /wp-json/wc/v3/orders`) and send the customer to order-pay. This file does not hook order creation, order totals, or order-pay, so that payment and the whole-rear-end discount on the order stay as they are. A basket line that does have `design_id` is kept, and checkout copies that id onto the order line so the cart fee can follow it.

Filters, if Layout needs to change them without editing the file:

| Filter | Default |
| --- | --- |
| `creature_fd_only_product_ids` | `8634`, `8635`, `8636` |
| `creature_fd_only_coming_soon_ids` | `8636` |
| `creature_fd_only_designer_url` | `/apps/frame-designer.html` |

Clear `creature_fd_only_coming_soon_ids` when dropouts go on sale. They then get the Frame Designer button and remain guarded. Remove an id from `creature_fd_only_product_ids` only if that part should be a normal catalogue add again.

`frame-designer.html` only reads `?design=` and `?resume=`. It has no part parameter, so the button does not add one.

8634 and 8635 product pages also print “Design files are delivered within 5 working days of payment.” under the Frame Designer button. 8636 Coming soon does not. That sentence comes from `creature-fd-order-experience.php` when it is installed. Filter `creature_fd_lead_time` to change it.

Checks in the repo, without a shop:

```bash
php wordpress-plugins/mu-plugins/tests/creature-fd-only-purchase-test.php
```

On the shop, after the file is in place:

1. Open the BB yoke and SS yoke products. The price is still there. The quantity box and Add to basket control are gone, replaced in that same spot by **Open Frame Designer**. The button opens `/apps/frame-designer.html`. Card, Google Pay, Apple Pay, and PayPal buttons are not on the page. A normal product still has one buy form, not two.
2. Open dropouts. The button reads **Coming soon** and does not add the product. Existing Coming soon text on the product is unchanged.
3. In the shop grid, those three products use the Frame Designer link or Coming soon, including Astra’s on-card button. It is not an ajax add-to-cart.
4. Visit `?add-to-cart=8634`. The basket does not gain a line. The notice reads **Design your part in Frame Designer first** and links to Frame Designer. Repeat for 8635 and 8636.
5. Add 8634 from the block basket or Store API (`POST /wp-json/wc/store/v1/cart/add-item`). The add is refused. A basket that already held 8634, 8635, or 8636 with no `design_id` loses that line on the next cart or checkout view, with the same notice.
6. Save a design in Frame Designer and continue to checkout. The browser lands on order-pay for a tools-api order. That order still has `design_id` on the lines, and payment is unchanged. The rear-end discount still applies when 8634, 8635, and 8636 share a `design_id`.
7. On the BB yoke and SS yoke product pages, the lead-time line sits under **Open Frame Designer**. Dropouts do not show it.

## Frame Designer order experience

`creature-fd-order-experience.php` (1.2.1) is display copy for orders that already exist. Copy it into `wp-content/mu-plugins/` the same way, and replace `creature-fd-only-purchase.php` with the copy from this repo (1.2.1) so the product page can print the same lead time. WooCommerce must already be active. It loads on the next request. Under **Plugins → Must-Use** it is listed as “Creature Cycles Frame Designer Order Experience”.

It does not hook the basket, fees, or order totals. Prices and the tools-api order create stay as they are.

What customers see:

- On order-pay, a Frame Designer order (an 8634, 8635, or 8636 line that has `design_id`) no longer shows Woo’s red guest-order error. That page shows an info notice: **Your Frame Designer order. Design files are delivered within 5 working days of payment.** Any other guest order still gets Woo’s error. Email verification and the pay-for-order check are unchanged.
- `design_id` and `creature_design_id` are hidden on order-pay, the thank-you page, My Account, and customer emails. They stay visible in wp-admin and on emails sent to the shop. `geometry_summary` stays visible and is labelled **Geometry** for customers.
- The thank-you page, and the customer processing, on-hold, and completed emails, say **Design files are delivered within 5 working days of payment.** for those orders.
- On order-pay, a Frame Designer order also shows a required checkbox, separate from Woo’s terms box: **I want my design files made and supplied straight away, and I understand I lose my 14-day right to cancel once work starts.** Payment is refused until it is ticked. Consent is stored on the order (`_creature_fd_cancellation_waiver`, a UTC timestamp, and the wording). The customer processing email then adds one line: **You asked us to start straight away and acknowledged that the 14-day right to cancel ends once work starts.** Other orders, and the on-hold and completed emails, do not get that line. WooPayments express buttons on that page are hidden, because they cannot post the checkbox; the Store API still rejects them. The PayPal payment method stays and is checked when it creates the PayPal order.

- The customer processing and on-hold emails for a Frame Designer order include **Request a change** while the window is open. The link dies 24 hours after payment, or when the order is marked **In design**, whichever comes first. Filter `creature_fd_change_window_hours` to change the 24. The order screen has a **Change window closed** checkbox for the same cut-off. Saving a change updates the geometry on that order and does not take another payment. The change-window check is not cached: a closed window does not reopen from an old response.
- Customer emails, My Account, and the thank-you page do not show the design-id sentence that used to be the order's customer note. A note the customer typed still shows. The design id is a private order note instead. Cancelling, refunding, or failing the Woo order updates the tools-api design through the existing Order updated webhook.

Filter `creature_fd_lead_time` to change the phrase (default `5 working days`). Filter `creature_fd_cancellation_waiver_text` to follow the final T&Cs. The tools-api uses `FD_LEAD_TIME` for the save-design email and the payment email. It cannot read the WordPress filter, so the two have to be kept in step. If Railway still has `REVIEW_LEAD_TIME_DAYS=7` and `FD_LEAD_TIME` is unset, those emails say “7 working days”. Delete `REVIEW_LEAD_TIME_DAYS`, or set it to `5`. When both are set, `FD_LEAD_TIME` wins.

```bash
php wordpress-plugins/mu-plugins/tests/creature-fd-order-experience-test.php
```

## Smoke test

Use a pending order, then cancel it. Do not take payment.

1. Create a pending order with line items for products **8634**, **8635**, and **8636**. Set line-item meta `design_id` to the same value on each of those three lines. The frame-design checkout already does this: tools-api creates the order over the WooCommerce REST API, and the customer pays on the order-pay link.
2. Open the order-pay URL while the order is still pending. The totals table shows **Whole rear end discount −£24**. At the catalogue prices above, the order total is **£136**.
3. In the cart, the same three products with the same `design_id` show that fee. Give the lines different `design_id` values, or remove one product, and the fee drops off. Quantity 2 of each part shows **−£48**.
4. Open order-pay again (or recalculate the order). The fee stays a single line. It does not add a second −£24.
5. In **WooCommerce → Orders**, set the test order to **Cancelled**.

The REST create calculates order totals, which is when the fee is written onto a new pending order. The order-pay page checks again before showing the total and before the payment is taken, including for an order that was created before this file was installed.
