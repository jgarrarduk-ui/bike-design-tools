<?php
/**
 * Plugin Name: Creature Cycles Whole Rear End Discount
 * Description: Applies a £24 "Whole rear end discount" when a cart or order contains the BB yoke (8634), SS yoke (8635), and dropouts (8636) sharing the same design_id. One discount per complete set. Must-use plugin. Does not publish products.
 * Version: 1.0.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/ (not into a subfolder). WooCommerce
 * must already be active. Draft product 8637 is ignored; this file never
 * writes products.
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Whole rear end bundle discount.
 *
 * Cart: woocommerce_cart_calculate_fees (WooCommerce clears fees before the
 * hook, and add_fee replaces the same name, so a recalculation cannot stack).
 *
 * Orders, including pending orders created by the tools-api REST call: the fee
 * is synced inside woocommerce_order_before_calculate_totals, which REST runs
 * on create. The order-pay screen is a separate path and does not recalculate
 * on its own, so that request syncs the fee before the pay form is shown and
 * before WC_Form_Handler::pay_action (wp priority 20) charges the order.
 */
final class Creature_Rear_End_Discount {

	const BB        = 8634;
	const SS        = 8635;
	const DROPOUTS  = 8636;
	const DISCOUNT  = 24.0;
	const FEE_NAME  = 'Whole rear end discount';
	const FEE_META  = '_creature_rear_end_discount';

	/** @var bool Prevents a totals pass started here from syncing again. */
	private static $applying = false;

	/** @var bool */
	private static $booted = false;

	public static function boot() {
		if ( self::$booted || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$booted = true;

		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'on_cart_fees' ), 20 );
		add_action( 'woocommerce_order_before_calculate_totals', array( __CLASS__, 'on_order_totals' ), 20, 2 );
		add_action( 'woocommerce_order_item_fee_after_calculate_taxes', array( __CLASS__, 'on_fee_taxes' ), 10, 2 );
		// Before WC_Form_Handler::pay_action on `wp` (priority 20).
		add_action( 'wp', array( __CLASS__, 'on_order_pay' ), 5 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'on_before_pay' ) );
	}

	/**
	 * Complete sets: for each design_id, the smallest quantity among 8634,
	 * 8635, and 8636. Lines without a design_id, and any other product
	 * (including draft 8637), do not count.
	 *
	 * @param array<int, array{product_id?:int, variation_id?:int, qty?:int, design_id?:mixed}> $lines
	 * @return int
	 */
	public static function count_sets( array $lines ) {
		$by_design = array();

		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$part_id = self::part_id(
				isset( $line['product_id'] ) ? $line['product_id'] : 0,
				isset( $line['variation_id'] ) ? $line['variation_id'] : 0
			);
			if ( ! $part_id ) {
				continue;
			}
			$design_id = self::design_id( isset( $line['design_id'] ) ? $line['design_id'] : '' );
			if ( '' === $design_id ) {
				continue;
			}
			$qty = isset( $line['qty'] ) ? (int) $line['qty'] : 0;
			if ( $qty < 1 ) {
				continue;
			}
			if ( ! isset( $by_design[ $design_id ] ) ) {
				$by_design[ $design_id ] = array(
					self::BB       => 0,
					self::SS       => 0,
					self::DROPOUTS => 0,
				);
			}
			$by_design[ $design_id ][ $part_id ] += $qty;
		}

		$sets = 0;
		foreach ( $by_design as $counts ) {
			$sets += min( $counts[ self::BB ], $counts[ self::SS ], $counts[ self::DROPOUTS ] );
		}
		return $sets;
	}

	/**
	 * Negative fee total for this many sets. Zero when there is no set.
	 *
	 * @param int $sets
	 * @return float
	 */
	public static function discount_total( $sets ) {
		$sets = (int) $sets;
		if ( $sets < 1 ) {
			return 0.0;
		}
		return round( -1 * self::DISCOUNT * $sets, 2 );
	}

	/**
	 * One discount fee, never two. Calling this again with the resulting fee
	 * reports no change.
	 *
	 * @param array<int, array{id:int|string, name?:string, total?:float|string, meta?:bool, tax_status?:string, total_tax?:float|string}> $fees
	 * @param int $sets
	 * @return array{changed:bool, remove:array<int, int|string>, update:?array, create:?array}
	 */
	public static function reconcile_fees( array $fees, $sets ) {
		$amount = self::discount_total( $sets );
		$ours   = array();

		foreach ( $fees as $fee ) {
			if ( ! is_array( $fee ) || ! isset( $fee['id'] ) ) {
				continue;
			}
			$name = isset( $fee['name'] ) ? (string) $fee['name'] : '';
			if ( ! empty( $fee['meta'] ) || self::FEE_NAME === $name ) {
				$ours[] = $fee;
			}
		}

		if ( (int) $sets < 1 ) {
			$remove = array();
			foreach ( $ours as $fee ) {
				$remove[] = $fee['id'];
			}
			return array(
				'changed' => array() !== $remove,
				'remove'  => $remove,
				'update'  => null,
				'create'  => null,
			);
		}

		$remove = array();
		$keeper = null;
		foreach ( $ours as $fee ) {
			if ( null === $keeper ) {
				$keeper = $fee;
				continue;
			}
			$remove[] = $fee['id'];
		}

		if ( null === $keeper ) {
			return array(
				'changed' => true,
				'remove'  => $remove,
				'update'  => null,
				'create'  => array(
					'name'       => self::FEE_NAME,
					'amount'     => $amount,
					'meta'       => true,
					'tax_status' => 'none',
				),
			);
		}

		$needs_update = self::FEE_NAME !== (string) $keeper['name']
			|| empty( $keeper['meta'] )
			|| ! self::money_matches( isset( $keeper['total'] ) ? $keeper['total'] : 0, $amount )
			|| ( isset( $keeper['tax_status'] ) && 'none' !== (string) $keeper['tax_status'] )
			|| ( isset( $keeper['total_tax'] ) && ! self::money_matches( $keeper['total_tax'], 0 ) );

		return array(
			'changed' => $needs_update || array() !== $remove,
			'remove'  => $remove,
			'update'  => $needs_update ? array(
				'id'         => $keeper['id'],
				'name'       => self::FEE_NAME,
				'amount'     => $amount,
				'meta'       => true,
				'tax_status' => 'none',
			) : null,
			'create'  => null,
		);
	}

	/**
	 * @param WC_Cart $cart
	 */
	public static function on_cart_fees( $cart ) {
		if ( is_admin() && ! ( function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) ) {
			return;
		}
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_fee' ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$sets = self::count_sets( self::lines_from_cart( $cart ) );
		if ( $sets < 1 ) {
			return;
		}

		$cart->add_fee( self::FEE_NAME, self::discount_total( $sets ), false );
	}

	/**
	 * @param bool     $and_taxes
	 * @param WC_Order $order
	 */
	public static function on_order_totals( $and_taxes, $order ) {
		unset( $and_taxes );
		if ( self::$applying || ! $order instanceof WC_Order ) {
			return;
		}
		self::sync_order_fee( $order );
	}

	/**
	 * Negative fees pick up a share of VAT during calculate_taxes even when
	 * tax_status is none. Clear that so the grand total falls by exactly £24
	 * per set (the same figure the cart fee uses).
	 *
	 * @param WC_Order_Item_Fee $item
	 * @param array             $calculate_tax_for
	 */
	public static function on_fee_taxes( $item, $calculate_tax_for = array() ) {
		unset( $calculate_tax_for );
		if ( ! self::is_our_fee( $item ) ) {
			return;
		}
		self::clear_fee_tax( $item );
	}

	public static function on_order_pay() {
		$order = self::order_pay_order();
		if ( $order instanceof WC_Order ) {
			self::persist_if_needed( $order );
		}
	}

	/**
	 * @param WC_Order $order
	 */
	public static function on_before_pay( $order ) {
		if ( $order instanceof WC_Order ) {
			self::persist_if_needed( $order );
		}
	}

	/**
	 * @param WC_Order $order
	 * @return bool
	 */
	private static function sync_order_fee( $order ) {
		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
				continue;
			}
			$fees[] = array(
				'id'         => $item_id,
				'name'       => (string) $item->get_name(),
				'total'      => method_exists( $item, 'get_total' ) ? $item->get_total() : 0,
				'meta'       => method_exists( $item, 'get_meta' ) ? (bool) $item->get_meta( self::FEE_META, true ) : false,
				'tax_status' => method_exists( $item, 'get_tax_status' ) ? (string) $item->get_tax_status() : 'none',
				'total_tax'  => method_exists( $item, 'get_total_tax' ) ? $item->get_total_tax() : 0,
			);
		}

		$plan = self::reconcile_fees( $fees, self::count_sets( self::lines_from_order( $order ) ) );
		if ( ! $plan['changed'] ) {
			return false;
		}

		foreach ( $plan['remove'] as $item_id ) {
			$order->remove_item( $item_id );
		}
		if ( is_array( $plan['update'] ) ) {
			$item = $order->get_item( $plan['update']['id'] );
			if ( $item ) {
				self::configure_fee( $item, $plan['update']['amount'] );
			}
		}
		if ( is_array( $plan['create'] ) ) {
			$fee = new WC_Order_Item_Fee();
			self::configure_fee( $fee, $plan['create']['amount'] );
			$order->add_item( $fee );
		}
		return true;
	}

	/**
	 * Rewrite a payable order-pay order when the fee is missing or stale.
	 * Paid orders are left alone on page view; an explicit totals recalculation
	 * still goes through on_order_totals.
	 *
	 * @param WC_Order $order
	 */
	private static function persist_if_needed( $order ) {
		if ( self::$applying || ! $order->needs_payment() ) {
			return;
		}
		if ( ! self::sync_order_fee( $order ) ) {
			return;
		}

		self::$applying = true;
		try {
			$order->calculate_totals();
		} finally {
			self::$applying = false;
		}
	}

	/**
	 * @param WC_Order_Item_Fee $item
	 * @param float             $amount
	 */
	private static function configure_fee( $item, $amount ) {
		$item->set_name( self::FEE_NAME );
		$item->set_amount( $amount );
		$item->set_total( $amount );
		$item->set_tax_status( 'none' );
		$item->set_tax_class( '' );
		self::clear_fee_tax( $item );
		if ( method_exists( $item, 'get_meta' ) && ! $item->get_meta( self::FEE_META, true ) ) {
			$item->add_meta_data( self::FEE_META, '1', true );
		}
	}

	/**
	 * @param object $item
	 */
	private static function clear_fee_tax( $item ) {
		if ( method_exists( $item, 'set_taxes' ) ) {
			$item->set_taxes(
				array(
					'total'    => array(),
					'subtotal' => array(),
				)
			);
		}
		if ( method_exists( $item, 'set_total_tax' ) ) {
			$item->set_total_tax( 0 );
		}
	}

	/**
	 * @param object $item
	 * @return bool
	 */
	private static function is_our_fee( $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
			return false;
		}
		if ( method_exists( $item, 'get_meta' ) && $item->get_meta( self::FEE_META, true ) ) {
			return true;
		}
		return self::FEE_NAME === (string) $item->get_name();
	}

	/**
	 * Order-pay request whose key matches the order. Mirrors the check in
	 * WC_Form_Handler::pay_action.
	 *
	 * @return WC_Order|null
	 */
	private static function order_pay_order() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return null;
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		// The order key is the secret for this URL. WooCommerce reads it the same way.
		if ( ! isset( $_GET['key'] ) || ! is_string( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		$key       = wp_unslash( $_GET['key'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = (string) $order->get_order_key();
		if ( '' === $key || '' === $order_key || ! hash_equals( $order_key, $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * @param object $cart
	 * @return array<int, array{product_id:int, variation_id:int, qty:int, design_id:mixed}>
	 */
	private static function lines_from_cart( $cart ) {
		$lines = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'qty'          => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
				'design_id'    => self::cart_design_id( $item ),
			);
		}
		return $lines;
	}

	/**
	 * @param array $item
	 * @return mixed
	 */
	private static function cart_design_id( array $item ) {
		if ( array_key_exists( 'design_id', $item ) ) {
			return $item['design_id'];
		}
		if ( empty( $item['meta_data'] ) || ! is_array( $item['meta_data'] ) ) {
			return '';
		}
		foreach ( $item['meta_data'] as $meta ) {
			$key = null;
			$val = null;
			if ( is_array( $meta ) ) {
				$key = isset( $meta['key'] ) ? $meta['key'] : null;
				$val = array_key_exists( 'value', $meta ) ? $meta['value'] : null;
			} elseif ( is_object( $meta ) ) {
				$key = isset( $meta->key ) ? $meta->key : null;
				$val = isset( $meta->value ) ? $meta->value : null;
			}
			if ( 'design_id' === $key ) {
				return $val;
			}
		}
		return '';
	}

	/**
	 * @param WC_Order $order
	 * @return array<int, array{product_id:int, variation_id:int, qty:int, design_id:mixed}>
	 */
	private static function lines_from_order( $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
				'qty'          => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
				'design_id'    => method_exists( $item, 'get_meta' ) ? $item->get_meta( 'design_id', true ) : '',
			);
		}
		return $lines;
	}

	/**
	 * @param mixed $product_id
	 * @param mixed $variation_id
	 * @return int
	 */
	private static function part_id( $product_id, $variation_id ) {
		$variation_id = (int) $variation_id;
		$product_id   = (int) $product_id;
		if ( self::is_part( $variation_id ) ) {
			return $variation_id;
		}
		if ( self::is_part( $product_id ) ) {
			return $product_id;
		}
		return 0;
	}

	/**
	 * @param int $id
	 * @return bool
	 */
	private static function is_part( $id ) {
		return in_array( (int) $id, array( self::BB, self::SS, self::DROPOUTS ), true );
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function design_id( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( is_bool( $value ) || is_object( $value ) || null === $value ) {
			return '';
		}
		return trim( (string) $value );
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 * @return bool
	 */
	private static function money_matches( $left, $right ) {
		return round( (float) $left, 2 ) === round( (float) $right, 2 );
	}
}

if ( defined( 'ABSPATH' ) ) {
	add_action( 'plugins_loaded', array( 'Creature_Rear_End_Discount', 'boot' ), 20 );
}
