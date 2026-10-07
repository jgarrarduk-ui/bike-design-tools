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

## Smoke test

Use a pending order, then cancel it. Do not take payment.

1. Create a pending order with line items for products **8634**, **8635**, and **8636**. Set line-item meta `design_id` to the same value on each of those three lines. The frame-design checkout already does this: tools-api creates the order over the WooCommerce REST API, and the customer pays on the order-pay link.
2. Open the order-pay URL while the order is still pending. The totals table shows **Whole rear end discount −£24**. At the catalogue prices above, the order total is **£136**.
3. In the cart, the same three products with the same `design_id` show that fee. Give the lines different `design_id` values, or remove one product, and the fee drops off. Quantity 2 of each part shows **−£48**.
4. Open order-pay again (or recalculate the order). The fee stays a single line. It does not add a second −£24.
5. In **WooCommerce → Orders**, set the test order to **Cancelled**.

The REST create calculates order totals, which is when the fee is written onto a new pending order. The order-pay page checks again before showing the total and before the payment is taken, including for an order that was created before this file was installed.
