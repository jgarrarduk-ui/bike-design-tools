<?php
/**
 * Plugin Name: Creature Cycles Frame Designer Order Experience
 * Description: Customer-facing copy for Frame Designer orders. Replaces the order-pay guest warning on those orders, hides internal line meta from customers, prints the delivery lead time, and requires the straight-away cancellation waiver before payment. Does not change prices, totals, or order creation.
 * Version: 1.1.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/ (not into a subfolder), next to
 * creature-fd-only-purchase.php. WooCommerce must already be active.
 *
 * Why this is a sibling of the catalogue guard
 * --------------------------------------------
 * creature-fd-only-purchase.php decides whether a yoke or dropout can be
 * added to the basket. This file only changes what a customer reads after
 * an order exists, plus the lead-time sentence the purchase plugin prints
 * under the product-page button. Keeping them apart means this display
 * layer never hooks cart validation, fees, or order totals, and the basket
 * guard can stay installed if this file is removed.
 *
 * Order-pay guest notice
 * ----------------------
 * Woo prints an error, "You are paying for a guest order…", when a logged-in
 * customer pays a guest order whose billing email is not theirs. For an
 * order that has an 8634/8635/8636 line with design_id, that error is
 * rewritten as an info notice while the pay page renders. Every other guest
 * order keeps Woo's error. Email verification, the pay_for_order capability,
 * and the order-key check are not filtered.
 *
 * Lead time
 * ---------
 * Filter creature_fd_lead_time (default "5 working days"). The tools-api
 * cannot read this constant. Its FD_LEAD_TIME env (or legacy
 * REVIEW_LEAD_TIME_DAYS) must be kept in step by hand.
 *
 * A later "Request a change" link can call is_fd_order() and
 * design_id_from_order() from the customer email hook already used here.
 * That flow is not built: it still needs a short-lived resume-style token
 * and a revision saved against the same Woo order.
 *
 * Cancellation waiver
 * ------------------
 * On order-pay, a Frame Designer order shows a separate required checkbox.
 * Filter creature_fd_cancellation_waiver_text to follow the final T&Cs.
 * Woo's own terms checkbox is left as it is. Payment is refused until the
 * box is ticked: the classic pay form (WooPayments card, and a normal Pay
 * for order submit), PayPal's pay-now create-order call, and the Store API
 * checkout used by WooPayments express buttons. Express buttons on that
 * page do not post the checkbox, so they are hidden and the Store API
 * rejects them. Totals are not recalculated.
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Customer-facing copy and the order-pay cancellation waiver.
 */
final class Creature_Fd_Order_Experience {

	const LEAD_TIME = '5 working days';

	const GUEST_NOTICE_NEEDLE = 'paying for a guest order';

	const WAIVER_TEXT = 'I want my design files made and supplied straight away, and I understand I lose my 14-day right to cancel once work starts.';

	const WAIVER_VERSION = '1';

	const WAIVER_EMAIL = 'You asked us to start straight away and acknowledged that the 14-day right to cancel ends once work starts.';

	const WAIVER_ERROR = 'Please tick the box to confirm you want your design files made and supplied straight away. Payment has not been taken.';

	const WAIVER_FIELD = 'creature_fd_cancellation_waiver';

	const WAIVER_META = '_creature_fd_cancellation_waiver';

	const WAIVER_AT_META = '_creature_fd_cancellation_waiver_at';

	const WAIVER_TEXT_META = '_creature_fd_cancellation_waiver_text';

	const WAIVER_VERSION_META = '_creature_fd_cancellation_waiver_version';

	/** @var bool */
	private static $booted = false;

	/** @var bool */
	private static $guest_filters_on = false;

	/** @var bool Set for the single error notice we are turning into info. */
	private static $render_as_info = false;

	/**
	 * null outside an email. True when the email is the shop copy.
	 *
	 * @var bool|null
	 */
	private static $email_to_admin = null;

	public static function boot() {
		if ( self::$booted || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$booted = true;

		add_action( 'before_woocommerce_pay', array( __CLASS__, 'on_before_pay' ), 1 );
		add_action( 'before_woocommerce_pay_form', array( __CLASS__, 'disarm_guest_notice' ), 1 );
		add_action( 'after_woocommerce_pay', array( __CLASS__, 'disarm_guest_notice' ), 1 );
		add_action( 'shutdown', array( __CLASS__, 'disarm_guest_notice' ), 0 );

		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'filter_formatted_meta' ), 10, 2 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'on_email_details_start' ), 1, 4 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'on_email_details_end' ), 999, 4 );

		add_action( 'woocommerce_thankyou', array( __CLASS__, 'on_thankyou' ), 5, 1 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'on_email_before_order_table' ), 10, 4 );

		add_action( 'before_woocommerce_pay_form', array( __CLASS__, 'on_before_pay_form_express' ), 2, 1 );
		add_action( 'woocommerce_pay_order_before_submit', array( __CLASS__, 'on_pay_order_before_submit' ) );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'on_before_pay_action' ), 5, 1 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( __CLASS__, 'on_validate_before_payment' ), 10, 2 );
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( __CLASS__, 'on_paypal_create_order' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'on_admin_order_waiver' ), 10, 1 );
	}

	/**
	 * Catalogue ids. Uses the purchase plugin's list when that file is loaded
	 * so both plugins share creature_fd_only_product_ids.
	 *
	 * @return int[]
	 */
	public static function product_ids() {
		if ( class_exists( 'Creature_Fd_Only_Purchase' ) ) {
			return Creature_Fd_Only_Purchase::product_ids();
		}
		$ids = apply_filters( 'creature_fd_only_product_ids', array( 8634, 8635, 8636 ) );
		return self::normalize_ids( $ids );
	}

	/**
	 * @return string
	 */
	public static function lead_time() {
		$value = apply_filters( 'creature_fd_lead_time', self::LEAD_TIME );
		if ( ! is_string( $value ) ) {
			return self::LEAD_TIME;
		}
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		if ( '' === $value || strlen( $value ) > 80 ) {
			return self::LEAD_TIME;
		}
		return $value;
	}

	/**
	 * Sentence used on the thank-you page, customer emails, the product
	 * button, and the save-design email (the tools-api copy must match).
	 *
	 * @return string
	 */
	public static function delivery_sentence() {
		return 'Design files are delivered within ' . self::lead_time() . ' of payment.';
	}

	/**
	 * Info notice that replaces Woo's guest-order error on a qualifying pay page.
	 *
	 * @return string
	 */
	public static function pay_notice() {
		return 'Your Frame Designer order. ' . self::delivery_sentence();
	}

	/**
	 * Keys tools-api writes on a line that customers should not see.
	 * geometry_summary is not in this list. Underscore-prefixed keys are
	 * already hidden by Woo and are ignored here.
	 *
	 * @return string[]
	 */
	public static function hidden_customer_keys() {
		$keys = apply_filters(
			'creature_fd_hidden_customer_meta_keys',
			array( 'design_id', 'creature_design_id' )
		);
		if ( ! is_array( $keys ) ) {
			return array( 'design_id', 'creature_design_id' );
		}
		$out = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( '_' === substr( $key, 0, 1 ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}

	/**
	 * True when a gated product line on the order carries a design id.
	 * Order-level meta alone does not qualify. Public so a later
	 * request-a-change email can reuse the same check.
	 *
	 * @param mixed $order
	 * @return bool
	 */
	public static function is_fd_order( $order ) {
		if ( ! self::is_order( $order ) ) {
			return false;
		}
		foreach ( self::order_items( $order ) as $item ) {
			if ( self::item_is_fd_product( $item ) && '' !== self::item_design_id( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Design id for a later resume link. Prefers a gated line, then any line,
	 * then order meta design_id / creature_design_id. Does not mint a token.
	 *
	 * @param mixed $order
	 * @return string
	 */
	public static function design_id_from_order( $order ) {
		if ( ! self::is_order( $order ) ) {
			return '';
		}
		$fallback = '';
		foreach ( self::order_items( $order ) as $item ) {
			$design_id = self::item_design_id( $item );
			if ( '' === $design_id ) {
				continue;
			}
			if ( self::item_is_fd_product( $item ) ) {
				return $design_id;
			}
			if ( '' === $fallback ) {
				$fallback = $design_id;
			}
		}
		if ( '' !== $fallback ) {
			return $fallback;
		}
		return self::order_design_id( $order );
	}

	/**
	 * design_id, then creature_design_id, on one line.
	 *
	 * @param mixed $item
	 * @return string
	 */
	public static function item_design_id( $item ) {
		foreach ( array( 'design_id', 'creature_design_id' ) as $key ) {
			$value = self::item_meta( $item, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Arm the guest-notice swap only for this pay-page render, and only when
	 * the order key matches a Frame Designer order. Other orders never get
	 * the filters.
	 */
	public static function on_before_pay() {
		self::disarm_guest_notice();
		if ( ! self::is_fd_order( self::order_from_pay_request() ) ) {
			return;
		}
		self::$guest_filters_on = true;
		add_filter( 'woocommerce_add_error', array( __CLASS__, 'filter_guest_error' ), 100, 1 );
		add_filter( 'wc_get_template', array( __CLASS__, 'filter_guest_notice_template' ), 100, 5 );
	}

	/**
	 * @param mixed $message
	 * @return mixed
	 */
	public static function filter_guest_error( $message ) {
		if ( ! self::$guest_filters_on || ! self::is_guest_pay_message( $message ) ) {
			return $message;
		}
		self::$render_as_info = true;
		return self::pay_notice();
	}

	/**
	 * Woo has already put the replacement sentence in the error template args.
	 * Point that one include at the info template, then drop both filters so
	 * a later stock or permission error on the same request stays an error.
	 *
	 * @param mixed  $located
	 * @param string $template_name
	 * @param mixed  $args
	 * @return mixed
	 */
	public static function filter_guest_notice_template( $located, $template_name = '', $args = array() ) {
		if ( 'notices/error.php' !== $template_name || ! self::$render_as_info ) {
			return $located;
		}
		$notice = '';
		if ( is_array( $args ) && isset( $args['notices'][0]['notice'] ) ) {
			$notice = (string) $args['notices'][0]['notice'];
		}
		if ( $notice !== self::pay_notice() ) {
			return $located;
		}
		self::$render_as_info = false;
		$path = '';
		if ( function_exists( 'wc_locate_template' ) ) {
			$located_info = wc_locate_template( 'notices/notice.php' );
			// A missing file would make Woo skip the notice. Keep the error
			// template in that case; the sentence is already the info copy.
			if ( is_string( $located_info ) && '' !== $located_info && file_exists( $located_info ) ) {
				$path = $located_info;
			}
		}
		self::disarm_guest_notice();
		if ( '' !== $path ) {
			return $path;
		}
		return $located;
	}

	public static function disarm_guest_notice() {
		if ( self::$guest_filters_on ) {
			remove_filter( 'woocommerce_add_error', array( __CLASS__, 'filter_guest_error' ), 100 );
			remove_filter( 'wc_get_template', array( __CLASS__, 'filter_guest_notice_template' ), 100 );
		}
		self::$guest_filters_on = false;
		self::$render_as_info   = false;
	}

	/**
	 * @param mixed $order_id Order id, or an order object on some templates.
	 */
	public static function on_thankyou( $order_id ) {
		$order = self::order_from_thankyou_arg( $order_id );
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		self::print_info( self::delivery_sentence() );
	}

	/**
	 * Customer processing, on-hold, and completed emails for FD orders.
	 * Admin copies and every other email are left alone.
	 *
	 * @param mixed  $order
	 * @param bool   $sent_to_admin
	 * @param bool   $plain_text
	 * @param object $email
	 */
	public static function on_email_before_order_table( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! self::is_fd_order( $order ) ) {
			return;
		}
		$id = ( is_object( $email ) && isset( $email->id ) ) ? (string) $email->id : '';
		if ( ! in_array( $id, self::customer_lead_email_ids(), true ) ) {
			return;
		}
		$lines = array( self::delivery_sentence() );
		if ( 'customer_processing_order' === $id && self::order_has_waiver( $order ) ) {
			$lines[] = self::waiver_email_line();
		}
		if ( $plain_text ) {
			echo "\n" . implode( "\n", $lines ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, no markup.
			return;
		}
		echo '<p class="creature-fd-lead-time">' . self::esc( $lines[0] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		if ( isset( $lines[1] ) ) {
			echo '<p class="creature-fd-cancellation-waiver">' . self::esc( $lines[1] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		}
	}

	/**
	 * Wording from James's draft T&Cs (page 8735). Filter to follow a later draft.
	 *
	 * @return string
	 */
	public static function waiver_text() {
		$value = apply_filters( 'creature_fd_cancellation_waiver_text', self::WAIVER_TEXT );
		if ( ! is_string( $value ) ) {
			return self::WAIVER_TEXT;
		}
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		if ( '' === $value || strlen( $value ) > 400 ) {
			return self::WAIVER_TEXT;
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function waiver_version() {
		$value = apply_filters( 'creature_fd_cancellation_waiver_version', self::WAIVER_VERSION );
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return self::WAIVER_VERSION;
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 40 ) {
			return self::WAIVER_VERSION;
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function waiver_email_line() {
		$value = apply_filters( 'creature_fd_cancellation_waiver_email_line', self::WAIVER_EMAIL );
		if ( ! is_string( $value ) ) {
			return self::WAIVER_EMAIL;
		}
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );
		if ( '' === $value || strlen( $value ) > 400 ) {
			return self::WAIVER_EMAIL;
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function waiver_error() {
		return self::WAIVER_ERROR;
	}

	/**
	 * Hide wallet buttons that pay through the Store API and never post this checkbox.
	 * The PayPal payment method stays; its create-order call is checked separately.
	 *
	 * @param mixed $order
	 */
	public static function on_before_pay_form_express( $order ) {
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		echo '<style>.wcpay-express-checkout-wrapper,#wcpay-express-checkout-button-separator{display:none!important}</style>';
		echo '<script>(function(){function hide(){var nodes=document.querySelectorAll(".wcpay-express-checkout-wrapper,#wcpay-express-checkout-button-separator");for(var i=0;i<nodes.length;i++){nodes[i].remove();}}hide();document.body&&document.body.addEventListener("updated_checkout",hide);})();</script>';
	}

	/**
	 * Checkbox inside the pay form, separate from Woo's terms box.
	 */
	public static function on_pay_order_before_submit() {
		$order = self::order_from_pay_request();
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		$text = self::waiver_text();
		echo '<p class="form-row creature-fd-cancellation-waiver">';
		echo '<label for="creature-fd-cancellation-waiver">';
		echo '<input type="checkbox" name="' . self::esc( self::WAIVER_FIELD ) . '" id="creature-fd-cancellation-waiver" value="1" required="required" aria-required="true" /> ';
		echo '<span>' . self::esc( $text ) . '</span>';
		echo '</label></p>';
	}

	/**
	 * Classic order-pay submit, including WooPayments card fields that post the form.
	 * An error notice here stops WC_Form_Handler::pay_action before process_payment.
	 *
	 * @param mixed $order
	 */
	public static function on_before_pay_action( $order ) {
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		if ( ! self::posted_waiver() ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( self::waiver_error(), 'error' );
			}
			return;
		}
		self::record_waiver( $order );
	}

	/**
	 * Store API checkout of an existing order (WooPayments Apple Pay / Google Pay on order-pay).
	 * Regular cart checkout uses the same hook and is left alone.
	 *
	 * @param mixed $order
	 * @param mixed $errors
	 */
	public static function on_validate_before_payment( $order, $errors ) {
		if ( ! self::is_store_api_existing_order_payment() || ! self::is_fd_order( $order ) ) {
			return;
		}
		if ( self::posted_waiver() ) {
			self::record_waiver( $order );
			return;
		}
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'creature_fd_cancellation_waiver', self::waiver_error() );
		}
	}

	/**
	 * PayPal pay-now creates the PayPal order, then captures it, without pay_action.
	 * The button sends the pay form fields. Missing consent throws before that order exists.
	 *
	 * @param mixed $data
	 * @throws RuntimeException When a Frame Designer pay-now request has no waiver.
	 */
	public static function on_paypal_create_order( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['context'] ) || 'pay-now' !== $data['context'] ) {
			return;
		}
		$order_id = isset( $data['order_id'] ) ? $data['order_id'] : 0;
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		if ( self::form_has_waiver( isset( $data['form'] ) ? $data['form'] : null ) ) {
			self::record_waiver( $order );
			return;
		}
		throw new RuntimeException( self::waiver_error() );
	}

	/**
	 * @param mixed $order
	 */
	public static function on_admin_order_waiver( $order ) {
		if ( ! self::order_has_waiver( $order ) || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$text = (string) $order->get_meta( self::WAIVER_TEXT_META, true );
		$at   = (string) $order->get_meta( self::WAIVER_AT_META, true );
		echo '<p class="creature-fd-cancellation-waiver-admin"><strong>Cancellation waiver:</strong> ';
		echo self::esc( $text ) . ' <span class="creature-fd-cancellation-waiver-at">' . self::esc( $at ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
	}

	/**
	 * @param mixed $order
	 */
	public static function record_waiver( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$already = self::order_has_waiver( $order );
		$text    = self::waiver_text();
		$at      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$order->update_meta_data( self::WAIVER_META, '1' );
		$order->update_meta_data( self::WAIVER_AT_META, $at );
		$order->update_meta_data( self::WAIVER_TEXT_META, $text );
		$order->update_meta_data( self::WAIVER_VERSION_META, self::waiver_version() );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		if ( ! $already && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'Customer agreed to start straight away: ' . $text . ' (' . $at . ').' );
		}
	}

	/**
	 * @param mixed $order
	 * @return bool
	 */
	public static function order_has_waiver( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		return '1' === (string) $order->get_meta( self::WAIVER_META, true );
	}

	/**
	 * @return bool
	 */
	public static function posted_waiver() {
		if ( ! isset( $_POST[ self::WAIVER_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		$value = $_POST[ self::WAIVER_FIELD ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( function_exists( 'wp_unslash' ) ) {
			$value = wp_unslash( $value );
		}
		return self::waiver_value_is_yes( $value );
	}

	/**
	 * @param mixed $form
	 * @return bool
	 */
	public static function form_has_waiver( $form ) {
		if ( is_string( $form ) ) {
			parse_str( $form, $parsed );
			$form = $parsed;
		}
		if ( ! is_array( $form ) ) {
			return false;
		}
		if ( array_key_exists( self::WAIVER_FIELD, $form ) ) {
			return self::waiver_value_is_yes( $form[ self::WAIVER_FIELD ] );
		}
		foreach ( $form as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) ) {
				continue;
			}
			if ( self::WAIVER_FIELD === (string) $row['name'] && isset( $row['value'] ) && self::waiver_value_is_yes( $row['value'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private static function waiver_value_is_yes( $value ) {
		if ( is_array( $value ) ) {
			return false;
		}
		$flag = strtolower( trim( (string) $value ) );
		return in_array( $flag, array( '1', 'on', 'yes', 'true' ), true );
	}

	/**
	 * /wc/store/v1/checkout/123 is pay-for-order. /wc/store/v1/checkout is the cart.
	 *
	 * @return bool
	 */
	private static function is_store_api_existing_order_payment() {
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}
		return (bool) preg_match( '#/wc/store(?:/v\d+)?/checkout/\d+#', $route );
	}

	/**
	 * @param mixed $formatted_meta
	 * @param mixed $item
	 * @return mixed
	 */
	public static function filter_formatted_meta( $formatted_meta, $item = null ) {
		unset( $item );
		if ( ! is_array( $formatted_meta ) || self::show_internal_meta() ) {
			return $formatted_meta;
		}
		$hidden = self::hidden_customer_keys();
		foreach ( $formatted_meta as $id => $meta ) {
			$key = self::meta_key( $meta );
			if ( in_array( $key, $hidden, true ) ) {
				unset( $formatted_meta[ $id ] );
				continue;
			}
			if ( 'geometry_summary' === $key ) {
				self::set_display_key( $formatted_meta[ $id ], 'Geometry' );
			}
		}
		return $formatted_meta;
	}

	/**
	 * Item meta is rendered inside this action, after priority 1 and before 999.
	 *
	 * @param mixed $order
	 * @param bool  $sent_to_admin
	 */
	public static function on_email_details_start( $order = null, $sent_to_admin = false ) {
		unset( $order );
		self::$email_to_admin = (bool) $sent_to_admin;
	}

	public static function on_email_details_end() {
		self::$email_to_admin = null;
	}

	/**
	 * @return string[]
	 */
	public static function customer_lead_email_ids() {
		return array(
			'customer_processing_order',
			'customer_on_hold_order',
			'customer_completed_order',
		);
	}

	/**
	 * @param mixed $message
	 * @return bool
	 */
	public static function is_guest_pay_message( $message ) {
		$text = self::plain_notice( $message );
		if ( '' === $text ) {
			return false;
		}
		return false !== stripos( $text, self::GUEST_NOTICE_NEEDLE );
	}

	/**
	 * @param mixed $order
	 * @return bool
	 */
	private static function is_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return false;
		}
		if ( class_exists( 'WC_Order' ) ) {
			return $order instanceof WC_Order;
		}
		return true;
	}

	/**
	 * @param mixed $order
	 * @return array
	 */
	private static function order_items( $order ) {
		$items = $order->get_items();
		return is_array( $items ) ? $items : array();
	}

	/**
	 * @param mixed $item
	 * @return bool
	 */
	private static function item_is_fd_product( $item ) {
		$ids = self::product_ids();
		foreach ( self::item_product_ids( $item ) as $id ) {
			if ( in_array( $id, $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $item
	 * @return int[]
	 */
	private static function item_product_ids( $item ) {
		$ids = array();
		if ( ! is_object( $item ) ) {
			return $ids;
		}
		if ( method_exists( $item, 'get_product_id' ) ) {
			$ids[] = (int) $item->get_product_id();
		}
		if ( method_exists( $item, 'get_variation_id' ) ) {
			$ids[] = (int) $item->get_variation_id();
		}
		return $ids;
	}

	/**
	 * @param mixed  $item
	 * @param string $key
	 * @return string
	 */
	private static function item_meta( $item, $key ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return '';
		}
		$value = $item->get_meta( $key, true );
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( (string) $value );
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function order_design_id( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		foreach ( array( 'design_id', 'creature_design_id' ) as $key ) {
			$value = $order->get_meta( $key, true );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	/**
	 * @return object|null
	 */
	private static function order_from_pay_request() {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) ) {
			return null;
		}
		$order_id = function_exists( 'get_query_var' ) ? get_query_var( 'order-pay' ) : 0;
		$order_id = self::abs_id( $order_id );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! self::is_order( $order ) ) {
			return null;
		}
		if ( ! isset( $_GET['key'] ) || ! is_string( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}
		$key = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['key'] ) : $_GET['key']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = method_exists( $order, 'get_order_key' ) ? (string) $order->get_order_key() : '';
		if ( ! is_string( $key ) || '' === $key || '' === $order_key || ! hash_equals( $order_key, $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * @param mixed $order_id
	 * @return object|null
	 */
	private static function order_from_thankyou_arg( $order_id ) {
		if ( self::is_order( $order_id ) ) {
			return $order_id;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		return self::is_order( $order ) ? $order : null;
	}

	/**
	 * Staff see internal keys in wp-admin and on emails sent to the shop.
	 * A customer email rendered inside wp-admin (status change) is not staff.
	 *
	 * @return bool
	 */
	private static function show_internal_meta() {
		if ( null !== self::$email_to_admin ) {
			return self::$email_to_admin;
		}
		return function_exists( 'is_admin' ) && is_admin();
	}

	/**
	 * @param mixed $meta
	 * @return string
	 */
	private static function meta_key( $meta ) {
		if ( is_object( $meta ) && isset( $meta->key ) ) {
			return (string) $meta->key;
		}
		if ( is_array( $meta ) && isset( $meta['key'] ) ) {
			return (string) $meta['key'];
		}
		return '';
	}

	/**
	 * @param mixed  $meta
	 * @param string $label
	 */
	private static function set_display_key( &$meta, $label ) {
		if ( is_object( $meta ) ) {
			$meta->display_key = $label;
			return;
		}
		if ( is_array( $meta ) ) {
			$meta['display_key'] = $label;
		}
	}

	/**
	 * @param mixed $message
	 * @return string
	 */
	private static function plain_notice( $message ) {
		$text = (string) $message;
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$text = wp_strip_all_tags( $text );
		} else {
			$text = strip_tags( $text );
		}
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * @param string $message
	 */
	private static function print_info( $message ) {
		if ( function_exists( 'wc_print_notice' ) ) {
			wc_print_notice( $message, 'notice' );
			return;
		}
		echo '<div class="woocommerce-info creature-fd-lead">' . self::esc( $message ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
	}

	/**
	 * @param mixed $value
	 * @return int
	 */
	private static function abs_id( $value ) {
		if ( function_exists( 'absint' ) ) {
			return (int) absint( $value );
		}
		return abs( (int) $value );
	}

	/**
	 * @param mixed $ids
	 * @return int[]
	 */
	private static function normalize_ids( $ids ) {
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$out[ $id ] = $id;
			}
		}
		return array_values( $out );
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function esc( $text ) {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

add_action( 'plugins_loaded', array( 'Creature_Fd_Order_Experience', 'boot' ), 20 );
