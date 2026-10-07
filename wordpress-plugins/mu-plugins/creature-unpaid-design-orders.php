<?php
/**
 * Plugin Name: Creature Cycles Unpaid Design Orders
 * Description: Daily WP-Cron pass that cancels pending WooCommerce orders for frame designs older than 90 days. Does not touch paid orders. Must-use plugin.
 * Version: 1.0.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/ (not into a subfolder). WooCommerce
 * must already be active. Layout installs it. The tools-api job is the other
 * half: it expires resume tokens and soft-deletes the design row. This file
 * only cancels leftover pending orders if that process did not.
 *
 * An order qualifies when it is still pending, was created more than
 * CREATURE_UNPAID_DESIGN_TTL_DAYS ago (default 90, same as the resume token),
 * and carries order meta design_id or creature_design_id. Those keys are
 * written by the tools-api when it creates the pending order.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CREATURE_UNPAID_DESIGN_CRON = 'creature_cancel_expired_unpaid_design_orders';

/**
 * Days a pending frame-design order may sit before this cron cancels it.
 * Filter creature_unpaid_design_order_ttl_days to change it. Default 90.
 *
 * @return int
 */
function creature_unpaid_design_ttl_days() {
	$days = (int) apply_filters( 'creature_unpaid_design_order_ttl_days', 90 );
	return $days > 0 ? $days : 90;
}

/**
 * Cancel a batch of scrapped pending design orders. Safe to run twice:
 * a cancelled order is no longer pending, so the next pass skips it.
 *
 * @return int Number of orders cancelled on this pass.
 */
function creature_cancel_expired_unpaid_design_orders() {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$days   = creature_unpaid_design_ttl_days();
	$cutoff = time() - ( $days * DAY_IN_SECONDS );
	$orders = wc_get_orders(
		array(
			'status'       => 'pending',
			'limit'        => 100,
			'return'       => 'objects',
			'date_created' => '<' . $cutoff,
			'meta_query'   => array(
				'relation' => 'OR',
				array(
					'key'     => 'creature_design_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => 'design_id',
					'compare' => 'EXISTS',
				),
			),
		)
	);

	$cancelled = 0;
	foreach ( $orders as $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			continue;
		}
		if ( $order->get_status() !== 'pending' ) {
			continue;
		}
		$order->update_status(
			'cancelled',
			sprintf( 'Unpaid frame design older than %d days.', $days )
		);
		$cancelled++;
	}

	return $cancelled;
}

add_action( CREATURE_UNPAID_DESIGN_CRON, 'creature_cancel_expired_unpaid_design_orders' );

add_action(
	'init',
	static function () {
		if ( wp_next_scheduled( CREATURE_UNPAID_DESIGN_CRON ) ) {
			return;
		}
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CREATURE_UNPAID_DESIGN_CRON );
	}
);
