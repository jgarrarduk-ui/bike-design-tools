<?php
/**
 * Plugin Name: Creature Cycles Frame Designer Order Experience
 * Description: Customer-facing copy for Frame Designer orders. Replaces the order-pay guest warning on those orders, hides internal line meta from customers, prints the delivery lead time, requires the design-file terms and the straight-away cancellation waiver before payment, and adds the paid-order change link. Does not change prices, totals, or order creation.
 * Version: 1.3.0
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
 * Request a change
 * ----------------
 * Processing and on-hold emails for a Frame Designer order include a link
 * while the window is open. The token is minted here, on the order, because
 * this email is sent at payment and the window follows the paid date and the
 * In design status. Filter creature_fd_change_window_hours (default 24).
 * The tools-api stores the geometry revisions and emails
 * info@creaturecycles.co.uk. This file updates geometry_summary and an order
 * note on the same order. It does not create an order or take payment.
 *
 * Cancellation waiver
 * ------------------
 * On order-pay, a Frame Designer order shows two required checkboxes. The
 * first agrees to the Terms & Conditions, the Design File Licence, and the
 * Required Build Specification. Each of those titles is a link (/terms/,
 * /design-file-licence/, /build-specification/), opened in a new tab. The
 * links render while those pages are still drafts. Woo's own terms checkbox
 * is hidden on that page so the customer sees one terms box. The
 * cancellation waiver stays a separate box underneath. Filter
 * creature_fd_cancellation_waiver_text to follow the final T&Cs. Payment
 * is refused until both are ticked: the
 * classic pay form (WooPayments card, and a normal Pay for order submit),
 * PayPal's pay-now create-order call, and the Store API checkout used by
 * WooPayments express buttons. Express buttons on that page do not post the
 * checkboxes, so they are hidden and the Store API rejects them. Totals
 * are not recalculated.
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

	const TERMS_WORDING = 'I agree to the Terms & Conditions, the Design File Licence and the Required Build Specification supplied with my design files.';

	const TERMS_ERROR = 'Please tick the box to agree to the Terms & Conditions, the Design File Licence and the Required Build Specification. Payment has not been taken.';

	const TERMS_EMAIL = 'You agreed to our Terms & Conditions, Design File Licence and Required Build Specification.';

	const TERMS_FIELD = 'creature_fd_terms_accepted';

	const TERMS_META = '_creature_fd_terms_accepted';

	const TERMS_AT_META = '_creature_fd_terms_accepted_at';

	const TERMS_WORDING_META = '_creature_fd_terms_wording';

	const TERMS_VERSIONS_META = '_creature_fd_terms_versions';

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
		add_filter( 'woocommerce_order_get_customer_note', array( __CLASS__, 'filter_customer_note' ), 10, 2 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'on_email_details_start' ), 1, 4 );
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'on_email_details_end' ), 999, 4 );

		add_action( 'woocommerce_thankyou', array( __CLASS__, 'on_thankyou' ), 5, 1 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'on_email_before_order_table' ), 10, 4 );

		add_action( 'before_woocommerce_pay_form', array( __CLASS__, 'on_before_pay_form_express' ), 2, 1 );
		add_filter( 'woocommerce_checkout_show_terms', array( __CLASS__, 'filter_checkout_show_terms' ) );
		add_action( 'woocommerce_pay_order_before_submit', array( __CLASS__, 'on_pay_order_before_submit' ) );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'on_before_pay_action' ), 5, 1 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( __CLASS__, 'on_validate_before_payment' ), 10, 2 );
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( __CLASS__, 'on_paypal_create_order' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'on_admin_order_waiver' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'on_admin_order_terms' ), 11, 1 );

		add_action( 'init', array( __CLASS__, 'register_in_design_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'filter_order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'filter_paid_statuses' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'filter_bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'filter_bulk_actions' ) );
		add_action( 'woocommerce_order_status_in-design', array( __CLASS__, 'on_marked_in_design' ), 10, 1 );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'on_save_change_flag' ), 20, 1 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'on_admin_order_change' ), 12, 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_change_routes' ) );
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
		$waiver_line = '';
		if ( 'customer_processing_order' === $id && self::order_has_waiver( $order ) ) {
			$waiver_line = self::waiver_email_line();
			$lines[]     = $waiver_line;
		}
		$terms_line = '';
		if ( 'customer_processing_order' === $id && self::order_has_terms( $order ) ) {
			$terms_line = self::TERMS_EMAIL;
			$lines[]    = $terms_line;
		}
		$change_url = '';
		if ( in_array( $id, array( 'customer_processing_order', 'customer_on_hold_order' ), true ) ) {
			$change_url = self::change_email_url( $order );
		}
		if ( '' !== $change_url ) {
			$lines[] = 'Request a change: ' . $change_url;
		}
		if ( $plain_text ) {
			echo "\n" . implode( "\n", $lines ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, no markup.
			return;
		}
		echo '<p class="creature-fd-lead-time">' . self::esc( $lines[0] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		if ( '' !== $waiver_line ) {
			echo '<p class="creature-fd-cancellation-waiver">' . self::esc( $waiver_line ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		}
		if ( '' !== $terms_line ) {
			echo '<p class="creature-fd-terms-accepted">' . self::esc( $terms_line ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		}
		if ( '' !== $change_url ) {
			echo '<p class="creature-fd-change-link"><a href="' . self::esc( $change_url ) . '">Request a change</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
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
	 * @return string
	 */
	public static function terms_error() {
		return self::TERMS_ERROR;
	}

	/**
	 * Plain label stored on the order. The checkbox HTML links the first two documents.
	 *
	 * @return string
	 */
	public static function terms_wording() {
		return self::TERMS_WORDING;
	}

	/**
	 * @return string
	 */
	public static function terms_label_html() {
		$terms   = self::document_link( self::terms_document_url(), 'Terms & Conditions' );
		$licence = self::document_link( self::licence_document_url(), 'Design File Licence' );
		$spec    = self::document_link( self::build_spec_document_url(), 'Required Build Specification' );
		return 'I agree to the ' . $terms . ', the ' . $licence . ' and the ' . $spec . ' supplied with my design files.';
	}

	/**
	 * @return string
	 */
	public static function terms_document_url() {
		return self::document_url( 'creature_fd_terms_url', '/terms/' );
	}

	/**
	 * @return string
	 */
	public static function licence_document_url() {
		return self::document_url( 'creature_fd_design_file_licence_url', '/design-file-licence/' );
	}

	/**
	 * @return string
	 */
	public static function build_spec_document_url() {
		return self::document_url( 'creature_fd_build_spec_url', '/build-specification/' );
	}

	/**
	 * @return array{terms: string, design_file_licence: string, build_spec: string}
	 */
	public static function terms_versions() {
		return array(
			'terms'                => self::document_version( 'creature_fd_terms_version' ),
			'design_file_licence'  => self::document_version( 'creature_fd_design_file_licence_version' ),
			'build_spec'           => self::document_version( 'creature_fd_build_spec_version' ),
		);
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
	 * Hide Woo's terms block on a Frame Designer order-pay page. The pay form
	 * prints that block only when this filter stays true. Checkout and every
	 * other order keep the value Woo passed in.
	 *
	 * @param mixed $show
	 * @return mixed
	 */
	public static function filter_checkout_show_terms( $show ) {
		if ( self::is_fd_order( self::order_from_pay_request() ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Terms checkbox, then the cancellation waiver. Woo's terms box is not printed.
	 */
	public static function on_pay_order_before_submit() {
		$order = self::order_from_pay_request();
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		echo '<p class="form-row creature-fd-terms validate-required">';
		echo '<label for="creature-fd-terms-accepted">';
		echo '<input type="checkbox" name="' . self::esc( self::TERMS_FIELD ) . '" id="creature-fd-terms-accepted" value="1" required="required" aria-required="true" /> ';
		echo '<span>' . self::terms_label_html() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- links are escaped in terms_label_html.
		echo '</label></p>';

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
		if ( ! self::posted_terms() ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( self::terms_error(), 'error' );
			}
			return;
		}
		self::record_waiver( $order );
		self::record_terms( $order );
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
		$waiver = self::posted_waiver();
		$terms  = self::posted_terms();
		if ( $waiver && $terms ) {
			self::record_waiver( $order );
			self::record_terms( $order );
			return;
		}
		if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
			return;
		}
		if ( ! $waiver ) {
			$errors->add( 'creature_fd_cancellation_waiver', self::waiver_error() );
		}
		if ( ! $terms ) {
			$errors->add( 'creature_fd_terms_accepted', self::terms_error() );
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
		$form = isset( $data['form'] ) ? $data['form'] : null;
		if ( ! self::form_has_waiver( $form ) ) {
			throw new RuntimeException( self::waiver_error() );
		}
		if ( ! self::form_has_terms( $form ) ) {
			throw new RuntimeException( self::terms_error() );
		}
		self::record_waiver( $order );
		self::record_terms( $order );
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
	 * @param mixed $order
	 */
	public static function record_terms( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$already  = self::order_has_terms( $order );
		$versions = self::terms_versions();
		$encoded  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $versions ) : json_encode( $versions );
		$at       = gmdate( 'Y-m-d\TH:i:s\Z' );
		$order->update_meta_data( self::TERMS_META, '1' );
		$order->update_meta_data( self::TERMS_AT_META, $at );
		$order->update_meta_data( self::TERMS_WORDING_META, self::terms_wording() );
		$order->update_meta_data( self::TERMS_VERSIONS_META, $encoded );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		if ( ! $already && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'Customer agreed to the Terms & Conditions, Design File Licence and Required Build Specification (' . self::terms_versions_text( $versions ) . ') at ' . $at . '.' );
		}
	}

	/**
	 * @param mixed $order
	 * @return bool
	 */
	public static function order_has_terms( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		return '1' === (string) $order->get_meta( self::TERMS_META, true );
	}

	/**
	 * @param mixed $order
	 */
	public static function on_admin_order_terms( $order ) {
		if ( ! self::order_has_terms( $order ) || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$at       = (string) $order->get_meta( self::TERMS_AT_META, true );
		$versions = self::terms_versions_from_meta( $order->get_meta( self::TERMS_VERSIONS_META, true ) );
		echo '<p class="creature-fd-terms-admin"><strong>Terms accepted:</strong> ';
		echo self::esc( $at ) . ' <span class="creature-fd-terms-versions">' . self::esc( self::terms_versions_text( $versions ) ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
	}

	/**
	 * @return bool
	 */
	public static function posted_terms() {
		return self::posted_flag( self::TERMS_FIELD );
	}

	/**
	 * @return bool
	 */
	public static function posted_waiver() {
		return self::posted_flag( self::WAIVER_FIELD );
	}

	/**
	 * @param string $field
	 * @return bool
	 */
	private static function posted_flag( $field ) {
		if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		$value = $_POST[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
		return self::form_has_field( $form, self::WAIVER_FIELD );
	}

	/**
	 * @param mixed $form
	 * @return bool
	 */
	public static function form_has_terms( $form ) {
		return self::form_has_field( $form, self::TERMS_FIELD );
	}

	/**
	 * @param mixed  $form
	 * @param string $field
	 * @return bool
	 */
	private static function form_has_field( $form, $field ) {
		if ( is_string( $form ) ) {
			parse_str( $form, $parsed );
			$form = $parsed;
		}
		if ( ! is_array( $form ) ) {
			return false;
		}
		if ( array_key_exists( $field, $form ) ) {
			return self::waiver_value_is_yes( $form[ $field ] );
		}
		foreach ( $form as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'] ) ) {
				continue;
			}
			if ( $field === (string) $row['name'] && isset( $row['value'] ) && self::waiver_value_is_yes( $row['value'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $filter
	 * @param string $path
	 * @return string
	 */
	private static function document_url( $filter, $path ) {
		$default = function_exists( 'home_url' ) ? home_url( $path ) : $path;
		$value   = apply_filters( $filter, $default );
		if ( ! is_string( $value ) ) {
			return $default;
		}
		$value = trim( $value );
		if ( preg_match( '#^https?://#i', $value ) && strlen( $value ) <= 300 ) {
			return $value;
		}
		if ( strlen( $value ) <= 300 && preg_match( '#^/[A-Za-z0-9._~:/?\#\[\]@!$&\'()*+,;=%-]*$#', $value ) ) {
			return $value;
		}
		return $default;
	}

	/**
	 * @param string $url
	 * @param string $label
	 * @return string
	 */
	private static function document_link( $url, $label ) {
		return '<a href="' . self::esc_attr( $url ) . '" target="_blank" rel="noopener">' . self::esc( $label ) . '</a>';
	}

	/**
	 * @param string $filter
	 * @return string
	 */
	private static function document_version( $filter ) {
		$value = apply_filters( $filter, '1' );
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '1';
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 40 ) {
			return '1';
		}
		return $value;
	}

	/**
	 * @param mixed $raw
	 * @return array
	 */
	private static function terms_versions_from_meta( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array $versions
	 * @return string
	 */
	private static function terms_versions_text( $versions ) {
		$terms   = isset( $versions['terms'] ) ? (string) $versions['terms'] : '1';
		$licence = isset( $versions['design_file_licence'] ) ? (string) $versions['design_file_licence'] : '1';
		$spec    = isset( $versions['build_spec'] ) ? (string) $versions['build_spec'] : '1';
		return 'terms ' . $terms . ', design file licence ' . $licence . ', build spec ' . $spec;
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function esc_attr( $text ) {
		if ( function_exists( 'esc_attr' ) ) {
			return esc_attr( $text );
		}
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
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
	/**
	 * Hide the tools-api design-id sentence from customers. A note the
	 * customer actually typed is left in place. Staff and shop emails keep
	 * the original text.
	 *
	 * @param mixed $note
	 * @param mixed $order
	 * @return mixed
	 */
	public static function filter_customer_note( $note, $order ) {
		if ( ! is_string( $note ) || '' === $note || self::show_internal_meta() || ! self::is_fd_order( $order ) ) {
			return $note;
		}
		$stripped = preg_replace(
			'/[ \t]*Bespoke bike design\s+[—–-]\s+ID:\s*[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\.?/u',
			'',
			$note
		);
		if ( ! is_string( $stripped ) ) {
			return $note;
		}
		$stripped = trim( (string) preg_replace( '/[ \t]{2,}/', ' ', $stripped ) );
		$stripped = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $stripped ) );
		return $stripped;
	}

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

	const CHANGE_WINDOW_HOURS = 24;

	const CHANGE_CLOSED_MESSAGE = 'The change window has closed. Reply to your confirmation email and we\'ll help.';

	const CHANGE_TOKEN_META = '_creature_fd_change_token';

	const CHANGE_PAID_AT_META = '_creature_fd_change_paid_at';

	const CHANGE_CLOSED_META = '_creature_fd_change_closed';

	const CHANGE_REVISION_META = '_creature_fd_change_revision';

	const CHANGE_IDEM_META = '_creature_fd_change_idem';

	const CHANGE_LAST_META = '_creature_fd_change_last_at';

	const CHANGE_RATE_SECONDS = 10;

	const DESIGNER_URL = 'https://creaturecycles.co.uk/apps/frame-designer.html';

	/**
	 * Custom status used when James starts the design. Registered for the
	 * admin list and bulk action. It is a paid status so stock is not restored
	 * when an order leaves processing. No customer email class is registered.
	 */
	public static function register_in_design_status() {
		if ( ! function_exists( 'register_post_status' ) ) {
			return;
		}
		$args = array(
			'label'                     => 'In design',
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		);
		if ( function_exists( '_n_noop' ) ) {
			$args['label_count'] = _n_noop(
				'In design <span class="count">(%s)</span>',
				'In design <span class="count">(%s)</span>'
			);
		}
		register_post_status( 'wc-in-design', $args );
	}

	/**
	 * @param mixed $statuses
	 * @return mixed
	 */
	public static function filter_order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		if ( isset( $statuses['wc-in-design'] ) ) {
			return $statuses;
		}
		$updated = array();
		foreach ( $statuses as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$updated['wc-in-design'] = 'In design';
			}
		}
		if ( ! isset( $updated['wc-in-design'] ) ) {
			$updated['wc-in-design'] = 'In design';
		}
		return $updated;
	}

	/**
	 * Keep stock reduced. Moving processing → In design must not restock.
	 *
	 * @param mixed $statuses
	 * @return mixed
	 */
	public static function filter_paid_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		if ( ! in_array( 'in-design', $statuses, true ) ) {
			$statuses[] = 'in-design';
		}
		return $statuses;
	}

	/**
	 * @param mixed $actions
	 * @return mixed
	 */
	public static function filter_bulk_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}
		$actions['mark_in-design'] = 'Change status to In design';
		return $actions;
	}

	/**
	 * @return int
	 */
	public static function change_window_hours() {
		$value = apply_filters( 'creature_fd_change_window_hours', self::CHANGE_WINDOW_HOURS );
		$hours = is_numeric( $value ) ? (int) $value : self::CHANGE_WINDOW_HOURS;
		if ( $hours < 1 || $hours > 720 ) {
			return self::CHANGE_WINDOW_HOURS;
		}
		return $hours;
	}

	/**
	 * @param mixed $order
	 * @return bool
	 */
	public static function change_is_started( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		$status = preg_replace( '/^wc-/', '', $status );
		if ( 'in-design' === $status ) {
			return true;
		}
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		return '1' === (string) $order->get_meta( self::CHANGE_CLOSED_META, true );
	}

	/**
	 * @param mixed $order
	 * @return int Unix timestamp, or 0 when payment has not been stamped.
	 */
	public static function change_paid_timestamp( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return 0;
		}
		$stored = (string) $order->get_meta( self::CHANGE_PAID_AT_META, true );
		if ( '' !== $stored ) {
			$parsed = strtotime( $stored );
			return false === $parsed ? 0 : (int) $parsed;
		}
		if ( ! method_exists( $order, 'get_date_paid' ) ) {
			return 0;
		}
		$date = $order->get_date_paid();
		if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
			return (int) $date->getTimestamp();
		}
		return 0;
	}

	/**
	 * @param mixed $order
	 * @return string
	 */
	public static function change_window_ends_at( $order ) {
		$paid = self::change_paid_timestamp( $order );
		if ( ! $paid ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $paid + ( self::change_window_hours() * 3600 ) );
	}

	/**
	 * @param mixed $order
	 * @return bool
	 */
	public static function change_window_open( $order ) {
		if ( ! self::is_fd_order( $order ) || self::change_is_started( $order ) ) {
			return false;
		}
		$paid = self::change_paid_timestamp( $order );
		if ( ! $paid ) {
			return true;
		}
		$ends = $paid + ( self::change_window_hours() * 3600 );
		return time() < $ends;
	}

	/**
	 * @param mixed $order
	 * @return string
	 */
	public static function ensure_change_token( $order ) {
		if ( ! self::change_window_open( $order ) || ! method_exists( $order, 'get_meta' ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return '';
		}
		$existing = (string) $order->get_meta( self::CHANGE_TOKEN_META, true );
		if ( self::token_shape( $existing ) ) {
			return $existing;
		}
		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			unset( $e );
			return '';
		}
		$order->update_meta_data( self::CHANGE_TOKEN_META, $token );
		if ( '' === (string) $order->get_meta( self::CHANGE_PAID_AT_META, true ) ) {
			$paid = self::change_paid_timestamp( $order );
			$order->update_meta_data( self::CHANGE_PAID_AT_META, gmdate( 'Y-m-d\TH:i:s\Z', $paid ? $paid : time() ) );
		}
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		return $token;
	}

	/**
	 * @param mixed $order
	 * @return string
	 */
	public static function change_email_url( $order ) {
		if ( ! self::change_window_open( $order ) ) {
			return '';
		}
		$token  = self::ensure_change_token( $order );
		$design = self::design_id_from_order( $order );
		if ( '' === $token || '' === $design ) {
			return '';
		}
		$base = self::designer_url();
		$join = false === strpos( $base, '?' ) ? '?' : '&';
		return $base . $join . 'design=' . rawurlencode( $design ) . '&change=' . rawurlencode( $token );
	}

	/**
	 * @param mixed  $order
	 * @param string $token
	 * @param string $design_id
	 * @return array
	 */
	public static function verify_change_request( $order, $token, $design_id ) {
		$stored = ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) ? (string) $order->get_meta( self::CHANGE_TOKEN_META, true ) : '';
		$given  = is_string( $token ) ? $token : '';
		if ( ! self::is_fd_order( $order ) || ! self::token_shape( $stored ) || ! self::token_shape( $given ) || ! hash_equals( $stored, $given ) ) {
			return array( 'ok' => false, 'error' => 'invalid' );
		}
		if ( (string) $design_id !== self::design_id_from_order( $order ) ) {
			return array( 'ok' => false, 'error' => 'invalid' );
		}
		if ( ! self::change_window_open( $order ) ) {
			return array(
				'ok'      => false,
				'error'   => 'closed',
				'message' => self::CHANGE_CLOSED_MESSAGE,
			);
		}
		return array(
			'ok'               => true,
			'designId'         => self::design_id_from_order( $order ),
			'orderId'          => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
			'productIds'       => self::order_fd_product_ids( $order ),
			'geometrySummary'  => self::order_geometry( $order ),
			'revision'         => (int) $order->get_meta( self::CHANGE_REVISION_META, true ),
			'windowEnds'       => self::change_window_ends_at( $order ),
			'status'           => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
			'orderUrl'         => self::order_admin_url( $order ),
		);
	}

	/**
	 * Update geometry on this order. Product ids, totals, and payment stay put.
	 *
	 * @param mixed $order
	 * @param array $args
	 * @return array
	 */
	public static function apply_change_revision( $order, $args ) {
		$args    = is_array( $args ) ? $args : array();
		$token   = isset( $args['token'] ) ? $args['token'] : '';
		$design  = isset( $args['designId'] ) ? $args['designId'] : '';
		$check   = self::verify_change_request( $order, $token, $design );
		$total   = ( is_object( $order ) && method_exists( $order, 'get_total' ) ) ? $order->get_total() : '';
		if ( empty( $check['ok'] ) ) {
			$check['total'] = $total;
			return $check;
		}
		$wanted  = self::normalize_id_list( isset( $args['productIds'] ) ? $args['productIds'] : array() );
		$current = self::order_fd_product_ids( $order );
		if ( ! $wanted || $wanted !== $current ) {
			return array(
				'ok'      => false,
				'error'   => 'parts',
				'message' => 'Those parts can\'t be changed on this order.',
				'total'   => $total,
			);
		}
		$summary = isset( $args['geometrySummary'] ) ? trim( (string) $args['geometrySummary'] ) : '';
		$summary = trim( (string) preg_replace( '/\s+/', ' ', $summary ) );
		if ( '' === $summary || strlen( $summary ) > 500 ) {
			return array( 'ok' => false, 'error' => 'geometry', 'total' => $total );
		}
		$old      = self::order_geometry( $order );
		$revision = (int) $order->get_meta( self::CHANGE_REVISION_META, true );
		$key      = isset( $args['idempotencyKey'] ) ? (string) $args['idempotencyKey'] : '';
		if ( strlen( $key ) > 80 ) {
			$key = '';
		}
		$stored_key = (string) $order->get_meta( self::CHANGE_IDEM_META, true );
		if ( ( '' !== $key && $key === $stored_key ) || $summary === $old ) {
			return array(
				'ok'            => true,
				'unchanged'     => true,
				'designId'      => $check['designId'],
				'orderId'       => $check['orderId'],
				'orderUrl'      => $check['orderUrl'],
				'revision'      => $revision,
				'oldGeometry'   => $old,
				'newGeometry'   => $old,
				'productIds'    => $current,
				'total'         => $total,
			);
		}
		$last = (string) $order->get_meta( self::CHANGE_LAST_META, true );
		$last_ts = '' !== $last ? strtotime( $last ) : false;
		if ( false !== $last_ts && ( time() - (int) $last_ts ) < self::CHANGE_RATE_SECONDS ) {
			return array(
				'ok'      => false,
				'error'   => 'rate',
				'message' => 'Please wait a moment before saving another change.',
				'total'   => $total,
			);
		}
		self::write_geometry( $order, $summary );
		$revision++;
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$order->update_meta_data( self::CHANGE_REVISION_META, (string) $revision );
		$order->update_meta_data( self::CHANGE_LAST_META, $now );
		if ( '' !== $key ) {
			$order->update_meta_data( self::CHANGE_IDEM_META, $key );
		}
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		if ( method_exists( $order, 'add_order_note' ) ) {
			$was = '' !== $old ? $old : '(none)';
			$order->add_order_note( 'Geometry revised (revision ' . $revision . '). Was: ' . $was . '. Now: ' . $summary . '.' );
		}
		return array(
			'ok'           => true,
			'unchanged'    => false,
			'designId'     => $check['designId'],
			'orderId'      => $check['orderId'],
			'orderUrl'     => self::order_admin_url( $order ),
			'revision'     => $revision,
			'oldGeometry'  => $old,
			'newGeometry'  => $summary,
			'productIds'   => $current,
			'total'        => $total,
		);
	}

	/**
	 * @param mixed $order_id
	 */
	public static function on_marked_in_design( $order_id ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		if ( '1' === (string) $order->get_meta( self::CHANGE_CLOSED_META, true ) ) {
			return;
		}
		$order->update_meta_data( self::CHANGE_CLOSED_META, '1' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * @param mixed $order
	 */
	public static function on_admin_order_change( $order ) {
		if ( ! self::is_fd_order( $order ) ) {
			return;
		}
		$open = self::change_window_open( $order );
		$ends = self::change_window_ends_at( $order );
		echo '<p class="creature-fd-change-window"><strong>Change window:</strong> ';
		if ( $open && '' !== $ends ) {
			echo 'open until ' . self::esc( $ends ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped.
		} elseif ( $open ) {
			echo 'open';
		} else {
			echo 'closed';
		}
		echo '</p>';
		$checked = self::change_is_started( $order ) ? ' checked="checked"' : '';
		echo '<p class="creature-fd-change-closed"><label>';
		echo '<input type="hidden" name="creature_fd_change_flag_present" value="1" />';
		echo '<input type="checkbox" name="creature_fd_change_closed" value="1"' . $checked . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
		echo 'Change window closed</label></p>';
	}

	/**
	 * @param mixed $order_id
	 */
	public static function on_save_change_flag( $order_id ) {
		if ( ! isset( $_POST['creature_fd_change_flag_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! self::is_fd_order( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$closed = isset( $_POST['creature_fd_change_closed'] ) && '1' === (string) wp_unslash( $_POST['creature_fd_change_closed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( self::status_is_in_design( $order ) ) {
			$closed = true;
		}
		$order->update_meta_data( self::CHANGE_CLOSED_META, $closed ? '1' : '' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	public static function register_change_routes() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		register_rest_route(
			'creature-fd/v1',
			'/change',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_read_change' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_apply_change' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rest_read_change( $request ) {
		$design = (string) self::request_param( $request, 'design' );
		$token  = (string) self::request_param( $request, 'token' );
		$order  = self::order_by_change_token( $token );
		if ( ! $order ) {
			return self::rest_response( array( 'error' => 'invalid', 'message' => 'This link is not valid.' ), 404 );
		}
		$check = self::verify_change_request( $order, $token, $design );
		if ( empty( $check['ok'] ) && isset( $check['error'] ) && 'closed' === $check['error'] ) {
			return self::rest_response(
				array( 'error' => 'closed', 'message' => self::CHANGE_CLOSED_MESSAGE ),
				410
			);
		}
		if ( empty( $check['ok'] ) ) {
			return self::rest_response( array( 'error' => 'invalid', 'message' => 'This link is not valid.' ), 404 );
		}
		return self::rest_response( $check, 200 );
	}

	/**
	 * @param mixed $request
	 * @return mixed
	 */
	public static function rest_apply_change( $request ) {
		$token = (string) self::request_param( $request, 'token' );
		$order = self::order_by_change_token( $token );
		if ( ! $order ) {
			return self::rest_response( array( 'error' => 'invalid', 'message' => 'This link is not valid.' ), 404 );
		}
		$ids = self::request_param( $request, 'productIds' );
		$result = self::apply_change_revision(
			$order,
			array(
				'token'           => $token,
				'designId'        => (string) self::request_param( $request, 'designId' ),
				'productIds'      => is_array( $ids ) ? $ids : array(),
				'geometrySummary' => (string) self::request_param( $request, 'geometrySummary' ),
				'idempotencyKey'  => (string) self::request_param( $request, 'idempotencyKey' ),
			)
		);
		$status = 200;
		if ( empty( $result['ok'] ) ) {
			$error  = isset( $result['error'] ) ? $result['error'] : 'invalid';
			$status = 'parts' === $error ? 409 : ( 'rate' === $error ? 429 : ( 'closed' === $error ? 410 : ( 'geometry' === $error ? 400 : 404 ) ) );
		}
		return self::rest_response( $result, $status );
	}

	/**
	 * @param string $token
	 * @return object|null
	 */
	public static function order_by_change_token( $token ) {
		if ( ! self::token_shape( $token ) || ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 2,
				'status'     => 'any',
				'meta_key'   => self::CHANGE_TOKEN_META,
				'meta_value' => $token,
				'return'     => 'objects',
			)
		);
		if ( ! is_array( $orders ) || 1 !== count( $orders ) ) {
			return null;
		}
		$order = $orders[0];
		return self::is_order( $order ) ? $order : null;
	}

	/**
	 * @param mixed $order
	 * @return string
	 */
	public static function order_admin_url( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_edit_order_url' ) ) {
			$url = $order->get_edit_order_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		$id = ( is_object( $order ) && method_exists( $order, 'get_id' ) ) ? (int) $order->get_id() : 0;
		if ( function_exists( 'admin_url' ) && $id ) {
			return admin_url( 'post.php?post=' . $id . '&action=edit' );
		}
		return '';
	}

	/**
	 * @return string
	 */
	private static function designer_url() {
		$value = apply_filters( 'creature_fd_designer_url', self::DESIGNER_URL );
		if ( ! is_string( $value ) ) {
			return self::DESIGNER_URL;
		}
		$value = trim( $value );
		if ( ! preg_match( '#^https://#', $value ) || strlen( $value ) > 300 ) {
			return self::DESIGNER_URL;
		}
		return $value;
	}

	/**
	 * @param string $token
	 * @return bool
	 */
	private static function token_shape( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * @param mixed $order
	 * @return bool
	 */
	private static function status_is_in_design( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
			return false;
		}
		$status = preg_replace( '/^wc-/', '', (string) $order->get_status() );
		return 'in-design' === $status;
	}

	/**
	 * @param mixed $order
	 * @return int[]
	 */
	private static function order_fd_product_ids( $order ) {
		$ids = array();
		foreach ( self::order_items( $order ) as $item ) {
			if ( ! self::item_is_fd_product( $item ) ) {
				continue;
			}
			foreach ( self::item_product_ids( $item ) as $id ) {
				if ( in_array( $id, self::product_ids(), true ) ) {
					$ids[] = (int) $id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * @param mixed $ids
	 * @return int[]
	 */
	private static function normalize_id_list( $ids ) {
		$list = self::normalize_ids( $ids );
		sort( $list );
		return $list;
	}

	/**
	 * @param mixed $order
	 * @return string
	 */
	private static function order_geometry( $order ) {
		foreach ( self::order_items( $order ) as $item ) {
			if ( ! self::item_is_fd_product( $item ) || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			$value = trim( (string) $item->get_meta( 'geometry_summary', true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			return trim( (string) $order->get_meta( 'geometry_summary', true ) );
		}
		return '';
	}

	/**
	 * @param mixed  $order
	 * @param string $summary
	 */
	private static function write_geometry( $order, $summary ) {
		foreach ( self::order_items( $order ) as $item ) {
			if ( ! self::item_is_fd_product( $item ) || ! is_object( $item ) || ! method_exists( $item, 'update_meta_data' ) ) {
				continue;
			}
			$item->update_meta_data( 'geometry_summary', $summary );
			if ( method_exists( $item, 'save' ) ) {
				$item->save();
			}
		}
		if ( is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( 'geometry_summary', $summary );
		}
	}

	/**
	 * @param mixed  $request
	 * @param string $key
	 * @return mixed
	 */
	private static function request_param( $request, $key ) {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				return $value;
			}
		}
		if ( is_array( $request ) && isset( $request[ $key ] ) ) {
			return $request[ $key ];
		}
		return '';
	}

	/**
	 * @param array $data
	 * @param int   $status
	 * @return mixed
	 */
	private static function rest_response( $data, $status ) {
		self::send_change_nocache();
		if ( class_exists( 'WP_REST_Response' ) ) {
			$response = new WP_REST_Response( $data, $status );
			if ( method_exists( $response, 'header' ) ) {
				$response->header( 'Cache-Control', 'no-store, private' );
			}
			return $response;
		}
		return array(
			'data'   => $data,
			'status' => (int) $status,
		);
	}

	/**
	 * The tools-api reads this route on every amend open. A cached 200 would
	 * reopen a window that has since closed.
	 */
	private static function send_change_nocache() {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'nocache_headers', array( __CLASS__, 'change_nocache_headers' ) );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'nocache_headers', array( __CLASS__, 'change_nocache_headers' ) );
		}
		if ( function_exists( 'headers_sent' ) && headers_sent() ) {
			return;
		}
		if ( function_exists( 'header' ) ) {
			header( 'Cache-Control: no-store, private', true );
		}
	}

	/**
	 * @param mixed $headers
	 * @return array
	 */
	public static function change_nocache_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}
		$headers['Cache-Control'] = 'no-store, private';
		return $headers;
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
