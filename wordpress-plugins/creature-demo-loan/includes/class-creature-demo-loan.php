<?php
/**
 * De-Reacher postal demo loan.
 *
 * Checkout consent and Stripe-only baskets, loan statuses, the two end-of-loan
 * choices (return or keep), charge notices, and the off-session charge gate. Staging and Stripe
 * test mode only, unless CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES is exactly true.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Plugin behaviour. Methods are static so the PHP harness can call them.
 */
final class Creature_Demo_Loan {

	const STATUS_LOAN     = 'demo-loan';
	const STATUS_BOOKED   = 'demo-booked';
	const STATUS_INSPECT  = 'demo-inspect';
	const STATUS_RETURNED = 'demo-returned';
	const STATUS_OVERDUE  = 'demo-overdue';
	const STATUS_KEPT     = 'demo-kept';
	const STATUS_DEEMED   = 'demo-deemed';
	const STATUS_CLOSED   = 'demo-closed';

	/**
	 * A pay-link lock older than this is not treated as in progress.
	 * The creature_demo_loan_charge_lock_stale_seconds filter can change it.
	 */
	const CHARGE_LOCK_STALE_SECONDS = 900;

	/** Terms 4.4 and 6.4(b): the one bill goes out within this many working days of the kit arriving. */
	const BILL_WORKING_DAYS = 5;

	/** The shop is emailed on this working day if the bill is not raised yet. */
	const BILL_WARN_WORKING_DAY = 4;

	/** Terms 8.5(b) and (c): the kit must be posted within this many days of the deemed payment to be refunded. */
	const DEEMED_REFUND_DAYS = 30;

	/** Hour (site time zone) the daily job runs. Late, so working day 5 is still open when it fires. */
	const DAILY_RUN_HOUR = 22;

	/** Option: the last London date (Y-m-d) the scheduled job has covered. */
	const DAILY_COVERED_OPTION = 'creature_demo_loan_daily_covered';

	/** Option: the late-run flag and the occurrences already reported. */
	const LATE_RUN_OPTION = 'creature_demo_loan_late_run';

	/** The most days one catch-up will run. */
	const DAILY_CATCH_UP_MAX = 14;

	/** Photo slots on the damage entry (terms 6.4(b)). */
	const PHOTO_SLOTS = 6;

	/** The demo product when the constant, the filter and the config screen are all empty. */
	const DEFAULT_DEMO_PRODUCT_ID = 8946;

	/** Woo emails that carry the booking confirmation content and attachments (terms 11.1). */
	const BOOKING_EMAIL_IDS = array( 'customer_processing_order', 'customer_on_hold_order' );

	/** A bundle price at or below this (the demo fee plus used stock) is treated as a price error. */
	const BUNDLE_FLOOR_PENCE = Creature_Demo_Loan_Money::DEMO_FEE_PENCE + Creature_Demo_Loan_Money::USED_STOCK_PENCE;

	/** @var bool */
	private static $booted = false;

	/** @var array<int, bool> */
	private static $intent_lookup_noted = array();

	/** @var array<int, string> */
	private static $intent_status_cache = array();

	/** @var array<int, string> */
	private static $held_lock_tokens = array();

	/** @var array<string, mixed>|null */
	public static $block_field = null;

	/**
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			self::STATUS_LOAN     => 'On loan',
			self::STATUS_BOOKED   => 'Return booked',
			self::STATUS_INSPECT  => 'Returned (inspecting)',
			self::STATUS_RETURNED => 'Returned',
			self::STATUS_OVERDUE  => 'Overdue',
			self::STATUS_KEPT     => 'Kept (purchased)',
			self::STATUS_DEEMED   => 'Deemed purchase',
			self::STATUS_CLOSED   => 'Closed',
		);
	}

	public static function boot() {
		if ( self::$booted || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( __CLASS__, 'on_init' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_action( 'creature_demo_loan_daily', array( __CLASS__, 'run_scheduled' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_late_run_notice' ) );

		add_filter( 'woocommerce_register_shop_order_post_statuses', array( __CLASS__, 'filter_post_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'filter_order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'filter_paid_statuses' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'filter_bulk' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'filter_bulk' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'filter_columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'filter_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_legacy_column' ), 10, 2 );

		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ) );
		// Stripe Gateway 11.1.1 applies this in classic and block checkout.
		// The second argument is an order id. 11.2.1 is not public yet.
		add_filter( 'wc_stripe_force_save_payment_method', array( __CLASS__, 'filter_force_save' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'filter_registration_required' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'filter_registration_enabled' ) );
		add_filter( 'wcpay_payment_request_is_product_supported', array( __CLASS__, 'filter_express_supported' ), 10, 2 );
		add_filter( 'wcpay_woopay_button_is_product_supported', array( __CLASS__, 'filter_express_supported' ), 10, 2 );
		add_filter( 'woocommerce_paypal_payments_product_supports_payment_request_button', array( __CLASS__, 'filter_express_supported' ), 10, 2 );
		add_filter( 'woocommerce_paypal_payments_product_buttons_disabled', array( __CLASS__, 'filter_paypal_buttons_disabled' ), 10, 2 );
		add_filter( 'wc_stripe_show_payment_request_on_checkout', array( __CLASS__, 'filter_payment_request_off' ) );
		add_filter( 'wc_stripe_show_payment_request_on_cart', array( __CLASS__, 'filter_payment_request_off' ) );

		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'on_check_cart_items' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'on_checkout_process' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'on_before_submit' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'on_checkout_create_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'on_store_api_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_store_api_service_area' ), 10, 2 );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'filter_order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_creature_demo_start_loan', array( __CLASS__, 'action_start' ) );
		add_action( 'woocommerce_order_action_creature_demo_charge_saved_card', array( __CLASS__, 'action_charge' ) );
		add_action( 'woocommerce_order_action_creature_demo_mark_disputed', array( __CLASS__, 'action_dispute' ) );
		add_action( 'woocommerce_order_action_creature_demo_uphold_dispute', array( __CLASS__, 'action_uphold' ) );
		add_action( 'woocommerce_order_action_creature_demo_waive_dispute', array( __CLASS__, 'action_waive' ) );
		add_action( 'woocommerce_order_action_creature_demo_record_scan', array( __CLASS__, 'action_scan' ) );
		add_action( 'woocommerce_order_action_creature_demo_complete_inspection', array( __CLASS__, 'action_inspect' ) );
		add_action( 'woocommerce_order_action_creature_demo_refund_deemed', array( __CLASS__, 'action_refund' ) );
		add_action( 'woocommerce_order_action_creature_demo_raise_damage', array( __CLASS__, 'action_damage' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_meta_box' ), 10, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_my_account_choices' ) );
		// The Demo loan tab in My account (terms 3A.1 and 3.2).
		add_filter( 'query_vars', array( __CLASS__, 'filter_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'filter_account_menu' ) );
		add_filter( 'woocommerce_endpoint_' . self::ACCOUNT_ENDPOINT . '_title', array( __CLASS__, 'filter_account_title' ) );
		add_action( 'woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', array( __CLASS__, 'render_account_tab' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_choice_request' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ) );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'on_before_pay_action' ) );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_pay_link_failed' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_pay_link_failed' ), 10, 2 );
		add_action( 'wc_gateway_stripe_process_payment_error', array( __CLASS__, 'on_stripe_payment_error' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_charge_order_deleted' ) );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'on_charge_order_deleted' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_shop_order' ) );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'filter_needs_payment' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_payment_complete' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_payment_complete' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_checkout_script' ) );

		// Booking confirmation (terms 11.1): consent record, express request, files.
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'render_booking_confirmation' ), 20, 4 );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'filter_booking_attachments' ), 10, 4 );
		add_filter( 'woocommerce_email_headers', array( __CLASS__, 'filter_email_headers' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'render_attachment_notice' ) );

		foreach ( array(
			'customer_processing_order',
			'customer_completed_order',
			'customer_on_hold_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
			'customer_failed_order',
			'customer_cancelled_order',
			'customer_new_account',
		) as $email_id ) {
			add_filter( 'woocommerce_email_recipient_' . $email_id, array( __CLASS__, 'filter_customer_recipient' ), 10, 3 );
			add_filter( 'woocommerce_email_subject_' . $email_id, array( __CLASS__, 'filter_customer_subject' ), 10, 3 );
		}
	}

	/**
	 * WooCommerce customer mail for a demo order follows the same redirect.
	 *
	 * @param mixed       $recipient
	 * @param object|null $order
	 * @param mixed       $email
	 * @return mixed
	 */
	public static function filter_customer_recipient( $recipient, $order = null, $email = null ) {
		if ( self::customer_email_allowed() || ! self::redirects_customer_mail( $order, $email ) ) {
			return $recipient;
		}
		$redirect = Creature_Demo_Loan_Mail::redirect_address();
		return '' !== $redirect ? $redirect : '';
	}

	/**
	 * @param mixed       $subject
	 * @param object|null $order
	 * @param mixed       $email
	 * @return mixed
	 */
	public static function filter_customer_subject( $subject, $order = null, $email = null ) {
		if ( self::customer_email_allowed() || ! self::redirects_customer_mail( $order, $email ) ) {
			return $subject;
		}
		$subject = (string) $subject;
		if ( 0 === strpos( $subject, '[TEST]' ) ) {
			return $subject;
		}
		return '[TEST] ' . $subject;
	}

	public static function on_init() {
		self::register_statuses();
		self::register_block_field();
		self::register_account_endpoint();
		self::schedule_daily();
		// The rewrite flush is not here: it runs on wp_loaded (maybe_flush_rewrite_rules).
	}

	/**
	 * Undefined is off. wp-config may set the constant before this file loads.
	 *
	 * @return bool
	 */
	public static function live_charges_allowed() {
		return defined( 'CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES' ) && true === CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES;
	}

	/**
	 * @return bool
	 */
	public static function customer_email_allowed() {
		return defined( 'CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL' ) && true === CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL;
	}

	/**
	 * Terms 8.5 applies to anyone when this is true. When false, only riders
	 * who never chose keep.
	 *
	 * @return bool
	 */
	public static function refund_includes_keep_choosers() {
		$value = true;
		if ( function_exists( 'apply_filters' ) ) {
			$value = apply_filters( 'creature_demo_loan_refund_includes_keep_choosers', true );
		}
		return (bool) $value;
	}

	/**
	 * @return int
	 */
	public static function loan_days() {
		$days = 21;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_length_days', 21 );
		}
		return $days > 0 ? $days : 21;
	}

	/**
	 * @return int
	 */
	public static function grace_days() {
		$days = 5;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_grace_days', 5 );
		}
		return $days >= 0 ? $days : 5;
	}

	/**
	 * @return int
	 */
	public static function deemed_after_days() {
		$days = 14;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_deemed_after_days', 14 );
		}
		return $days > 0 ? $days : 14;
	}

	/**
	 * @return int
	 */
	public static function notice_days() {
		$days = 7;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_notice_days', 7 );
		}
		return $days > 0 ? $days : 7;
	}

	/**
	 * Calendar days after the keep link is sent (terms 3A.2).
	 *
	 * @return int
	 */
	public static function keep_lapse_days() {
		$days = 7;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_keep_lapse_days', 7 );
		}
		return $days >= 0 ? $days : 7;
	}

	/**
	 * @return string[]
	 */
	public static function holidays() {
		$list = Creature_Demo_Loan_Calendar::default_bank_holidays();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_bank_holidays', $list );
			if ( is_array( $filtered ) ) {
				$list = $filtered;
			}
		}
		$out = array();
		foreach ( $list as $day ) {
			if ( Creature_Demo_Loan_Calendar::is_ymd( $day ) ) {
				$out[] = $day;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Demo product ids. A constant with ids wins, then the filter (the config
	 * screen sets the filter). When the constant, the filter and the config
	 * setting are all empty the default is 8946.
	 *
	 * @return int[]
	 */
	public static function demo_product_ids() {
		$ids = array();
		if ( defined( 'CREATURE_DEMO_LOAN_PRODUCT_IDS' ) ) {
			$ids = self::normalize_ids( self::constant_id_list( CREATURE_DEMO_LOAN_PRODUCT_IDS ) );
		}
		if ( ! $ids && function_exists( 'apply_filters' ) ) {
			$ids = self::normalize_ids( apply_filters( 'creature_demo_loan_product_ids', array() ) );
		}
		return $ids ? $ids : array( self::DEFAULT_DEMO_PRODUCT_ID );
	}

	/**
	 * Coupon products. Default is the De-Reacher stem, 6687. Terms 3A.1(a)
	 * also describe the bundle; add that id with the filter.
	 *
	 * @return int[]
	 */
	public static function coupon_product_ids() {
		if ( defined( 'CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS' ) ) {
			$ids = self::normalize_ids( self::constant_id_list( CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS ) );
			return $ids ? $ids : array( 6687 );
		}
		$ids = array( 6687 );
		if ( function_exists( 'apply_filters' ) ) {
			$ids = apply_filters( 'creature_demo_loan_coupon_product_ids', $ids );
		}
		$ids = self::normalize_ids( $ids );
		return $ids ? $ids : array( 6687 );
	}

	/**
	 * The bundle whose price sets the keep price. Default is 8425, the
	 * De-Reacher Stem + Inverted Mount Kit Bundle. The constant wins, then the
	 * filter. Zero means no usable id.
	 *
	 * @return int
	 */
	public static function bundle_product_id() {
		if ( defined( 'CREATURE_DEMO_LOAN_BUNDLE_PRODUCT_ID' ) ) {
			return max( 0, (int) CREATURE_DEMO_LOAN_BUNDLE_PRODUCT_ID );
		}
		$id = 8425;
		if ( function_exists( 'apply_filters' ) ) {
			$id = apply_filters( 'creature_demo_loan_bundle_product_id', $id );
		}
		return is_numeric( $id ) ? max( 0, (int) $id ) : 0;
	}

	/**
	 * Bundle price today in pence, or null when there is no usable price.
	 *
	 * wc_get_product() hands back the variable parent, and get_price() on a
	 * parent is the cheapest variation's price. That is deliberate. All four
	 * variations are £249 today; if they ever diverge we would only charge less
	 * than the kit is worth, never more, because the keep price is capped at
	 * the ceiling. get_price() already returns the sale price when there is
	 * one, so this does not use get_regular_price().
	 *
	 * The formula assumes a single-price bundle (all variations the same
	 * price). A mixed-price bundle reads the product's get_price() and is not
	 * verified.
	 *
	 * @return int|null
	 */
	private static function bundle_price_pence_now() {
		$id = self::bundle_product_id();
		if ( $id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return null;
		}
		$price = $product->get_price();
		if ( ! is_numeric( $price ) ) {
			return null;
		}
		$pence = (int) round( (float) $price * 100 );
		return $pence > 0 ? $pence : null;
	}

	/**
	 * Record the bundle price at booking so a later sale cannot move a loan
	 * that already exists. Written once; a repeat call leaves the first figure.
	 *
	 * @param object $order
	 */
	private static function capture_bundle_price( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		if ( '' !== self::meta_string( $order, '_creature_demo_bundle_price_source' ) ) {
			return;
		}
		$pence = self::bundle_price_pence_now();
		if ( null === $pence ) {
			$source = 'fallback';
		} elseif ( $pence <= self::BUNDLE_FLOOR_PENCE ) {
			// At or below the deductions the formula gives 0 or a few pence, which
			// is a price error, not a real keep price. Record the real figure, but
			// mark it so the read side uses the ceiling.
			$source = 'fallback_below_floor';
		} else {
			$source = 'bundle';
		}
		$order->update_meta_data( '_creature_demo_bundle_price_pence', null === $pence ? 0 : $pence );
		$order->update_meta_data( '_creature_demo_bundle_price_source', $source );
	}

	/**
	 * This loan's keep price in pence. The only place an order and the money
	 * class meet. Missing meta, or a fallback booking, gives the ceiling.
	 *
	 * @param object $order
	 * @return int
	 */
	public static function loan_keep_price_pence( $order ) {
		$ceiling = Creature_Demo_Loan_Money::KEEP_CEILING_PENCE;
		if ( ! is_object( $order ) ) {
			return $ceiling;
		}
		$bundle = self::meta_string( $order, '_creature_demo_bundle_price_pence' );
		$source = self::meta_string( $order, '_creature_demo_bundle_price_source' );
		if ( '' === $bundle || 0 === strpos( $source, 'fallback' ) ) {
			return $ceiling;
		}
		return Creature_Demo_Loan_Money::keep_price_pence( (int) $bundle );
	}

	/**
	 * Due −5 (choices), due day (choices), grace end (terms 7.5), deemed warning.
	 * Charge notices are sent when a charge is raised, not on this calendar.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function reminder_schedule() {
		$default = array(
			'due_minus_5'    => array(
				'offset_days'      => -5,
				'slot'             => 'reminder_choices_due_minus_5',
				'includes_choices' => true,
			),
			'due_day'        => array(
				'offset_days'      => 0,
				'slot'             => 'reminder_due_day',
				'includes_choices' => true,
			),
			'grace_end'      => array(
				'offset_days'      => 5,
				'slot'             => 'reminder_grace_end',
				'includes_choices' => false,
			),
			'deemed_warning' => array(
				'offset_days'      => 10,
				'slot'             => 'reminder_deemed_warning',
				'includes_choices' => false,
			),
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_reminder_schedule', $default );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		return $default;
	}

	/**
	 * @param string $text
	 * @return string
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/\b(?:sk|pk|rk)_(?:test|live)_[A-Za-z0-9]+/', '[redacted-key]', $text );
		$text = preg_replace( '/\b\d{13,19}\b/', '[redacted-number]', $text );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * @param bool $test_mode
	 * @param bool $test_key
	 * @param bool $allow_live
	 * @return string Empty when the mode gate allows a charge.
	 */
	public static function mode_refusal( $test_mode, $test_key, $allow_live ) {
		if ( $test_mode && $test_key ) {
			return '';
		}
		if ( $allow_live ) {
			return '';
		}
		if ( ! $test_mode ) {
			return 'live_mode';
		}
		return 'live_key';
	}

	/**
	 * @param array<string, mixed> $state
	 * @return string
	 */
	public static function charge_refusal_code( array $state ) {
		if ( empty( $state['notice_logged'] ) ) {
			return 'no_notice';
		}
		$need = isset( $state['required_days'] ) ? (int) $state['required_days'] : 7;
		$since = isset( $state['days_since_notice'] ) ? (int) $state['days_since_notice'] : 0;
		if ( $since < $need ) {
			return 'notice_too_recent';
		}
		$dispute = isset( $state['dispute'] ) ? (string) $state['dispute'] : '';
		if ( 'open' === $dispute ) {
			return 'disputed';
		}
		if ( 'waived' === $dispute ) {
			return 'dispute_waived';
		}
		if ( ! empty( $state['paid'] ) ) {
			return 'already_paid';
		}
		if ( empty( $state['consent'] ) ) {
			return 'no_consent';
		}
		return self::mode_refusal(
			! empty( $state['test_mode'] ),
			! empty( $state['test_key'] ),
			! empty( $state['allow_live'] )
		);
	}

	/**
	 * @param string $code
	 * @return string
	 */
	public static function refusal_message( $code ) {
		$need = self::notice_days();
		$map  = array(
			'no_notice'          => 'Charge refused: no charge notice is logged on the order.',
			'notice_too_recent'  => 'Charge refused: fewer than ' . $need . ' days have passed since the notice (or since the final-decision email, once a dispute is decided).',
			'disputed'           => 'Charge refused: a dispute is open.',
			'dispute_waived'     => 'Charge refused: the dispute decision did not uphold the charge.',
			'already_paid'         => 'Charge refused: this charge is already paid.',
			'charge_cancelled'     => 'Charge refused: this charge order is cancelled, so the saved card is not charged for it.',
			'charge_netted'        => 'Charge refused: this deduction is taken from the deemed purchase refund, not from the saved card.',
			'payment_in_progress'  => 'Charge refused: a pay-link payment is already in progress on this charge order.',
			'no_consent'           => 'Charge refused: the loan has no recorded consent for a later charge.',
			'live_mode'          => 'Charge refused: the Stripe gateway is not in test mode.',
			'live_key'           => 'Charge refused: the Stripe secret key is not a test key.',
			'no_saved_method'    => 'Charge refused: no saved Stripe customer and payment method were found.',
			'stripe_api_missing' => 'Charge refused: the Stripe gateway API helper is not available.',
			'stripe_error'       => 'Charge failed. The saved card was not charged. A payment link remains available.',
		);
		$msg = isset( $map[ $code ] ) ? $map[ $code ] : 'Charge refused.';
		if ( 'crash_hold' === $code ) {
			// Shown to the rider on the pay page (and, as the same slot, on the order page and the Demo loan tab). Cadence v3.
			$slot = trim( Creature_Demo_Loan_Copy::text( 'crash_hold_notice' ) );
			$msg  = '' !== $slot ? $slot : 'Charge refused.';
		}
		return self::redact( $msg );
	}

	/**
	 * @return array{test_mode: bool, test_key: bool}
	 */
	public static function stripe_mode_flags() {
		$settings = array();
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( 'woocommerce_stripe_settings', array() );
			if ( is_array( $stored ) ) {
				$settings = $stored;
			}
		}
		$test_mode = isset( $settings['testmode'] ) && 'yes' === $settings['testmode'];
		$key       = '';
		if ( class_exists( 'WC_Stripe_API' ) && is_callable( array( 'WC_Stripe_API', 'get_secret_key' ) ) ) {
			$fetched = WC_Stripe_API::get_secret_key();
			$key     = is_string( $fetched ) ? $fetched : '';
		} elseif ( $test_mode && isset( $settings['test_secret_key'] ) && is_string( $settings['test_secret_key'] ) ) {
			$key = $settings['test_secret_key'];
		} elseif ( isset( $settings['secret_key'] ) && is_string( $settings['secret_key'] ) ) {
			$key = $settings['secret_key'];
		}
		$test_key = ( '' !== $key && 0 === strncmp( $key, 'sk_test_', 8 ) );
		unset( $key );
		return array(
			'test_mode' => $test_mode,
			'test_key'  => $test_key,
		);
	}

	/**
	 * @param object $order
	 * @return array{customer: string, payment_method: string}
	 */
	public static function saved_card_refs( $order ) {
		$customer = self::meta_string( $order, '_stripe_customer_id' );
		$method   = self::meta_string( $order, '_stripe_source_id' );
		if ( '' === $method ) {
			$method = self::meta_string( $order, '_stripe_payment_method' );
		}
		$refs = array(
			'customer'       => $customer,
			'payment_method' => $method,
		);
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_saved_card_refs', $refs, $order );
			if ( is_array( $filtered ) ) {
				$refs['customer']       = isset( $filtered['customer'] ) ? (string) $filtered['customer'] : '';
				$refs['payment_method'] = isset( $filtered['payment_method'] ) ? (string) $filtered['payment_method'] : '';
			}
		}
		if ( 0 !== strpos( $refs['customer'], 'cus_' ) ) {
			$refs['customer'] = '';
		}
		$pm = $refs['payment_method'];
		if ( 0 !== strpos( $pm, 'pm_' ) && 0 !== strpos( $pm, 'src_' ) && 0 !== strpos( $pm, 'card_' ) ) {
			$refs['payment_method'] = '';
		}
		return $refs;
	}

	/**
	 * @param mixed $gateways
	 * @return mixed
	 */
	public static function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ! self::cart_is_demo_only() ) {
			return $gateways;
		}
		$allowed = array( 'stripe' );
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_gateway_ids', $allowed );
			if ( is_array( $filtered ) ) {
				$allowed = array_map( 'strval', $filtered );
			}
		}
		$kept = array();
		foreach ( $gateways as $id => $gateway ) {
			if ( in_array( (string) $id, $allowed, true ) ) {
				$kept[ $id ] = $gateway;
			}
		}
		return $kept;
	}

	/**
	 * Gateway 11.1.1 passes an order id. An order object is accepted too.
	 *
	 * @param mixed $force
	 * @param mixed $order
	 * @return mixed
	 */
	public static function filter_force_save( $force, $order = null ) {
		if ( self::cart_is_demo_only() ) {
			return true;
		}
		if ( is_numeric( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order );
		}
		if ( is_object( $order ) && self::order_is_demo( $order ) ) {
			return true;
		}
		return $force;
	}

	/**
	 * @param mixed $required
	 * @return mixed
	 */
	public static function filter_registration_required( $required ) {
		if ( self::cart_is_demo_only() ) {
			return true;
		}
		return $required;
	}

	/**
	 * @param mixed $enabled
	 * @return mixed
	 */
	public static function filter_registration_enabled( $enabled ) {
		if ( self::cart_is_demo_only() ) {
			return true;
		}
		return $enabled;
	}

	/**
	 * @param mixed       $supported
	 * @param object|null $product
	 * @return mixed
	 */
	public static function filter_express_supported( $supported, $product = null ) {
		if ( self::cart_is_demo_only() || self::product_is_demo( $product ) ) {
			return false;
		}
		return $supported;
	}

	/**
	 * @param mixed $disabled
	 * @param mixed $context
	 * @return mixed
	 */
	public static function filter_paypal_buttons_disabled( $disabled, $context = null ) {
		if ( self::cart_is_demo_only() ) {
			return true;
		}
		if ( is_array( $context ) && isset( $context['product'] ) && self::product_is_demo( $context['product'] ) ) {
			return true;
		}
		return $disabled;
	}

	/**
	 * @param mixed $enabled
	 * @return mixed
	 */
	public static function filter_payment_request_off( $enabled ) {
		if ( self::cart_is_demo_only() ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * @return bool
	 */
	public static function cart_is_demo_only() {
		$items = self::cart_items();
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! self::item_is_demo( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return bool
	 */
	public static function cart_contains_demo() {
		foreach ( self::cart_items() as $item ) {
			if ( self::item_is_demo( $item ) ) {
				return true;
			}
		}
		return false;
	}

	public static function on_check_cart_items() {
		if ( self::cart_contains_demo() && ! self::cart_is_demo_only() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( Creature_Demo_Loan_Copy::text( 'mixed_basket_error' ), 'error' );
		}
	}

	public static function on_checkout_process() {
		if ( ! self::cart_is_demo_only() ) {
			return;
		}
		if ( ! self::posted_consent() && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( Creature_Demo_Loan_Copy::text( 'consent_error' ), 'error' );
		}
		$problem = self::service_area_problem(
			self::posted_field( 'billing_country' ),
			self::posted_field( 'billing_postcode' ),
			self::posted_field( 'shipping_country' ),
			self::posted_field( 'shipping_postcode' )
		);
		if ( '' !== $problem && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * Demo loans ship only to England, Scotland, Wales and Northern Ireland.
	 * An empty string means the address is inside that area.
	 *
	 * @param mixed $billing_country
	 * @param mixed $billing_postcode
	 * @param mixed $shipping_country
	 * @param mixed $shipping_postcode
	 * @return string
	 */
	public static function service_area_problem( $billing_country, $billing_postcode, $shipping_country = '', $shipping_postcode = '' ) {
		$shipping_country = trim( (string) $shipping_country );
		if ( ! self::country_is_gb( $billing_country ) || ( '' !== $shipping_country && ! self::country_is_gb( $shipping_country ) ) ) {
			return Creature_Demo_Loan_Copy::text( 'service_area_error' );
		}
		if ( self::postcode_outside_uk( $billing_postcode ) || self::postcode_outside_uk( $shipping_postcode ) ) {
			return Creature_Demo_Loan_Copy::text( 'service_area_error' );
		}
		return '';
	}

	/**
	 * Block checkout. Classic checkout uses on_checkout_process.
	 *
	 * @param object $order
	 * @param mixed  $request
	 */
	public static function on_store_api_service_area( $order, $request = null ) {
		unset( $order );
		if ( ! self::cart_is_demo_only() ) {
			return;
		}
		$billing  = self::request_address( $request, 'billing_address' );
		$shipping = self::request_address( $request, 'shipping_address' );
		$problem  = self::service_area_problem(
			isset( $billing['country'] ) ? $billing['country'] : '',
			isset( $billing['postcode'] ) ? $billing['postcode'] : '',
			isset( $shipping['country'] ) ? $shipping['country'] : '',
			isset( $shipping['postcode'] ) ? $shipping['postcode'] : ''
		);
		if ( '' === $problem ) {
			return;
		}
		if ( class_exists( 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'creature_demo_service_area', $problem, 400 );
		}
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $problem, 'error' );
		}
	}

	/**
	 * @param mixed $country
	 * @return bool
	 */
	private static function country_is_gb( $country ) {
		return 'GB' === strtoupper( trim( (string) $country ) );
	}

	/**
	 * GY, JE and IM are the Channel Islands and the Isle of Man. WooCommerce
	 * files them under GB. IP and IV are mainland postcodes and are allowed.
	 *
	 * @param mixed $postcode
	 * @return bool
	 */
	private static function postcode_outside_uk( $postcode ) {
		$norm = strtoupper( (string) preg_replace( '/\s+/', '', (string) $postcode ) );
		if ( '' === $norm ) {
			return false;
		}
		return 1 === preg_match( '/^(GY|JE|IM)(\d|$)/', $norm );
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private static function posted_field( $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		return self::unslash_string( $_POST[ $key ] );
	}

	/**
	 * @param mixed  $request
	 * @param string $key
	 * @return array<string, mixed>
	 */
	private static function request_address( $request, $key ) {
		$value = array();
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( $key );
		} elseif ( is_array( $request ) && isset( $request[ $key ] ) ) {
			$value = $request[ $key ];
		}
		return is_array( $value ) ? $value : array();
	}

	public static function on_before_submit() {
		if ( ! self::cart_is_demo_only() ) {
			return;
		}
		echo self::classic_checkbox_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::express_checkbox_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The label of the "Send my kit now" checkbox (terms 11.3, Appendix B). Empty
	 * until Cadence supplies it.
	 *
	 * @return string
	 */
	public static function express_label() {
		return trim( Creature_Demo_Loan_Copy::text( 'express_request_checkbox' ) );
	}

	/**
	 * Terms 11.3 and Appendix B: not ticked in advance, and separate from the
	 * consent box. Nothing is shown while the label is empty.
	 *
	 * @return string
	 */
	public static function express_checkbox_html() {
		$label = self::express_label();
		if ( '' === $label ) {
			return '';
		}
		return '<p class="form-row creature-demo-loan-express"><label><input type="checkbox" name="creature_demo_express_request" id="creature_demo_express_request" value="1" /> <span>' . self::esc( $label ) . '</span></label></p>';
	}

	/**
	 * @return bool
	 */
	public static function posted_express() {
		if ( ! isset( $_POST['creature_demo_express_request'] ) ) {
			return false;
		}
		return '1' === self::unslash_string( $_POST['creature_demo_express_request'] );
	}

	/**
	 * Store the rider's answer to "Send my kit now" as
	 * `_creature_demo_express_request`: yes when ticked, no when the checkbox was
	 * shown and left unticked. Nothing is stored when the box was not shown (its
	 * label is empty), so an unanswered question is never recorded as a no. There
	 * is no refund logic here: the pro-rata refund under 11.3 stays manual.
	 *
	 * @param object $order
	 * @param bool   $ticked
	 */
	public static function store_express_request( $order, $ticked ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		if ( ! $ticked && '' === self::express_label() ) {
			return;
		}
		$order->update_meta_data( '_creature_demo_express_request', $ticked ? 'yes' : 'no' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Unticked. Not Woo's terms checkbox.
	 *
	 * @return string
	 */
	public static function classic_checkbox_html() {
		$label = self::esc( Creature_Demo_Loan_Copy::text( 'consent_checkbox' ) );
		return '<p class="form-row creature-demo-loan-consent validate-required"><label><input type="checkbox" name="creature_demo_loan_consent" id="creature_demo_loan_consent" value="1" /> <span>' . $label . '</span></label></p>';
	}

	/**
	 * @return bool
	 */
	public static function posted_consent() {
		if ( ! isset( $_POST['creature_demo_loan_consent'] ) ) {
			return false;
		}
		return '1' === self::unslash_string( $_POST['creature_demo_loan_consent'] );
	}

	/**
	 * @param object $order
	 * @param mixed  $data
	 */
	public static function on_checkout_create_order( $order, $data = array() ) {
		unset( $data );
		if ( ! self::cart_is_demo_only() && ! self::order_is_demo( $order ) ) {
			return;
		}
		self::capture_bundle_price( $order );
		if ( self::posted_consent() ) {
			self::store_consent( $order, true, gmdate( 'c' ) );
		}
		self::store_express_request( $order, self::posted_express() );
	}

	/**
	 * @param object $order
	 * @param mixed  $request
	 */
	public static function on_store_api_order( $order, $request = null ) {
		$value = self::block_consent_value( $order, $request );
		if ( self::value_is_yes( $value ) ) {
			self::store_consent( $order, true, gmdate( 'c' ) );
		}
		if ( self::cart_is_demo_only() || self::order_is_demo( $order ) ) {
			self::capture_bundle_price( $order );
			self::store_express_request( $order, self::value_is_yes( self::block_field_value( $order, $request, 'creature/demo-express-request' ) ) );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}
	}

	/**
	 * @param mixed $value
	 * @return WP_Error|string|null
	 */
	public static function validate_block_consent( $value ) {
		if ( ! self::cart_is_demo_only() ) {
			return null;
		}
		if ( self::value_is_yes( $value ) ) {
			return null;
		}
		$message = Creature_Demo_Loan_Copy::text( 'consent_error' );
		if ( class_exists( 'WP_Error' ) ) {
			return new WP_Error( 'creature_demo_consent', $message );
		}
		return $message;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function block_field_definition() {
		return array(
			'id'                => 'creature/demo-loan-consent',
			'label'             => Creature_Demo_Loan_Copy::text( 'consent_checkbox' ),
			'location'          => 'order',
			'type'              => 'checkbox',
			'required'          => false,
			'validate_callback' => static function ( ...$args ) {
				$value = isset( $args[0] ) ? $args[0] : null;
				return Creature_Demo_Loan::validate_block_consent( $value );
			},
		);
	}

	public static function register_block_field() {
		if ( is_array( self::$block_field ) ) {
			return;
		}
		$def               = self::block_field_definition();
		self::$block_field = $def;
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			woocommerce_register_additional_checkout_field( $def );
			// Terms 11.3: the "Send my kit now" checkbox. Not required, not ticked, and not registered while it has no label.
			$express = self::express_block_field_definition();
			if ( is_array( $express ) ) {
				woocommerce_register_additional_checkout_field( $express );
			}
		}
	}

	/**
	 * @return array<string, mixed>|null Null while the label is empty.
	 */
	public static function express_block_field_definition() {
		$label = self::express_label();
		if ( '' === $label ) {
			return null;
		}
		return array(
			'id'       => 'creature/demo-express-request',
			'label'    => $label,
			'location' => 'order',
			'type'     => 'checkbox',
			'required' => false,
		);
	}

	/**
	 * @param object $order
	 * @param bool   $checked
	 * @param string $when
	 * @return bool
	 */
	public static function store_consent( $order, $checked, $when = null ) {
		if ( ! self::value_is_yes( $checked ) ) {
			return false;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return false;
		}
		$when    = is_string( $when ) && '' !== $when ? $when : gmdate( 'c' );
		$version = self::consent_version();
		$order->update_meta_data( '_creature_demo_consent', 'yes' );
		$order->update_meta_data( '_creature_demo_consent_wording', Creature_Demo_Loan_Copy::consent_wording( $version ) );
		$order->update_meta_data( '_creature_demo_consent_version', $version );
		$order->update_meta_data( '_creature_demo_consent_at', $when );
		$order->update_meta_data( '_creature_demo_loan', 'yes' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		return true;
	}

	/**
	 * The consent version stored on a new order. `version-5` reads as the
	 * terms' "version 5". The filter can name an older version, and a string
	 * the plugin does not know falls back to `version-5`, so a stored version
	 * always matches the wording that was shown.
	 *
	 * @return string
	 */
	public static function consent_version() {
		$version = Creature_Demo_Loan_Copy::CONSENT_CURRENT;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_consent_version', $version );
			if ( is_string( $filtered ) && in_array( $filtered, Creature_Demo_Loan_Copy::consent_versions(), true ) ) {
				$version = $filtered;
			}
		}
		return $version;
	}

	/**
	 * The consent on an order as read back: version, its label, the wording the
	 * rider agreed to, and when. The stored wording wins over the catalogue.
	 *
	 * @param object $order
	 * @return array{version: string, label: string, wording: string, at: string, date: string}
	 */
	public static function consent_record( $order ) {
		$version = self::meta_string( $order, '_creature_demo_consent_version' );
		$wording = self::meta_string( $order, '_creature_demo_consent_wording' );
		$at      = self::meta_string( $order, '_creature_demo_consent_at' );
		if ( '' === $wording && 'yes' === self::meta_string( $order, '_creature_demo_consent' ) ) {
			$wording = Creature_Demo_Loan_Copy::consent_wording( $version );
		}
		return array(
			'version' => $version,
			'label'   => Creature_Demo_Loan_Copy::consent_version_label( $version ),
			'wording' => $wording,
			'at'      => $at,
			'date'    => Creature_Demo_Loan_Calendar::format_uk( substr( $at, 0, 10 ) ),
		);
	}

	/**
	 * @param object $order
	 * @param string $dispatch
	 * @param string $delivery
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function start_loan( $order, $dispatch, $delivery = '', $as_of = null ) {
		unset( $as_of );
		if ( 'yes' === self::meta_string( $order, '_creature_demo_loan' ) && '' !== self::meta_string( $order, '_creature_demo_due_date' ) ) {
			return array(
				'ok'          => true,
				'idempotent'  => true,
				'due'         => self::meta_string( $order, '_creature_demo_due_date' ),
				'delivery'    => self::meta_string( $order, '_creature_demo_delivery_date' ),
				'estimated'   => 'yes' === self::meta_string( $order, '_creature_demo_delivery_estimated' ),
			);
		}
		$dispatch  = Creature_Demo_Loan_Calendar::today( $dispatch );
		$estimated = ! Creature_Demo_Loan_Calendar::is_ymd( $delivery );
		if ( $estimated ) {
			$delivery = Creature_Demo_Loan_Calendar::default_delivery_date( $dispatch, self::holidays() );
		}
		$due  = Creature_Demo_Loan_Calendar::due_date( $delivery, self::loan_days() );
		$note = 'Demo loan started. Due ' . $due . '.';
		if ( $estimated ) {
			$note = 'Demo loan started. Delivery date ' . $delivery . ' is an estimate (dispatch plus 2 working days), not a tracked delivery. Replace it with the tracked date. Due ' . $due . '.';
		}
		$order->update_meta_data( '_creature_demo_loan', 'yes' );
		$order->update_meta_data( '_creature_demo_dispatch_date', $dispatch );
		$order->update_meta_data( '_creature_demo_delivery_date', $delivery );
		$order->update_meta_data( '_creature_demo_delivery_estimated', $estimated ? 'yes' : 'no' );
		$order->update_meta_data( '_creature_demo_due_date', $due );
		if ( '' === self::meta_string( $order, '_creature_demo_action_token' ) ) {
			$order->update_meta_data( '_creature_demo_action_token', self::random_token() );
		}
		$order->update_status( self::STATUS_LOAN, $note );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		// A tracked delivery date is the delivery. An estimate is not, and the
		// dates email waits for the tracked date.
		if ( ! $estimated ) {
			self::send_dates_on_delivery( $order );
		}
		return array(
			'ok'        => true,
			'due'       => $due,
			'delivery'  => $delivery,
			'dispatch'  => $dispatch,
			'estimated' => $estimated,
		);
	}

	/**
	 * Terms 3.2: when the kit is delivered, email the due date, the last day of
	 * grace and the deemed purchase date. Once per loan.
	 *
	 * @param object $order
	 * @return bool True when the email went out on this call.
	 */
	public static function send_dates_on_delivery( $order ) {
		if ( 'yes' === self::meta_string( $order, '_creature_demo_dates_sent' ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_delivery_estimated' ) ) {
			return false;
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $due ) ) {
			return false;
		}
		$order->update_meta_data( '_creature_demo_dates_sent', 'yes' );
		$order->save();
		Creature_Demo_Loan_Mail::send_customer(
			$order,
			'dates_on_delivery',
			array(
				'due'       => $due,
				'grace_end' => Creature_Demo_Loan_Calendar::grace_end( $due, self::grace_days() ),
				'deemed_on' => self::deemed_on_for( $order ),
				'price'     => Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $order ) ),
			)
		);
		return true;
	}

	/**
	 * Replace an estimated or wrong delivery date and recompute the due date.
	 * Refused once the kit is kept, returned, closed, or deemed purchased.
	 *
	 * @param object $order
	 * @param string $delivery
	 * @return array<string, mixed>
	 */
	public static function correct_delivery_date( $order, $delivery ) {
		if ( ! Creature_Demo_Loan_Calendar::is_ymd( $delivery ) ) {
			return array(
				'ok'     => false,
				'reason' => 'bad_date',
			);
		}
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_loan',
			);
		}
		$status = (string) $order->get_status();
		if ( in_array( $status, array( self::STATUS_KEPT, self::STATUS_CLOSED, self::STATUS_RETURNED, self::STATUS_DEEMED ), true ) ) {
			return array(
				'ok'     => false,
				'reason' => 'closed',
			);
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' ) || 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'closed',
			);
		}
		$current = self::meta_string( $order, '_creature_demo_delivery_date' );
		if ( $delivery === $current && 'yes' !== self::meta_string( $order, '_creature_demo_delivery_estimated' ) ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'due'        => self::meta_string( $order, '_creature_demo_due_date' ),
				'delivery'   => $current,
			);
		}
		$due = Creature_Demo_Loan_Calendar::due_date( $delivery, self::loan_days() );
		$order->update_meta_data( '_creature_demo_delivery_date', $delivery );
		$order->update_meta_data( '_creature_demo_delivery_estimated', 'no' );
		$order->update_meta_data( '_creature_demo_due_date', $due );
		$order->save();
		self::note( $order, 'Tracked delivery date set to ' . $delivery . '. Due date is now ' . $due . '.' );
		self::send_dates_on_delivery( $order );
		return array(
			'ok'       => true,
			'due'      => $due,
			'delivery' => $delivery,
		);
	}

	/**
	 * @param object $order
	 * @param string $choice
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function choose( $order, $choice, $as_of = null ) {
		$as_of  = Creature_Demo_Loan_Calendar::today( $as_of );
		$choice = self::normalize_choice( $choice );
		if ( '' === $choice ) {
			return array(
				'ok'     => false,
				'reason' => 'unknown',
			);
		}
		// A stored `buy` (the old third choice) reads as return.
		$current = self::normalize_choice( self::meta_string( $order, '_creature_demo_choice' ) );
		$lapsed  = 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' );
		if ( 'keep' === $choice && self::crash_hold( $order ) ) {
			return array(
				'ok'     => false,
				'reason' => 'crash_hold',
			);
		}
		if ( $current === $choice && ! ( 'keep' === $choice && $lapsed ) ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'choice'     => $choice,
			);
		}
		if ( ! self::choice_allowed( $order, $choice, $as_of ) ) {
			return array(
				'ok'     => false,
				'reason' => 'window',
			);
		}
		if ( 'keep' === $choice ) {
			return self::choose_keep( $order, $current, $as_of );
		}
		return self::choose_return( $order, $as_of );
	}

	/**
	 * @param object $order
	 * @param string $choice
	 * @param mixed  $as_of
	 * @return bool
	 */
	public static function choice_allowed( $order, $choice, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
			return false;
		}
		$choice = self::normalize_choice( $choice );
		if ( '' === $choice ) {
			return false;
		}
		// Terms 3A.3: after a reported crash, impact or failure the kit cannot be
		// kept until it has been inspected.
		if ( 'keep' === $choice && self::crash_hold( $order ) ) {
			return false;
		}
		$status = (string) $order->get_status();
		if ( in_array( $status, array( self::STATUS_KEPT, self::STATUS_CLOSED, self::STATUS_RETURNED ), true ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return false;
		}
		$scan    = self::meta_string( $order, '_creature_demo_return_scan_date' );
		$current = self::normalize_choice( self::meta_string( $order, '_creature_demo_choice' ) );
		$lapsed  = 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' );
		$due     = self::meta_string( $order, '_creature_demo_due_date' );
		$reached = self::deemed_reached( $order, $as_of );

		if ( 'keep' === $choice ) {
			if ( '' !== $scan || $reached ) {
				return false;
			}
			$open = true;
			if ( function_exists( 'apply_filters' ) ) {
				apply_filters( 'creature_demo_loan_keep_until', 'deemed_point', $order );
				$open = (bool) apply_filters( 'creature_demo_loan_keep_choice_open', true, $order, $as_of );
			}
			return $open;
		}

		if ( 'keep' === $current && ! $lapsed ) {
			return false;
		}
		if ( $reached ) {
			return false;
		}
		$until = 'return_scan';
		if ( function_exists( 'apply_filters' ) ) {
			$until = (string) apply_filters( 'creature_demo_loan_buy_return_switch_until', 'return_scan', $order );
		}
		if ( 'due_date' === $until ) {
			if ( '' !== $scan ) {
				return false;
			}
			if ( '' !== $due && $as_of > $due ) {
				return false;
			}
		} elseif ( '' !== $scan ) {
			return false;
		}
		if ( function_exists( 'apply_filters' ) ) {
			return (bool) apply_filters( 'creature_demo_loan_buy_return_switch_open', true, $order, $as_of );
		}
		return true;
	}

	/**
	 * Terms 3A.3: a crash, impact or failure is flagged on the order and the
	 * inspection is not recorded yet. While true the keep choice is hidden or
	 * refused.
	 *
	 * @param object $order
	 * @return bool
	 */
	public static function crash_hold( $order ) {
		return is_object( $order ) && 'yes' === self::meta_string( $order, '_creature_demo_crash_reported' ) && 'yes' !== self::meta_string( $order, '_creature_demo_inspection_done' );
	}

	/**
	 * Empty means return (terms 3A.5). A stored `buy` is the old third choice
	 * and reads as return. A lapsed keep is not an active choice.
	 *
	 * @param object $order
	 * @return string
	 */
	public static function effective_choice( $order ) {
		$choice = self::meta_string( $order, '_creature_demo_choice' );
		if ( 'keep' === $choice && 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' ) ) {
			$choice = '';
		}
		$choice = self::normalize_choice( $choice );
		return '' === $choice ? 'return' : $choice;
	}

	/**
	 * @param object $order
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function maybe_lapse_keep( $order, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' ) && 'keep' !== self::meta_string( $order, '_creature_demo_choice' ) ) {
			return array( 'ok' => true, 'idempotent' => true );
		}
		if ( 'keep' !== self::meta_string( $order, '_creature_demo_choice' ) ) {
			return array( 'ok' => false, 'reason' => 'not_keep' );
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return array( 'ok' => false, 'reason' => 'paid' );
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' ) ) {
			return array( 'ok' => true, 'idempotent' => true );
		}
		$sent = self::meta_string( $order, '_creature_demo_keep_link_sent_at' );
		$days = Creature_Demo_Loan_Calendar::days_between( $sent, $as_of );
		if ( $days < self::keep_lapse_days() ) {
			return array(
				'ok'     => false,
				'reason' => 'too_soon',
				'days'   => $days,
			);
		}
		$previous = self::normalize_choice( self::meta_string( $order, '_creature_demo_choice_before_keep' ) );
		$order->update_meta_data( '_creature_demo_keep_lapsed', 'yes' );
		if ( 'return' === $previous ) {
			$order->update_meta_data( '_creature_demo_choice', 'return' );
		} else {
			$order->update_meta_data( '_creature_demo_choice', '' );
		}
		$order->save();
		self::cancel_unpaid_charge( $order, 'keep' );
		Creature_Demo_Loan_Mail::send_customer( $order, 'keep_choice_lapsed', array() );
		self::note( $order, 'Keep choice lapsed. The loan continues. No choice means return.' );
		return array( 'ok' => true, 'lapsed' => true );
	}

	/**
	 * @param object $order
	 * @param string $scan
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function record_return_scan( $order, $scan, $as_of = null ) {
		$scan     = Creature_Demo_Loan_Calendar::today( $scan );
		$existing = self::meta_string( $order, '_creature_demo_return_scan_date' );
		if ( '' !== $existing ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'scan'       => $existing,
			);
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		$order->update_meta_data( '_creature_demo_return_scan_date', $scan );
		$order->save();
		// A scan is not the kit arriving. The 5 working day bill clock (terms 4.4)
		// starts only from a Kit received date that someone has entered, so the
		// scan never starts it. Without a date the shop is told, once.
		self::notify_no_received_date( $order );
		// Terms 3.3: the return dispatch date is the earlier of the proof of
		// posting and the first scan, using whichever is set.
		$dispatch  = self::dispatch_date( $order );
		$deemed_on = '' !== $due ? self::deemed_on_for( $order ) : '';
		$posted_after = '' !== $deemed_on && '' !== $dispatch && $dispatch >= $deemed_on;
		$after        = $posted_after || 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' );
		if ( $after && 'yes' !== self::meta_string( $order, '_creature_demo_deemed_paid' ) ) {
			// Terms 8.5(a): the deemed price is not paid, so it is cancelled and
			// the kit is a late return.
			return self::return_after_deemed_unpaid( $order, $scan, $dispatch, $posted_after );
		}
		if ( $after ) {
			// Deemed price already paid: 8.5(b) and (c). Inspect, then refund by hand.
			$order->update_meta_data( '_creature_demo_arrived_after_deemed', 'yes' );
			$order->save();
			self::note( $order, 'Return scan recorded after the deemed purchase was paid (' . $scan . '). Inspect the kit before any refund.' );
			return array(
				'ok'           => true,
				'after_deemed' => true,
			);
		}
		// The late fee is worked out here for the order note only. It is not
		// charged at the scan. It goes on the one bill raised when inspection is
		// recorded (terms 4.4 and Appendix C).
		$fee = Creature_Demo_Loan_Money::late_fee_pence_for_dates( $due, $dispatch );
		$order->update_status( self::STATUS_INSPECT, 'Return scan ' . $scan . ' recorded. Kit is being inspected. Return dispatch date ' . $dispatch . '.' );
		$order->save();
		return array(
			'ok'           => true,
			'late_fee'     => $fee,
			'dispatch'     => $dispatch,
			'after_deemed' => false,
		);
	}

	/**
	 * Terms 8.5(a): the kit came back after the deemed purchase date (or a
	 * deemed charge was raised before the posting date was known) and the deemed
	 * price has not been paid.
	 *
	 * The deemed charge is cancelled, so no payment link stays open and the saved
	 * card cannot be charged for it, and the kit goes through the one bill as a
	 * late return: the late fee (£24 at most) plus any damage or missing parts.
	 *
	 * No £25 code follows when the kit was posted on or after the deemed
	 * purchase date. Terms 3A.4: "We don't issue the £25 code ... if clause 8
	 * applies", and 8.5 is part of clause 8. A kit posted by the end of day 13,
	 * where the deemed charge was only raised early, is a normal return and still
	 * gets the code.
	 *
	 * @param object $order
	 * @param string $scan
	 * @param string $dispatch
	 * @param bool   $posted_after True when the dispatch date is on or after the deemed purchase date.
	 * @return array<string, mixed>
	 */
	private static function return_after_deemed_unpaid( $order, $scan, $dispatch, $posted_after ) {
		$had_charge = (int) self::meta_string( $order, '_creature_demo_deemed_order_id' ) > 0;
		self::cancel_unpaid_charge( $order, 'deemed' );
		$order->update_meta_data( '_creature_demo_deemed_raised', '' );
		$order->update_meta_data( '_creature_demo_deemed_cancelled', 'yes' );
		if ( $posted_after ) {
			$order->update_meta_data( '_creature_demo_clause8_return', 'yes' );
		}
		$order->save();
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		$fee = Creature_Demo_Loan_Money::late_fee_pence_for_dates( $due, $dispatch );
		self::note(
			$order,
			'Return scan ' . $scan . ' recorded after the deemed purchase date.' . ( $had_charge ? ' The unpaid deemed purchase was cancelled (no payment link, no saved-card charge).' : ' No deemed purchase is raised.' ) . ' The kit is a late return (terms 8.5(a)): one bill for late fees' . ( $posted_after ? ' and any damage or missing parts, with no £25 code (terms 3A.4).' : ' and any damage or missing parts.' )
		);
		$order->update_status( self::STATUS_INSPECT, 'Kit is being inspected. Return dispatch date ' . $dispatch . '.' );
		$order->save();
		return array(
			'ok'              => true,
			'after_deemed'    => true,
			'deemed_cancelled' => true,
			'late_fee'        => $fee,
			'dispatch'        => $dispatch,
		);
	}

	/**
	 * Return dispatch date (terms 3.3): the earlier of the proof of posting
	 * date and the first scan, using whichever is set. Empty when neither is.
	 *
	 * @param object $order
	 * @return string Y-m-d or ''.
	 */
	public static function dispatch_date( $order ) {
		$dates = array();
		foreach ( array( '_creature_demo_return_posted_date', '_creature_demo_return_scan_date' ) as $key ) {
			$value = self::meta_string( $order, $key );
			if ( Creature_Demo_Loan_Calendar::is_real_ymd( $value ) ) {
				$dates[] = $value;
			}
		}
		return $dates ? min( $dates ) : '';
	}

	/**
	 * Proof of posting date from the admin screen. It can be entered before or
	 * after the scan, and corrected until the bill is final.
	 *
	 * @param object $order
	 * @param string $ymd
	 * @return array<string, mixed>
	 */
	public static function record_return_posted( $order, $ymd ) {
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $ymd ) ) {
			return array(
				'ok'     => false,
				'reason' => 'bad_date',
			);
		}
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_loan',
			);
		}
		if ( '' !== self::meta_string( $order, '_creature_demo_bill_state' ) ) {
			self::note( $order, 'Proof of posting date ' . $ymd . ' ignored: the bill for this loan is already final.' );
			return array(
				'ok'     => false,
				'reason' => 'billed',
			);
		}
		if ( self::meta_string( $order, '_creature_demo_return_posted_date' ) === $ymd ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'dispatch'   => self::dispatch_date( $order ),
			);
		}
		$order->update_meta_data( '_creature_demo_return_posted_date', $ymd );
		$order->save();
		$dispatch = self::dispatch_date( $order );
		self::note( $order, 'Proof of posting date ' . $ymd . ' recorded. Return dispatch date is ' . $dispatch . ' (the earlier of the posting date and the first scan).' );
		return array(
			'ok'       => true,
			'dispatch' => $dispatch,
		);
	}

	/**
	 * Date the kit arrived back with us. Starts the 5 working day bill clock.
	 * Only ever entered by hand on the admin screen: nothing stamps it.
	 *
	 * @param object $order
	 * @param string $ymd
	 * @return array<string, mixed>
	 */
	public static function record_kit_received( $order, $ymd ) {
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $ymd ) ) {
			return array(
				'ok'     => false,
				'reason' => 'bad_date',
			);
		}
		if ( '' !== self::meta_string( $order, '_creature_demo_bill_state' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'billed',
			);
		}
		if ( self::meta_string( $order, '_creature_demo_received_date' ) === $ymd ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
			);
		}
		$order->update_meta_data( '_creature_demo_received_date', $ymd );
		$order->save();
		self::note( $order, 'Kit received date ' . $ymd . ' recorded. The bill is due within 5 working days of it.' );
		return array( 'ok' => true );
	}

	/**
	 * A scanned return has no Kit received date, so the bill clock has not
	 * started. Tell the shop once. Nothing is sent once a date is entered.
	 *
	 * @param object $order
	 * @return bool True when the notice went out on this call.
	 */
	private static function notify_no_received_date( $order ) {
		if ( Creature_Demo_Loan_Calendar::is_real_ymd( self::meta_string( $order, '_creature_demo_received_date' ) ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_received_notice_sent' ) ) {
			return false;
		}
		$order->update_meta_data( '_creature_demo_received_notice_sent', 'yes' );
		$order->save();
		Creature_Demo_Loan_Mail::send_admin( $order, 'admin_received_date_missing', array() );
		self::note( $order, 'A return scan is recorded but there is no Kit received date, so the 5 working day bill clock has not started. Enter the date the kit arrived back.' );
		return true;
	}

	/**
	 * @param object $order
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function complete_inspection( $order, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'yes' === self::meta_string( $order, '_creature_demo_inspection_done' ) ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
			);
		}
		$after_deemed = 'yes' === self::meta_string( $order, '_creature_demo_arrived_after_deemed' ) || 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' );
		$bill         = array();
		if ( ! $after_deemed ) {
			// Terms 4.4 and Appendix C: one bill for late fees, damage and
			// missing parts, raised now. A refusal (no findings) leaves the
			// inspection open so the admin can fix it and try again.
			$bill = self::bill_at_inspection( $order, $as_of );
			if ( empty( $bill['ok'] ) ) {
				return $bill;
			}
		}
		$order->update_meta_data( '_creature_demo_inspection_done', 'yes' );
		$order->save();
		if ( $after_deemed ) {
			self::note( $order, 'Inspection recorded after a deemed purchase. Refund is a separate admin action.' );
			return array(
				'ok'               => true,
				'awaiting_refund'  => true,
			);
		}
		$order->update_status( self::STATUS_RETURNED, 'Inspection complete.' );
		// Terms 3A.1(a) and 3A.5: every inspected return gets the £25 code,
		// including when no choice was made. issue_coupon() still refuses a
		// keep payment and a deemed purchase.
		$coupon = self::issue_coupon( $order, $as_of );
		if ( ! self::has_unpaid_open_charge( $order ) ) {
			$order->update_status( self::STATUS_CLOSED, 'Demo loan closed after the kit was returned.' );
		}
		$order->save();
		return array(
			'ok'     => true,
			'coupon' => $coupon,
			'bill'   => $bill,
		);
	}

	/**
	 * The one bill (terms 4.4, Appendix C). Late fees, damage and missing
	 * parts go on a single charge order with one line each.
	 *
	 * The return dispatch date sets the late-fee end date. Late fee first, then
	 * damage, then missing parts, each held to what is left under the overall
	 * cap (the keep price, never more than £212; the £24 late-fee cap is in the
	 * money class). A damage or missing-parts line needs the inspection findings
	 * (terms 6.4(b)); without them nothing is raised.
	 *
	 * Once a bill, a late-fee-only bill or a "nothing owed" decision is on the
	 * loan, no second bill can be raised.
	 *
	 * @param object $order
	 * @param mixed  $as_of
	 * @param bool   $late_only     Raise the late-fee line only (the day-5 fallback).
	 * @param string $damage_label  Name for the damage line. Default "Damage".
	 * @return array<string, mixed>
	 */
	public static function raise_bill( $order, $as_of = null, $late_only = false, $damage_label = '' ) {
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_loan',
			);
		}
		$state = self::meta_string( $order, '_creature_demo_bill_state' );
		if ( 'raised' === $state || ( '' !== $state && $late_only ) ) {
			$id = (int) self::meta_string( $order, '_creature_demo_bill_order_id' );
			$ch = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			return array(
				'ok'         => true,
				'idempotent' => true,
				'order_id'   => $id,
				'amount'     => $ch ? (int) self::meta_string( $ch, '_creature_demo_charge_amount_pence' ) : 0,
				'state'      => $state,
			);
		}
		if ( '' !== $state ) {
			return array(
				'ok'     => false,
				'reason' => 'bill_closed',
				'state'  => $state,
			);
		}
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		$keep  = self::loan_keep_price_pence( $order );
		$cap   = $keep;
		if ( function_exists( 'apply_filters' ) ) {
			$cap = (int) apply_filters( 'creature_demo_loan_damage_cap_pence', $cap, $order );
		}
		if ( $cap < 0 ) {
			$cap = $keep;
		}
		$cap = min( $cap, $keep );

		// A late-fee order raised at the scan by an earlier version still counts
		// against the cap, and the late fee is not billed a second time.
		$already  = self::billed_late_fee_pence( $order );
		$due      = self::meta_string( $order, '_creature_demo_due_date' );
		$dispatch = self::dispatch_date( $order );
		$late_ask = 0;
		if ( $already < 1 && '' !== $due && '' !== $dispatch ) {
			$late_ask = Creature_Demo_Loan_Money::late_fee_pence_for_dates( $due, $dispatch );
		}
		$late_line = min( $late_ask, max( 0, $cap - $already ) );
		$headroom  = max( 0, $cap - $already - $late_line );
		$room_all  = $headroom;

		$ask_damage  = $late_only ? 0 : max( 0, (int) self::meta_string( $order, '_creature_demo_damage_pence' ) );
		$ask_missing = $late_only ? 0 : max( 0, (int) self::meta_string( $order, '_creature_demo_missing_pence' ) );
		$damage_line = min( $ask_damage, $headroom );
		$headroom   -= $damage_line;
		$missing_line = min( $ask_missing, $headroom );
		$reduced     = ( $ask_damage + $ask_missing ) - ( $damage_line + $missing_line );
		$clamp       = array(
			'clamped'         => $reduced > 0,
			'reduced_pence'   => $reduced,
			'late_fees_pence' => $already + $late_line,
			'headroom_pence'  => $room_all,
		);
		if ( $reduced > 0 ) {
			self::note(
				$order,
				'Damage and missing-parts request of ' . Creature_Demo_Loan_Money::format_gbp( $ask_damage + $ask_missing ) . ' was clamped by ' . Creature_Demo_Loan_Money::format_gbp( $reduced ) . '. Late fees of ' . Creature_Demo_Loan_Money::format_gbp( $already + $late_line ) . ' leave ' . Creature_Demo_Loan_Money::format_gbp( $room_all ) . ' under the overall cap.'
			);
		}
		if ( '' === $dispatch && ! $late_only && $already < 1 ) {
			self::note( $order, 'No return scan or proof of posting date is recorded, so the bill has no late-fee line.' );
		}
		$total = $late_line + $damage_line + $missing_line;
		if ( $total < 1 ) {
			return array_merge(
				array(
					'ok'     => false,
					'reason' => 'no_amount',
				),
				$clamp
			);
		}
		// Terms 6.4(b): no damage or missing-parts line without what we found and
		// the photos. Nothing is created and nothing is emailed until both are in.
		if ( ( $damage_line + $missing_line ) > 0 ) {
			// Terms 6.4(b): the 5 working days run from the Kit received date. With no
			// date entered there is no clock, so no damage or missing-parts line.
			if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( self::meta_string( $order, '_creature_demo_received_date' ) ) ) {
				self::note( $order, 'Bill not sent: it has a damage or missing-parts line but no Kit received date is entered (terms 6.4(b)). Enter the date the kit arrived back, update the order and try again.' );
				return array_merge(
					array(
						'ok'     => false,
						'reason' => 'no_received_date',
					),
					$clamp
				);
			}
			$evidence = self::damage_evidence_problem( $order );
			if ( '' !== $evidence ) {
				self::note( $order, 'Bill not sent: it has a damage or missing-parts line and ' . ( 'no_findings' === $evidence ? 'the inspection findings are empty' : 'there is no photo' ) . ' (terms 6.4(b)). ' . ( 'no_findings' === $evidence ? 'Enter what you found' : 'Add at least one photo' ) . ' and try again.' );
				return array_merge(
					array(
						'ok'     => false,
						'reason' => $evidence,
					),
					$clamp
				);
			}
		}

		$lines = array();
		if ( $late_line > 0 ) {
			$lines[] = array(
				'kind'  => 'late_fee',
				'name'  => 'Late fees for the demo loan',
				'pence' => $late_line,
			);
		}
		if ( $damage_line > 0 ) {
			$label   = trim( (string) $damage_label );
			$lines[] = array(
				'kind'  => 'damage',
				'name'  => '' !== $label ? $label : 'Damage',
				'pence' => $damage_line,
			);
		}
		if ( $missing_line > 0 ) {
			$lines[] = array(
				'kind'  => 'missing_parts',
				'name'  => 'Missing parts',
				'pence' => $missing_line,
			);
		}
		$charge = self::ensure_charge_order( $order, 'bill', $total, self::bill_reason( $lines ), $as_of, $lines );
		if ( ! $charge ) {
			return array(
				'ok'     => false,
				'reason' => 'order_failed',
			);
		}
		$order->update_meta_data( '_creature_demo_bill_state', $late_only ? 'late_only' : 'raised' );
		$order->save();
		return array_merge(
			array(
				'ok'       => true,
				'amount'   => (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' ),
				'order_id' => (int) $charge->get_id(),
				'lines'    => $lines,
				'state'    => $late_only ? 'late_only' : 'raised',
			),
			$clamp
		);
	}

	/**
	 * Terms 6.4(b): a damage or missing-parts line needs the findings and at
	 * least one photo that resolves to a link (a media id that does not resolve
	 * would leave the photo line of the email blank).
	 *
	 * @param object $order
	 * @return string '' when the evidence is there, else no_findings or no_photos.
	 */
	public static function damage_evidence_problem( $order ) {
		if ( '' === self::findings_text( $order ) ) {
			return 'no_findings';
		}
		if ( '' === trim( self::photo_lines( $order ) ) ) {
			return 'no_photos';
		}
		return '';
	}

	/**
	 * @param array<int, array<string, mixed>> $lines
	 * @return string
	 */
	private static function bill_reason( $lines ) {
		$parts = array();
		foreach ( $lines as $line ) {
			$parts[] = $line['name'] . ': ' . Creature_Demo_Loan_Money::format_gbp( $line['pence'] );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Inspection is being recorded: raise the bill, or decide there is none.
	 *
	 * @param object $order
	 * @param string $as_of
	 * @return array<string, mixed>
	 */
	private static function bill_at_inspection( $order, $as_of ) {
		$state = self::meta_string( $order, '_creature_demo_bill_state' );
		if ( '' !== $state ) {
			$asked = (int) self::meta_string( $order, '_creature_demo_damage_pence' ) + (int) self::meta_string( $order, '_creature_demo_missing_pence' );
			if ( $asked > 0 && 'raised' !== $state ) {
				self::note( $order, 'Damage or missing parts were entered, but the single bill for this loan is already final (' . $state . '), so they are not charged (terms 4.4 and 6.4(b)).' );
			}
			return array(
				'ok'     => true,
				'state'  => $state,
				'closed' => true,
			);
		}
		$result = self::raise_bill( $order, $as_of );
		if ( ! empty( $result['ok'] ) ) {
			return $result;
		}
		if ( 'no_amount' === $result['reason'] ) {
			$order->update_meta_data( '_creature_demo_bill_state', 'none' );
			$order->save();
			self::note( $order, 'Inspection recorded. Nothing is owed, so no bill was raised.' );
			$result['ok']    = true;
			$result['state'] = 'none';
		}
		return $result;
	}

	/**
	 * Daily check on a kit that is back and not yet billed (terms 4.4). The
	 * bill is due within 5 working days of the kit arriving. Monday to Friday
	 * only: there is no bank-holiday table here, so the clock can only run
	 * ahead of the real one.
	 *
	 * On working day 4 the shop is emailed. The daily job runs at about 22:00,
	 * so on working day 5 with no inspection a late-fee-only bill goes out that
	 * evening, inside the 5 days, and no damage or missing-parts charge can
	 * follow, because the terms allow one bill.
	 *
	 * @param object $order
	 * @param string $as_of
	 */
	private static function check_bill_deadline( $order, $as_of ) {
		if ( '' !== self::meta_string( $order, '_creature_demo_bill_state' ) ) {
			return;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_inspection_done' ) ) {
			return;
		}
		// A kit that came back after a deemed purchase was raised is billed as a late
		// return (terms 8.5(a)). It gets the day-4 alert like any other kit. The
		// late-fee-only fallback bill stays as it was: it is not sent for these.
		$after_deemed = 'yes' === self::meta_string( $order, '_creature_demo_arrived_after_deemed' ) || 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' );
		$received     = self::meta_string( $order, '_creature_demo_received_date' );
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $received ) ) {
			// No Kit received date: no clock, no day-4 alert, no fallback bill.
			// A return scan that predates this check still gets the one notice.
			if ( '' !== self::meta_string( $order, '_creature_demo_return_scan_date' ) ) {
				self::notify_no_received_date( $order );
			}
			return;
		}
		$deadline = Creature_Demo_Loan_Calendar::add_working_days( $received, self::BILL_WORKING_DAYS, array() );
		$warn_on  = Creature_Demo_Loan_Calendar::add_working_days( $received, self::BILL_WARN_WORKING_DAY, array() );
		// The daily job runs at about 22:00 site time, so on working day 5 itself
		// the fallback bill goes out before the day ends (terms 4.4: within 5
		// working days). Comparing with > would send it on day 6.
		if ( $as_of >= $deadline && ! $after_deemed ) {
			self::raise_late_only_bill( $order, $as_of, $received, $deadline );
			return;
		}
		// For a normal kit this is only reached before the deadline. A kit that came
		// back after a deemed purchase has no fallback, so it is warned up to the deadline day.
		if ( $as_of >= $warn_on && $as_of <= $deadline && 'yes' !== self::meta_string( $order, '_creature_demo_bill_warn_sent' ) ) {
			self::send_bill_due_alert( $order, $received, $deadline );
		}
	}

	/**
	 * The day-4 shop alert, once.
	 *
	 * @param object $order
	 * @param string $received
	 * @param string $deadline
	 */
	private static function send_bill_due_alert( $order, $received, $deadline ) {
		Creature_Demo_Loan_Mail::send_admin(
			$order,
			'admin_bill_due',
			array(
				'received' => $received,
				'deadline' => $deadline,
			)
		);
		$order->update_meta_data( '_creature_demo_bill_warn_sent', 'yes' );
		$order->save();
	}

	/**
	 * Daily check on a paid deemed purchase whose kit is back.
	 *
	 * Before a deduction is raised: the shop gets the day-4 alert, like any
	 * kit, and a deduction cannot be raised after working day 5 from the entered
	 * Kit received date. After it is noticed: the shop gets one alert when the
	 * 7 days after the decision have passed, to settle it.
	 *
	 * @param object $order
	 * @param string $as_of
	 */
	private static function check_deduction_deadline( $order, $as_of ) {
		if ( 'yes' === self::meta_string( $order, '_creature_demo_refund_done' ) ) {
			return;
		}
		if ( 'noticed' === self::meta_string( $order, '_creature_demo_deduction_state' ) ) {
			self::maybe_alert_deduction_settle( $order, $as_of );
			return;
		}
		if ( '' !== self::meta_string( $order, '_creature_demo_deduction_state' ) ) {
			return;
		}
		$received = self::meta_string( $order, '_creature_demo_received_date' );
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $received ) ) {
			if ( '' !== self::meta_string( $order, '_creature_demo_return_scan_date' ) ) {
				self::notify_no_received_date( $order );
			}
			return;
		}
		$deadline = Creature_Demo_Loan_Calendar::add_working_days( $received, self::BILL_WORKING_DAYS, array() );
		$warn_on  = Creature_Demo_Loan_Calendar::add_working_days( $received, self::BILL_WARN_WORKING_DAY, array() );
		if ( $as_of >= $warn_on && $as_of <= $deadline && 'yes' !== self::meta_string( $order, '_creature_demo_bill_warn_sent' ) ) {
			self::send_bill_due_alert( $order, $received, $deadline );
		}
	}

	/**
	 * One shop alert when a noticed deduction can be settled: 7 days after the
	 * decision on a disputed deduction (upheld or waived), or 7 days after the
	 * notice when it was not disputed. An open dispute has no date yet.
	 *
	 * @param object $loan
	 * @param string $as_of
	 */
	private static function maybe_alert_deduction_settle( $loan, $as_of ) {
		if ( 'yes' === self::meta_string( $loan, '_creature_demo_deduction_settle_alert_sent' ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$charge = wc_get_order( (int) self::meta_string( $loan, '_creature_demo_damage_order_id' ) );
		if ( ! is_object( $charge ) ) {
			return;
		}
		$dispute = self::meta_string( $charge, '_creature_demo_dispute_status' );
		if ( 'open' === $dispute ) {
			return;
		}
		$from = '';
		if ( in_array( $dispute, array( 'upheld', 'waived' ), true ) ) {
			foreach ( array( '_creature_demo_decision_sent_at', '_creature_demo_dispute_decision_at' ) as $key ) {
				$value = self::meta_string( $charge, $key );
				if ( Creature_Demo_Loan_Calendar::is_real_ymd( $value ) ) {
					$from = $value;
					break;
				}
			}
		} else {
			$from = self::meta_string( $charge, '_creature_demo_charge_notice_at' );
		}
		// A waived deduction has no 7 days to wait: the shop is told straight away.
		if ( 'waived' !== $dispute && ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $from ) || $as_of < Creature_Demo_Loan_Calendar::add_days( $from, self::notice_days() ) ) ) {
			return;
		}
		$loan->update_meta_data( '_creature_demo_deduction_settle_alert_sent', 'yes' );
		$loan->save();
		Creature_Demo_Loan_Mail::send_admin( $loan, 'admin_deduction_settle_due', array() );
		self::note( $loan, 'Deduction ready to settle (waived, or 7 days since the notice or final decision). Run Refund deemed purchase (after inspection) again.' );
	}

	/**
	 * Working day 5 has come with no inspection. Bill the late fee alone
	 * and close the bill for this loan.
	 *
	 * @param object $order
	 * @param string $as_of
	 * @param string $received
	 * @param string $deadline
	 * @return array<string, mixed>
	 */
	private static function raise_late_only_bill( $order, $as_of, $received, $deadline ) {
		$result = self::raise_bill( $order, $as_of, true );
		if ( empty( $result['ok'] ) && 'no_amount' !== $result['reason'] ) {
			return $result;
		}
		if ( empty( $result['ok'] ) ) {
			$order->update_meta_data( '_creature_demo_bill_state', 'late_only' );
			$order->save();
		}
		self::note(
			$order,
			'The kit arrived on ' . $received . ' and 5 working days ended on ' . $deadline . ' with no inspection recorded. ' . ( ! empty( $result['ok'] ) ? 'A late-fee-only bill was raised.' : 'No late fee was due, so no bill was raised.' ) . ' No damage or missing-parts charge can be raised on this loan, because the terms allow one bill (terms 4.4 and 6.4(b)).'
		);
		return $result;
	}

	/**
	 * @param object $order
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function issue_coupon( $order, $as_of = null ) {
		$as_of    = Creature_Demo_Loan_Calendar::today( $as_of );
		$existing = self::meta_string( $order, '_creature_demo_coupon_code' );
		if ( '' !== $existing ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'code'       => $existing,
				'spec'       => $order->get_meta( '_creature_demo_coupon_spec' ),
			);
		}
		if ( 'return' !== self::effective_choice( $order ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_return',
			);
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' ) || 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no_coupon',
			);
		}
		// Terms 3A.4: no code if clause 8 applies. That includes a kit posted back
		// after the deemed purchase date and billed as a late return (8.5(a)).
		if ( 'yes' === self::meta_string( $order, '_creature_demo_clause8_return' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no_coupon',
			);
		}
		// Terms 3A.4: no code if any of the £25 was refunded under clause 10.4 or 11.
		if ( self::fee_was_refunded( $order ) ) {
			if ( 'yes' !== self::meta_string( $order, '_creature_demo_coupon_refused_noted' ) ) {
				$order->update_meta_data( '_creature_demo_coupon_refused_noted', 'yes' );
				$order->save();
				self::note( $order, 'No £25 code: the £25 was refunded under clause 10.4 or 11 (terms 3A.4).' );
			}
			return array(
				'ok'     => false,
				'reason' => 'fee_refunded',
			);
		}
		$spec = self::coupon_spec( $order, $as_of );
		$code = 'demo-' . (int) $order->get_id() . '-' . self::coupon_suffix( $order );
		if ( class_exists( 'WC_Coupon' ) ) {
			$coupon = new WC_Coupon();
			self::apply_coupon_spec( $coupon, $code, $spec );
		}
		$order->update_meta_data( '_creature_demo_coupon_code', $code );
		$order->update_meta_data( '_creature_demo_coupon_spec', $spec );
		$order->save();
		Creature_Demo_Loan_Mail::send_customer(
			$order,
			'coupon_issued',
			array( 'coupon' => $code )
		);
		self::note( $order, '£25 De-Reacher code issued.' );
		return array(
			'ok'   => true,
			'code' => $code,
			'spec' => $spec,
		);
	}

	/**
	 * Terms 3A.4: has any of the £25 been refunded under clause 10.4 (lost or
	 * damaged on the way out: replacement or refund) or clause 11 (cancelling
	 * the demo: full refund before dispatch, less a pro-rata part after)?
	 *
	 * WooCommerce cannot tell why an order was refunded or cancelled. A refund
	 * can be goodwill, a damage refund after an upheld dispute, the deemed
	 * refund (8.5), a faulty-kit refund (12.2) or a recall refund (9.5), and
	 * none of those blocks the code. So the code does not guess from the order
	 * status or from refund amounts. The block is an explicit mark: the admin
	 * checkbox "Refunded under 10.4/11 (no £25 code)" on the order screen, or
	 * mark_refunded_under_10_4_or_11().
	 *
	 * @param object $order
	 * @return bool
	 */
	public static function fee_was_refunded( $order ) {
		return is_object( $order ) && 'yes' === self::meta_string( $order, '_creature_demo_fee_refunded_admin' );
	}

	/**
	 * Mark a loan as refunded under clause 10.4 (lost or damaged on the way out)
	 * or clause 11 (cancelling: 11.2 before dispatch, 11.3 pro-rata after). This
	 * is what the admin checkbox does. No code is issued afterwards (terms 3A.4).
	 *
	 * @param object $order
	 * @param string $clause For the order note, for example 10.4, 11.2 or 11.3.
	 */
	public static function mark_refunded_under_10_4_or_11( $order, $clause = '' ) {
		if ( ! is_object( $order ) || self::fee_was_refunded( $order ) ) {
			return;
		}
		$order->update_meta_data( '_creature_demo_fee_refunded_admin', 'yes' );
		$order->save();
		self::note( $order, 'Marked as refunded under ' . ( '' !== $clause ? $clause : '10.4/11' ) . ': no £25 code will be issued (terms 3A.4).' );
	}

	/**
	 * @param object $order
	 * @param string $as_of
	 * @return array<string, mixed>
	 */
	public static function coupon_spec( $order, $as_of ) {
		$days = 60;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_coupon_days', 60 );
		}
		if ( $days < 1 ) {
			$days = 60;
		}
		$email = '';
		if ( is_object( $order ) && method_exists( $order, 'get_billing_email' ) ) {
			$email = strtolower( trim( (string) $order->get_billing_email() ) );
		}
		return array(
			'discount_type'       => 'fixed_cart',
			'amount'              => Creature_Demo_Loan_Money::pence_to_decimal( Creature_Demo_Loan_Money::COUPON_PENCE ),
			'individual_use'      => true,
			'usage_limit'         => 1,
			'usage_limit_per_user' => 1,
			'email_restrictions'  => array( $email ),
			'product_ids'         => self::coupon_product_ids(),
			'date_expires'        => Creature_Demo_Loan_Calendar::add_days( $as_of, $days ),
		);
	}

	/**
	 * @param object $order
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function maybe_raise_deemed( $order, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' ) ) {
			$id = (int) self::meta_string( $order, '_creature_demo_deemed_order_id' );
			return array(
				'ok'         => true,
				'idempotent' => true,
				'order_id'   => $id,
				'amount'     => $id && function_exists( 'wc_get_order' ) && wc_get_order( $id ) ? (int) self::meta_string( wc_get_order( $id ), '_creature_demo_charge_amount_pence' ) : 0,
			);
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return array( 'ok' => false, 'reason' => 'kept' );
		}
		// Terms 8.5(a): a kit that came back unpaid was billed as a late return.
		if ( 'yes' === self::meta_string( $order, '_creature_demo_deemed_cancelled' ) ) {
			return array( 'ok' => false, 'reason' => 'cancelled' );
		}
		$status = (string) $order->get_status();
		if ( in_array( $status, array( self::STATUS_KEPT, self::STATUS_CLOSED, self::STATUS_RETURNED, self::STATUS_INSPECT ), true ) ) {
			return array( 'ok' => false, 'reason' => 'status' );
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		if ( '' === $due ) {
			return array( 'ok' => false, 'reason' => 'no_due' );
		}
		$deemed_on = self::deemed_on_for( $order );
		$scan      = self::meta_string( $order, '_creature_demo_return_scan_date' );
		// Terms 8.1: posted back by the end of day 13 is not a deemed purchase.
		// The dispatch date is the earlier of the proof of posting and the scan.
		$dispatch = self::dispatch_date( $order );
		if ( '' !== $dispatch && $dispatch < $deemed_on ) {
			return array( 'ok' => false, 'reason' => ( '' !== $scan && $scan < $deemed_on ) ? 'scanned' : 'posted' );
		}
		if ( $as_of < $deemed_on ) {
			return array( 'ok' => false, 'reason' => 'too_soon' );
		}
		self::cancel_unpaid_charge( $order, 'late_fee' );
		self::cancel_unpaid_charge( $order, 'keep' );
		$paid   = (int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' );
		$amount = Creature_Demo_Loan_Money::deemed_amount_pence( self::loan_keep_price_pence( $order ), $paid );
		$charge = self::ensure_charge_order( $order, 'deemed', $amount, 'Deemed purchase of the demo kit', $as_of );
		if ( ! $charge ) {
			return array(
				'ok'     => false,
				'reason' => 'order_failed',
				'amount' => $amount,
			);
		}
		$order->update_meta_data( '_creature_demo_deemed_raised', 'yes' );
		$order->update_status( self::STATUS_DEEMED, 'Deemed purchase. A payment link was emailed. The saved card has not been charged.' );
		$order->save();
		return array(
			'ok'       => true,
			'amount'   => $amount,
			'order_id' => (int) $charge->get_id(),
		);
	}

	/**
	 * @param object $order
	 * @param mixed  $as_of
	 */
	public static function tick( $order, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
			return;
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		if ( '' === $due ) {
			return;
		}
		self::alert_open_disputes( $order, $as_of );
		$status = (string) $order->get_status();
		if ( self::STATUS_LOAN === $status && $as_of > $due ) {
			$order->update_status( self::STATUS_OVERDUE, 'Demo loan is past the due date.' );
			$status = self::STATUS_OVERDUE;
		}
		if ( self::STATUS_INSPECT === $status ) {
			// Kit is back and being inspected: watch the 5 working day bill clock.
			self::check_bill_deadline( $order, $as_of );
			return;
		}
		if ( self::STATUS_DEEMED === $status && 'yes' === self::meta_string( $order, '_creature_demo_deemed_paid' ) ) {
			// A paid deemed purchase whose kit came back: watch the deduction deadline
			// (terms 6.4(b)) and the settle date of a deduction that has been noticed.
			self::check_deduction_deadline( $order, $as_of );
			return;
		}
		$open = array( self::STATUS_LOAN, self::STATUS_OVERDUE, self::STATUS_BOOKED );
		if ( ! in_array( $status, $open, true ) ) {
			return;
		}
		self::send_due_reminders( $order, $as_of );
		self::maybe_lapse_keep( $order, $as_of );
		self::maybe_raise_deemed( $order, $as_of );
	}

	/**
	 * @param mixed $as_of
	 * @return int
	 */
	public static function run_daily( $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$limit = 50;
		if ( function_exists( 'apply_filters' ) ) {
			$limit = (int) apply_filters( 'creature_demo_loan_daily_limit', 50 );
		}
		if ( $limit < 1 ) {
			$limit = 50;
		}
		$count = 0;
		$page  = 1;
		while ( $page <= 20 ) {
			$orders = wc_get_orders(
				array(
					'limit'      => $limit,
					'page'       => $page,
					'status'     => array( self::STATUS_LOAN, self::STATUS_OVERDUE, self::STATUS_BOOKED, self::STATUS_DEEMED, self::STATUS_INSPECT ),
					'meta_query' => array(
						array(
							'key'   => '_creature_demo_loan',
							'value' => 'yes',
						),
					),
					'return'     => 'objects',
					'type'       => 'shop_order',
				)
			);
			if ( ! is_array( $orders ) || ! $orders ) {
				break;
			}
			foreach ( $orders as $order ) {
				self::tick( $order, $as_of );
				$count++;
			}
			if ( count( $orders ) < $limit ) {
				break;
			}
			$page++;
		}
		return $count;
	}

	/**
	 * When the final-decision email went out on this charge, as Y-m-d. Empty
	 * when the dispute has not been decided as upheld. A charge decided before
	 * the sent time was stored falls back to the decision date.
	 *
	 * @param object $charge
	 * @return string
	 */
	private static function decision_sent_at( $charge ) {
		if ( 'upheld' !== self::meta_string( $charge, '_creature_demo_dispute_status' ) ) {
			return '';
		}
		foreach ( array( '_creature_demo_decision_sent_at', '_creature_demo_dispute_decision_at' ) as $key ) {
			$value = self::meta_string( $charge, $key );
			if ( Creature_Demo_Loan_Calendar::is_real_ymd( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @param object $loan
	 * @param object $charge
	 * @param mixed  $as_of
	 * @return array{code: string, state: array<string, mixed>}
	 */
	public static function evaluate_charge( $loan, $charge, $as_of = null ) {
		$as_of   = Creature_Demo_Loan_Calendar::today( $as_of );
		$logged  = $charge && 'yes' === self::meta_string( $charge, '_creature_demo_charge_notice_logged' );
		$notice  = $charge ? self::meta_string( $charge, '_creature_demo_charge_notice_at' ) : '';
		// Terms 4.4(b): once a dispute is decided, the card is charged no sooner
		// than 7 days after the final-decision email, not after the first notice.
		$decided = $charge ? self::decision_sent_at( $charge ) : '';
		if ( '' !== $decided ) {
			$notice = $decided;
		}
		$days    = '' !== $notice ? Creature_Demo_Loan_Calendar::days_between( $notice, $as_of ) : 0;
		$mode    = self::stripe_mode_flags();
		$state   = array(
			'notice_logged'     => $logged,
			'days_since_notice' => $days,
			'required_days'     => self::notice_days(),
			'dispute'                   => $charge ? self::meta_string( $charge, '_creature_demo_dispute_status' ) : '',
			'paid'                      => $charge && 'yes' === self::meta_string( $charge, '_creature_demo_charge_paid' ),
			'consent'                   => is_object( $loan ) && 'yes' === self::meta_string( $loan, '_creature_demo_consent' ),
			'test_mode'                 => $mode['test_mode'],
			'test_key'                  => $mode['test_key'],
			'allow_live'                => self::live_charges_allowed(),
		);
		return array(
			'code'  => self::charge_refusal_code( $state ),
			'state' => $state,
		);
	}

	/**
	 * @param object $order Loan or charge order.
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function charge_saved_card( $order, $as_of = null ) {
		$loan   = $order;
		$charge = null;
		if ( '' !== self::meta_string( $order, '_creature_demo_parent_loan_id' ) ) {
			$charge = $order;
			$parent = self::parent_loan( $order );
			if ( $parent ) {
				$loan = $parent;
			}
		} else {
			$charge = self::open_charge_order( $order );
		}
		if ( ! $charge ) {
			self::note( $loan, self::refusal_message( 'no_notice' ) );
			return array( 'ok' => false, 'code' => 'no_notice' );
		}
		// A cancelled charge (for example an unpaid deemed purchase cancelled
		// under terms 8.5(a)) is never taken from the saved card.
		if ( method_exists( $charge, 'get_status' ) && in_array( (string) $charge->get_status(), array( 'cancelled', 'refunded', 'trash' ), true ) ) {
			self::note( $loan, self::refusal_message( 'charge_cancelled' ) );
			return array( 'ok' => false, 'code' => 'charge_cancelled' );
		}
		// A deduction from the deemed refund is taken from the money already held.
		if ( 'yes' === self::meta_string( $charge, '_creature_demo_charge_netted' ) ) {
			self::note( $loan, self::refusal_message( 'charge_netted' ) );
			return array( 'ok' => false, 'code' => 'charge_netted' );
		}
		$eval = self::evaluate_charge( $loan, $charge, $as_of );
		if ( '' !== $eval['code'] ) {
			self::note( $loan, self::refusal_message( $eval['code'] ) );
			if ( $charge !== $loan ) {
				self::note( $charge, self::refusal_message( $eval['code'] ) );
			}
			return array( 'ok' => false, 'code' => $eval['code'] );
		}
		$refs = self::saved_card_refs( $loan );
		if ( '' === $refs['customer'] || '' === $refs['payment_method'] ) {
			self::note( $loan, self::refusal_message( 'no_saved_method' ) );
			return array( 'ok' => false, 'code' => 'no_saved_method' );
		}
		$blocked = self::claim_saved_card_lock( $charge );
		if ( '' !== $blocked ) {
			self::note( $loan, self::refusal_message( $blocked ) );
			if ( $charge !== $loan ) {
				self::note( $charge, self::refusal_message( $blocked ) );
			}
			return array( 'ok' => false, 'code' => $blocked );
		}
		$amount = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
		$key    = self::idempotency_key( $charge );
		$result = Creature_Demo_Loan_Stripe::off_session_intent(
			$amount,
			$refs['customer'],
			$refs['payment_method'],
			$key,
			array(
				'loan_id'         => (string) $loan->get_id(),
				'charge_order_id' => (string) $charge->get_id(),
				'kind'            => self::meta_string( $charge, '_creature_demo_charge_kind' ),
			)
		);
		if ( 'authentication_required' === $result['code'] ) {
			self::release_charge_lock( $charge, 'saved_card' );
			$charge->update_meta_data( '_creature_demo_authentication_required', 'yes' );
			$charge->save();
			self::note( $loan, 'Charge needs authentication. A payment link was emailed instead. The saved card was not charged.' );
			Creature_Demo_Loan_Mail::send_customer(
				$loan,
				'charge_authentication_required',
				array(
					'amount' => Creature_Demo_Loan_Money::format_gbp( $amount ),
					'reason' => self::meta_string( $charge, '_creature_demo_charge_notice_reason' ),
					'link'   => self::payment_link( $charge ),
				)
			);
			return array( 'ok' => false, 'code' => 'authentication_required' );
		}
		if ( empty( $result['ok'] ) ) {
			self::release_charge_lock( $charge, 'saved_card' );
			self::note( $loan, self::refusal_message( $result['code'] ) );
			if ( ! empty( $result['declined'] ) ) {
				self::send_charge_declined( $loan, $charge, $amount );
			}
			return array( 'ok' => false, 'code' => $result['code'] );
		}
		if ( '' !== $result['id'] ) {
			$charge->update_meta_data( '_creature_demo_payment_intent', $result['id'] );
			if ( 'deemed' === self::meta_string( $charge, '_creature_demo_charge_kind' ) ) {
				$loan->update_meta_data( '_creature_demo_deemed_payment_intent', $result['id'] );
			}
			$charge->save();
		}
		$note = 'Off-session charge succeeded.';
		if ( '' !== $result['id'] ) {
			$note .= ' PaymentIntent ' . $result['id'] . '.';
		}
		self::note( $loan, $note );
		if ( $charge !== $loan ) {
			self::note( $charge, $note );
		}
		self::on_charge_paid( $loan, $charge );
		return array(
			'ok'   => true,
			'code' => 'succeeded',
			'id'   => $result['id'],
		);
	}

	/**
	 * Terms 4.5: a saved-card charge was declined. Email the payment link. The
	 * amount is still owed. Once per charge order, so a second decline on a
	 * retry does not email the rider again.
	 *
	 * @param object $loan
	 * @param object $charge
	 * @param int    $amount
	 * @return bool True when the email went out on this call.
	 */
	public static function send_charge_declined( $loan, $charge, $amount ) {
		if ( 'yes' === self::meta_string( $charge, '_creature_demo_decline_notified' ) ) {
			return false;
		}
		$charge->update_meta_data( '_creature_demo_decline_notified', 'yes' );
		$charge->save();
		self::note( $loan, 'The saved card was declined. A payment link was emailed. The amount is still owed.' );
		Creature_Demo_Loan_Mail::send_customer(
			$loan,
			'charge_declined',
			array(
				'amount'   => Creature_Demo_Loan_Money::format_gbp( $amount ),
				'price'    => Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $loan ) ),
				'reason'   => self::meta_string( $charge, '_creature_demo_charge_notice_reason' ),
				'link'     => self::payment_link( $charge ),
				'due'      => self::meta_string( $loan, '_creature_demo_due_date' ),
				'findings' => self::findings_text( $loan ),
				'photos'   => self::photo_lines( $loan ),
			)
		);
		return true;
	}

	/**
	 * @param object $charge
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function mark_disputed( $charge, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'open' === self::meta_string( $charge, '_creature_demo_dispute_status' ) ) {
			return array( 'ok' => true, 'idempotent' => true );
		}
		$charge->update_meta_data( '_creature_demo_dispute_status', 'open' );
		$charge->update_meta_data( '_creature_demo_dispute_opened_at', $as_of );
		$charge->save();
		self::note( $charge, 'Charge marked disputed. The saved card will not be charged while the dispute is open.' );
		return array( 'ok' => true );
	}

	/**
	 * @param object   $charge
	 * @param string   $decision upheld|waived
	 * @param mixed    $as_of
	 * @param int|null $amount_pence
	 * @param string   $reasons      Reasons for decision (Appendix D). Required: a blank value refuses the decision.
	 * @return array<string, mixed>
	 */
	public static function record_dispute_decision( $charge, $decision, $as_of = null, $amount_pence = null, $reasons = '' ) {
		$as_of    = Creature_Demo_Loan_Calendar::today( $as_of );
		$decision = 'upheld' === $decision ? 'upheld' : 'waived';
		// 4.4(b): the first decision is the final decision. A second one would also work out the
		// released amount from the already-cut amount. Nothing is stored or sent.
		$stored = self::meta_string( $charge, '_creature_demo_dispute_status' );
		if ( 'upheld' === $stored || 'waived' === $stored ) {
			self::note( $charge, 'Decision not recorded or sent: a final decision (' . $stored . ') is already recorded on this charge (terms 4.4(b)).' );
			return array(
				'ok'     => false,
				'reason' => 'already_decided',
			);
		}
		// Appendix D: "Our decision: [outcome and amount], because [reasons]". No reasons, no decision:
		// nothing is stored, nothing is sent and the dispute stays open.
		$reasons = is_string( $reasons ) ? trim( $reasons ) : '';
		if ( '' === $reasons ) {
			self::note( $charge, 'Decision not recorded or sent: Reasons for decision is blank. Enter the reasons, then record the decision again.' );
			return array(
				'ok'     => false,
				'reason' => 'no_decision_reasons',
			);
		}
		// A deemed-refund deduction has its own decision email: no pay link and no saved-card line
		// (it comes out of the refund). While that wording is empty nothing is recorded or sent,
		// and the shop is told once, as for deduction_notice_deemed. The dispute stays open.
		$deduction = 'yes' === self::meta_string( $charge, '_creature_demo_charge_netted' );
		if ( $deduction && '' === trim( Creature_Demo_Loan_Copy::text( 'dispute_decision_deemed' ) ) ) {
			$loan = self::parent_loan( $charge );
			if ( $loan && 'yes' !== self::meta_string( $loan, '_creature_demo_decision_wording_notice_sent' ) ) {
				$loan->update_meta_data( '_creature_demo_decision_wording_notice_sent', 'yes' );
				$loan->save();
				Creature_Demo_Loan_Mail::send_admin( $loan, 'admin_dispute_decision_missing', array() );
			}
			self::note( $charge, 'Decision not recorded or sent: the dispute_decision_deemed wording is empty, so the decision email for a deemed-refund deduction cannot be sent. Add the wording, then record the decision again.' );
			if ( $loan ) {
				self::note( $loan, 'Dispute decision not recorded: the dispute_decision_deemed wording is empty. Add the wording, then record the decision again.' );
			}
			return array(
				'ok'     => false,
				'reason' => 'no_decision_wording',
			);
		}
		$opened   = self::meta_string( $charge, '_creature_demo_dispute_opened_at' );
		$within   = true;
		if ( Creature_Demo_Loan_Calendar::is_ymd( $opened ) ) {
			$within = Creature_Demo_Loan_Calendar::days_between( $opened, $as_of ) <= 14;
		}
		$capped  = false;
		$changed = false;
		$amount  = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
		// What the rider was told at the notice: a waiver or a cut releases the difference.
		$noticed = (int) self::meta_string( $charge, '_creature_demo_charge_notice_amount' );
		if ( $noticed < 1 ) {
			$noticed = $amount;
		}
		if ( null !== $amount_pence && 'upheld' === $decision ) {
			$notice = (int) self::meta_string( $charge, '_creature_demo_charge_notice_amount' );
			if ( $notice < 1 ) {
				$notice = $amount;
			}
			$asked = max( 0, (int) $amount_pence );
			if ( $notice > 0 && $asked > $notice ) {
				$asked  = $notice;
				$capped = true;
			}
			if ( $asked !== $amount ) {
				$changed = true;
				$amount  = $asked;
				$charge->update_meta_data( '_creature_demo_idempotency_key', '' );
				$charge->update_meta_data( '_creature_demo_charge_amount_pence', $amount );
				// The late-fee line comes first on a bill, so a cut below it cuts it.
				if ( (int) self::meta_string( $charge, '_creature_demo_charge_late_pence' ) > $amount ) {
					$charge->update_meta_data( '_creature_demo_charge_late_pence', $amount );
				}
				if ( method_exists( $charge, 'set_total' ) ) {
					$charge->set_total( Creature_Demo_Loan_Money::pence_to_decimal( $amount ) );
				}
			}
		}
		$charge->update_meta_data( '_creature_demo_dispute_status', $decision );
		$charge->update_meta_data( '_creature_demo_dispute_decision_at', $as_of );
		$charge->update_meta_data( '_creature_demo_dispute_decision_reasons', $reasons );
		$charge->update_meta_data( '_creature_demo_dispute_decision_within_14_days', $within ? 'yes' : 'no' );
		$charge->save();
		if ( $changed ) {
			$loan = self::parent_loan( $charge );
			if ( $loan && $deduction ) {
				// A deemed-refund deduction is not re-noticed as a damage charge (that email has a pay link and
				// says the saved card will be charged). dispute_decision_deemed below carries the amount.
				// Keep the notice record in step without sending anything.
				$charge->update_meta_data( '_creature_demo_charge_notice_at', $as_of );
				$charge->update_meta_data( '_creature_demo_charge_notice_amount', (int) $amount );
				$charge->save();
			} elseif ( $loan ) {
				$kind   = self::meta_string( $charge, '_creature_demo_charge_kind' );
				$reason = self::meta_string( $charge, '_creature_demo_charge_notice_reason' );
				self::log_charge_notice( $loan, $charge, $amount, $reason, $as_of, $kind );
			}
		}
		$loan   = self::parent_loan( $charge );
		$target = $loan ? $loan : $charge;
		$amount = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
		// What is finally kept: nothing on a waiver, otherwise the (possibly cut) amount.
		$kept     = 'waived' === $decision ? 0 : $amount;
		$released = 'waived' === $decision ? $noticed : max( 0, $noticed - $amount );
		// The deduction email names three outcomes; the plain charge email keeps upheld or waived.
		$outcome = $decision;
		if ( $deduction && 'upheld' === $decision && $released > 0 ) {
			$outcome = 'upheld in part';
		}
		$row    = Creature_Demo_Loan_Mail::send_customer(
			$target,
			$deduction ? 'dispute_decision_deemed' : 'dispute_decision',
			array(
				'decision' => $deduction ? $outcome : $decision,
				'amount'   => Creature_Demo_Loan_Money::format_gbp( $amount ),
				// Refunded back on a waiver (all of it) or a partial uphold (the part cut).
				'released' => Creature_Demo_Loan_Money::format_gbp( $released ),
				// The amount finally kept (£0.00 on a waiver).
				'kept'     => Creature_Demo_Loan_Money::format_gbp( $kept ),
				'decision_reasons' => $reasons,
				'reason'   => self::meta_string( $charge, '_creature_demo_charge_notice_reason' ),
				// A deduction comes out of the refund, so its decision email never has a pay link.
				'link'     => $deduction ? '' : self::payment_link( $charge ),
				'price'    => $loan ? Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $loan ) ) : '',
				'findings' => $loan ? self::findings_text( $loan ) : '',
				'photos'   => $loan ? self::photo_lines( $loan ) : '',
			)
		);
		// The 7 days before an upheld charge can be taken run from this email.
		$charge->update_meta_data( '_creature_demo_decision_sent_at', $as_of );
		$charge->save();
		if ( ! is_array( $row ) || '' === (string) $row['to'] ) {
			self::note( $charge, 'The decision email had nowhere to go. The 7 days still count from ' . $as_of . '.' );
		}
		self::note( $charge, 'Dispute decision recorded: ' . $decision . ( $within ? ' (within 14 days).' : ' (after 14 days).' ) . ( $capped ? ' Amount capped at the original notice.' : '' ) );
		return array(
			'ok'       => true,
			'timely'   => $within,
			'decision' => $decision,
			'capped'   => $capped,
			'amount'   => (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' ),
		);
	}

	/**
	 * @param object $loan
	 * @param object $charge
	 * @return array<string, mixed>
	 */
	public static function on_charge_paid( $loan, $charge ) {
		$key = '_creature_demo_paid_applied_' . (int) $charge->get_id();
		if ( 'yes' === self::meta_string( $loan, $key ) ) {
			return array( 'ok' => true, 'idempotent' => true );
		}
		$charge->update_meta_data( '_creature_demo_charge_paid', 'yes' );
		$charge->save();
		self::release_current_charge_lock( $charge );
		$loan->update_meta_data( $key, 'yes' );
		$loan->save();
		self::complete_charge_payment( $charge, self::payment_intent_id( $charge ) );
		$kind   = self::meta_string( $charge, '_creature_demo_charge_kind' );
		$amount = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
		if ( 'late_fee' === $kind ) {
			$paid = (int) self::meta_string( $loan, '_creature_demo_late_fees_paid_pence' ) + $amount;
			$loan->update_meta_data( '_creature_demo_late_fees_paid_pence', $paid );
			$loan->save();
			self::note( $loan, 'Late fees paid: ' . Creature_Demo_Loan_Money::format_gbp( $amount ) . '.' );
			return array( 'ok' => true, 'kind' => 'late_fee' );
		}
		if ( 'bill' === $kind ) {
			// The late-fee line of the bill counts as late fees paid, but only up
			// to what was actually charged. A dispute decision can cut the bill
			// below its late-fee line, and the deemed amount and the £212 aggregate
			// cap use this figure, so it must not be the original line.
			$late = min( (int) self::meta_string( $charge, '_creature_demo_charge_late_pence' ), $amount );
			if ( $late > 0 ) {
				$loan->update_meta_data( '_creature_demo_late_fees_paid_pence', (int) self::meta_string( $loan, '_creature_demo_late_fees_paid_pence' ) + $late );
				$loan->save();
			}
			self::note( $loan, 'Bill paid: ' . Creature_Demo_Loan_Money::format_gbp( $amount ) . '.' );
			return array( 'ok' => true, 'kind' => 'bill' );
		}
		if ( 'keep' === $kind ) {
			return self::close_as_kept( $loan );
		}
		if ( 'deemed' === $kind ) {
			if ( 'yes' === self::meta_string( $loan, '_creature_demo_deemed_cancelled' ) ) {
				// Paid after it was cancelled (terms 8.5(a)). Do not sell the unit.
				self::note( $loan, 'A payment of ' . Creature_Demo_Loan_Money::format_gbp( $amount ) . ' arrived on the deemed purchase after it was cancelled for a late return. It is not treated as a purchase. Refund it by hand.' );
				return array( 'ok' => true, 'kind' => 'deemed', 'cancelled' => true );
			}
			$pi = self::payment_intent_id( $charge );
			if ( '' !== $pi && '' === self::meta_string( $loan, '_creature_demo_deemed_payment_intent' ) ) {
				$loan->update_meta_data( '_creature_demo_deemed_payment_intent', $pi );
				$loan->save();
			}
			return self::mark_deemed_paid( $loan, $amount );
		}
		return array( 'ok' => true, 'kind' => $kind );
	}

	/**
	 * @param object $loan
	 * @return array<string, mixed>
	 */
	public static function close_as_kept( $loan ) {
		if ( self::STATUS_KEPT === (string) $loan->get_status() && 'yes' === self::meta_string( $loan, '_creature_demo_unit_sold' ) ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'coupon'     => self::meta_string( $loan, '_creature_demo_coupon_code' ),
			);
		}
		$loan->update_meta_data( '_creature_demo_keep_paid', 'yes' );
		$loan->update_meta_data( '_creature_demo_unit_sold', 'yes' );
		$loan->update_meta_data( '_creature_demo_fleet_status', 'sold' );
		$loan->update_status( self::STATUS_KEPT, 'Keep price paid. Loan closed with no return and no coupon.' );
		$loan->save();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'creature_demo_loan_unit_removed_from_fleet', (int) $loan->get_id(), $loan, 'keep' );
		}
		return array(
			'ok'     => true,
			'coupon' => self::meta_string( $loan, '_creature_demo_coupon_code' ),
		);
	}

	/**
	 * @param object $loan
	 * @param int    $amount
	 * @return array<string, mixed>
	 */
	public static function mark_deemed_paid( $loan, $amount, $as_of = null ) {
		if ( 'yes' === self::meta_string( $loan, '_creature_demo_deemed_paid' ) ) {
			return array( 'ok' => true, 'idempotent' => true );
		}
		$loan->update_meta_data( '_creature_demo_deemed_paid', 'yes' );
		// The 30 days in terms 8.5(b) and (c) run from this date.
		$loan->update_meta_data( '_creature_demo_deemed_paid_at', Creature_Demo_Loan_Calendar::today( $as_of ) );
		$loan->update_meta_data( '_creature_demo_deemed_paid_pence', (int) $amount );
		$loan->update_meta_data( '_creature_demo_unit_sold', 'yes' );
		$loan->update_meta_data( '_creature_demo_fleet_status', 'sold' );
		if ( self::STATUS_DEEMED !== (string) $loan->get_status() ) {
			$loan->update_status( self::STATUS_DEEMED, 'Deemed purchase paid. No coupon is issued.' );
		} else {
			self::note( $loan, 'Deemed purchase paid. No coupon is issued.' );
		}
		$loan->save();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'creature_demo_loan_unit_removed_from_fleet', (int) $loan->get_id(), $loan, 'deemed' );
		}
		return array(
			'ok'     => true,
			'coupon' => self::meta_string( $loan, '_creature_demo_coupon_code' ),
		);
	}

	/**
	 * @param object $loan
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function refund_deemed( $loan, $as_of = null ) {
		$as_of = Creature_Demo_Loan_Calendar::today( $as_of );
		if ( 'yes' === self::meta_string( $loan, '_creature_demo_refund_done' ) ) {
			return array(
				'ok'         => true,
				'idempotent' => true,
				'amount'     => (int) self::meta_string( $loan, '_creature_demo_refund_pence' ),
			);
		}
		if ( 'yes' !== self::meta_string( $loan, '_creature_demo_deemed_paid' ) ) {
			self::note( $loan, 'Refund refused: the deemed purchase has not been paid.' );
			return array( 'ok' => false, 'reason' => 'not_paid' );
		}
		if ( '' === self::meta_string( $loan, '_creature_demo_return_scan_date' ) && 'yes' !== self::meta_string( $loan, '_creature_demo_arrived_after_deemed' ) ) {
			self::note( $loan, 'Refund refused: the kit has not been recorded back.' );
			return array( 'ok' => false, 'reason' => 'no_return' );
		}
		if ( 'yes' !== self::meta_string( $loan, '_creature_demo_inspection_done' ) ) {
			self::note( $loan, 'Refund refused: inspection is not recorded.' );
			return array( 'ok' => false, 'reason' => 'no_inspection' );
		}
		$ever = 'yes' === self::meta_string( $loan, '_creature_demo_ever_chose_keep' );
		if ( ! Creature_Demo_Loan_Money::refund_applies( $ever, self::refund_includes_keep_choosers() ) ) {
			self::note( $loan, 'Refund refused: this rider chose to keep the kit, and the post-deemed refund flag is off.' );
			return array( 'ok' => false, 'reason' => 'keep_choice' );
		}
		// Terms 8.5(c): the kit has to be posted within 30 days of the payment.
		// After that the kit is the rider's and is not taken back as a demo return.
		$dispatch = self::dispatch_date( $loan );
		$paid_at  = self::meta_string( $loan, '_creature_demo_deemed_paid_at' );
		if ( Creature_Demo_Loan_Calendar::is_real_ymd( $paid_at ) && '' !== $dispatch && $dispatch > Creature_Demo_Loan_Calendar::add_days( $paid_at, self::DEEMED_REFUND_DAYS ) ) {
			self::note( $loan, 'Refund refused: the kit was posted more than 30 days after the deemed purchase was paid (' . $paid_at . '), so it is the rider\'s and is not taken back as a demo return (terms 8.5(c)).' );
			return array( 'ok' => false, 'reason' => 'after_30_days' );
		}
		$keep   = self::loan_keep_price_pence( $loan );
		$paid   = (int) self::meta_string( $loan, '_creature_demo_deemed_paid_pence' );
		$late   = (int) self::meta_string( $loan, '_creature_demo_late_fees_paid_pence' );
		$base   = Creature_Demo_Loan_Money::post_deemed_refund_pence( $keep, $paid, $late, 0 );
		$state  = self::meta_string( $loan, '_creature_demo_deduction_state' );
		if ( '' !== $state ) {
			return self::advance_deduction( $loan, $as_of );
		}
		// What a damage or missing-parts deduction can be, under the £24 and keep-price caps.
		$asked = (int) self::meta_string( $loan, '_creature_demo_damage_pence' ) + (int) self::meta_string( $loan, '_creature_demo_missing_pence' );
		$held  = $base - Creature_Demo_Loan_Money::post_deemed_refund_pence( $keep, $paid, $late, $asked );
		if ( $held < 1 ) {
			return self::finish_deemed_refund( $loan, $base );
		}
		// Terms 6.4(b): the 5 working days run from the Kit received date the shop
		// entered. With no date there is no clock, so no deduction can be raised.
		$received = self::meta_string( $loan, '_creature_demo_received_date' );
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $received ) ) {
			self::note( $loan, 'Refund not sent: it has a damage or missing-parts deduction of ' . Creature_Demo_Loan_Money::format_gbp( $held ) . ' but no Kit received date is entered (terms 6.4(b)). Enter the date the kit arrived back and update the order, or clear the deduction, then try again.' );
			return array( 'ok' => false, 'reason' => 'no_received_date', 'held' => $held );
		}
		// Terms 8.5(b) and 6.4: a deduction is billed like any damage or missing-parts
		// charge, with the findings and the photos, and the rider has 7 days to pay or
		// dispute. Nothing is taken silently.
		$problem = self::damage_evidence_problem( $loan );
		if ( '' !== $problem ) {
			self::note( $loan, 'Refund not sent: it has a damage or missing-parts deduction and ' . ( 'no_findings' === $problem ? 'the inspection findings are empty' : 'there is no photo' ) . ' (terms 6.4(b) and 8.5(b)). ' . ( 'no_findings' === $problem ? 'Enter what you found' : 'Add at least one photo' ) . ' or clear the deduction, then try again.' );
			return array( 'ok' => false, 'reason' => $problem );
		}
		// Terms 6.4(b): the deduction has to be raised within 5 working days of the
		// Kit received date. The day-5 itself is still in time.
		$deadline = Creature_Demo_Loan_Calendar::add_working_days( $received, self::BILL_WORKING_DAYS, array() );
		if ( $as_of > $deadline ) {
			self::note( $loan, 'Damage or missing-parts deduction of ' . Creature_Demo_Loan_Money::format_gbp( $held ) . ' refused: it was raised after ' . $deadline . ', which is 5 working days after the Kit received date (' . $received . '), so nothing can be deducted (terms 6.4(b)). The refund goes ahead without it.' );
			$loan->update_meta_data( '_creature_demo_deduction_state', 'blocked' );
			$loan->save();
			return array_merge( self::finish_deemed_refund( $loan, $base ), array( 'deduction_blocked' => true ) );
		}
		// While the deduction notice has no wording it cannot be sent, so nothing is
		// refunded, held or billed. The shop is told, once, like the 11.3 label gap.
		if ( '' === trim( Creature_Demo_Loan_Copy::text( 'deduction_notice_deemed' ) ) ) {
			if ( 'yes' !== self::meta_string( $loan, '_creature_demo_deduction_wording_notice_sent' ) ) {
				$loan->update_meta_data( '_creature_demo_deduction_wording_notice_sent', 'yes' );
				$loan->save();
				Creature_Demo_Loan_Mail::send_admin( $loan, 'admin_deduction_notice_missing', array() );
			}
			self::note( $loan, 'Refund not sent: it has a damage or missing-parts deduction of ' . Creature_Demo_Loan_Money::format_gbp( $held ) . ' and the deduction_notice_deemed wording is empty, so the notice cannot be sent. Add the wording, or clear the deduction, then try again.' );
			return array( 'ok' => false, 'reason' => 'no_deduction_wording', 'held' => $held );
		}
		if ( ! self::raise_deduction_notice( $loan, $held, $base - $held, $as_of ) ) {
			return array( 'ok' => false, 'reason' => 'order_failed' );
		}
		return self::advance_deduction( $loan, $as_of );
	}

	/**
	 * Send the damage or missing-parts deduction notice (the same bill email and
	 * the same 7-day pay-or-dispute rule as any bill) and hold the amount back
	 * from the refund. The money is already held, so the saved card is never
	 * charged for it.
	 *
	 * @param object $loan
	 * @param int    $held        Deduction in pence, within the caps.
	 * @param int    $undisputed  What is refunded now: the refund less the deduction.
	 * @param string $as_of
	 * @return bool
	 */
	private static function raise_deduction_notice( $loan, $held, $undisputed, $as_of ) {
		$ask_damage = max( 0, (int) self::meta_string( $loan, '_creature_demo_damage_pence' ) );
		$damage     = min( $ask_damage, $held );
		$missing    = $held - $damage;
		$lines      = array();
		if ( $damage > 0 ) {
			$lines[] = array(
				'kind'  => 'damage',
				'name'  => 'Damage',
				'pence' => $damage,
			);
		}
		if ( $missing > 0 ) {
			$lines[] = array(
				'kind'  => 'missing_parts',
				'name'  => 'Missing parts',
				'pence' => $missing,
			);
		}
		$charge = self::ensure_charge_order( $loan, 'damage', $held, self::bill_reason( $lines ), $as_of, $lines, 'deduction_deemed' );
		if ( ! $charge ) {
			return false;
		}
		// The deduction comes out of the refund. The saved card is not charged for it.
		$charge->update_meta_data( '_creature_demo_charge_netted', 'yes' );
		$charge->save();
		$loan->update_meta_data( '_creature_demo_deduction_state', 'noticed' );
		$loan->update_meta_data( '_creature_demo_deduction_pence', $held );
		$loan->update_meta_data( '_creature_demo_deduction_undisputed_pence', $undisputed );
		$loan->update_meta_data( '_creature_demo_bill_state', 'deduction' );
		$loan->save();
		self::note( $loan, 'Damage or missing-parts deduction of ' . Creature_Demo_Loan_Money::format_gbp( $held ) . ' billed with the findings and photos. The rider has 7 days to pay or dispute. The undisputed refund of ' . Creature_Demo_Loan_Money::format_gbp( $undisputed ) . ' goes now and the deduction is held. Run the refund action again after 7 days, or after a dispute decision.' );
		return true;
	}

	/**
	 * Move a noticed deduction forward: refund the undisputed amount, then hold
	 * or settle the deduction through the dispute outcome.
	 *
	 * @param object $loan
	 * @param string $as_of
	 * @return array<string, mixed>
	 */
	private static function advance_deduction( $loan, $as_of ) {
		$state = self::meta_string( $loan, '_creature_demo_deduction_state' );
		if ( 'blocked' === $state ) {
			$base = Creature_Demo_Loan_Money::post_deemed_refund_pence(
				self::loan_keep_price_pence( $loan ),
				(int) self::meta_string( $loan, '_creature_demo_deemed_paid_pence' ),
				(int) self::meta_string( $loan, '_creature_demo_late_fees_paid_pence' ),
				0
			);
			return array_merge( self::finish_deemed_refund( $loan, $base ), array( 'deduction_blocked' => true ) );
		}
		$held       = (int) self::meta_string( $loan, '_creature_demo_deduction_pence' );
		$undisputed = (int) self::meta_string( $loan, '_creature_demo_deduction_undisputed_pence' );
		if ( 'yes' !== self::meta_string( $loan, '_creature_demo_undisputed_refunded' ) ) {
			if ( $undisputed > 0 ) {
				$sent = self::send_deemed_refund( $loan, $undisputed, 'u' );
				if ( empty( $sent['ok'] ) ) {
					return $sent;
				}
			}
			$loan->update_meta_data( '_creature_demo_undisputed_refunded', 'yes' );
			$loan->update_meta_data( '_creature_demo_refund_pence', $undisputed );
			$loan->save();
			self::note( $loan, 'Undisputed refund confirmed: ' . Creature_Demo_Loan_Money::format_gbp( $undisputed ) . '. ' . Creature_Demo_Loan_Money::format_gbp( $held ) . ' is held until the deduction is settled.' );
			return array(
				'ok'      => true,
				'pending' => true,
				'amount'  => $undisputed,
				'held'    => $held,
			);
		}
		$charge = function_exists( 'wc_get_order' ) ? wc_get_order( (int) self::meta_string( $loan, '_creature_demo_damage_order_id' ) ) : null;
		if ( ! is_object( $charge ) ) {
			self::note( $loan, 'The deduction notice order cannot be found, so the held ' . Creature_Demo_Loan_Money::format_gbp( $held ) . ' is not settled.' );
			return array( 'ok' => false, 'reason' => 'deduction_missing', 'held' => $held );
		}
		$keep_back = $held;
		$why       = '';
		$dispute   = self::meta_string( $charge, '_creature_demo_dispute_status' );
		if ( 'yes' === self::meta_string( $charge, '_creature_demo_charge_paid' ) ) {
			$keep_back = 0;
			$why       = 'The rider paid the deduction notice, so the held amount is released.';
		} elseif ( 'open' === $dispute ) {
			return array( 'ok' => false, 'reason' => 'deduction_disputed', 'held' => $held );
		} elseif ( 'waived' === $dispute ) {
			$keep_back = 0;
			$why       = 'The dispute was waived, so the held amount is released.';
		} elseif ( 'upheld' === $dispute ) {
			$sent_on = self::decision_sent_at( $charge );
			if ( '' === $sent_on || Creature_Demo_Loan_Calendar::days_between( $sent_on, $as_of ) < self::notice_days() ) {
				return array( 'ok' => false, 'reason' => 'deduction_decision_wait', 'held' => $held );
			}
			$keep_back = min( $held, (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' ) );
			$why       = 'The dispute was upheld for ' . Creature_Demo_Loan_Money::format_gbp( $keep_back ) . '.';
		} else {
			$noticed = self::meta_string( $charge, '_creature_demo_charge_notice_at' );
			if ( '' === $noticed || Creature_Demo_Loan_Calendar::days_between( $noticed, $as_of ) < self::notice_days() ) {
				return array( 'ok' => false, 'reason' => 'deduction_pending', 'held' => $held );
			}
			$why = 'Not paid or disputed within 7 days, so the deduction is taken from the refund.';
		}
		$release = $held - $keep_back;
		if ( $release > 0 ) {
			$sent = self::send_deemed_refund( $loan, $release, 'r' );
			if ( empty( $sent['ok'] ) ) {
				return $sent;
			}
		}
		// Settled. Close the unpaid notice so its link cannot be paid on top.
		self::cancel_unpaid_charge( $loan, 'damage' );
		$total = $undisputed + $release;
		$loan->update_meta_data( '_creature_demo_deduction_state', 'settled' );
		$loan->update_meta_data( '_creature_demo_refund_done', 'yes' );
		$loan->update_meta_data( '_creature_demo_refund_pence', $total );
		$loan->save();
		self::note( $loan, $why . ' Post-deemed refund confirmed: ' . Creature_Demo_Loan_Money::format_gbp( $total ) . ' in all, with ' . Creature_Demo_Loan_Money::format_gbp( $keep_back ) . ' deducted.' );
		return array(
			'ok'       => true,
			'amount'   => $total,
			'deducted' => $keep_back,
			'released' => $release,
		);
	}

	/**
	 * The refund with no deduction (or a blocked one): send it and mark it done.
	 *
	 * @param object $loan
	 * @param int    $refund
	 * @return array<string, mixed>
	 */
	private static function finish_deemed_refund( $loan, $refund ) {
		if ( $refund > 0 ) {
			$sent = self::send_deemed_refund( $loan, $refund, '' );
			if ( empty( $sent['ok'] ) ) {
				return $sent;
			}
		}
		$loan->update_meta_data( '_creature_demo_refund_done', 'yes' );
		$loan->update_meta_data( '_creature_demo_refund_pence', $refund );
		$loan->save();
		self::note( $loan, 'Post-deemed refund confirmed: ' . Creature_Demo_Loan_Money::format_gbp( $refund ) . '.' );
		return array( 'ok' => true, 'amount' => $refund );
	}

	/**
	 * One Stripe refund against the deemed purchase payment. A refund that is
	 * not confirmed is recorded and nothing is marked done.
	 *
	 * @param object $loan
	 * @param int    $refund
	 * @param string $stage '' for the whole refund, 'u' undisputed, 'r' release.
	 * @return array<string, mixed>
	 */
	private static function send_deemed_refund( $loan, $refund, $stage ) {
		$pi = self::meta_string( $loan, '_creature_demo_deemed_payment_intent' );
		if ( '' === $pi || ! self::stripe_charge_allowed() ) {
			$loan->update_meta_data( '_creature_demo_refund_recorded_pence', $refund );
			$loan->save();
			self::note( $loan, 'Refund recorded, not sent: ' . Creature_Demo_Loan_Money::format_gbp( $refund ) . '. Stripe has not confirmed it.' );
			return array(
				'ok'     => false,
				'reason' => 'not_sent',
				'amount' => $refund,
			);
		}
		$key    = 'demo-loan-refund-' . (int) $loan->get_id() . ( '' !== $stage ? '-' . $stage : '' ) . '-' . $refund;
		$result = Creature_Demo_Loan_Stripe::refund( $pi, $refund, $key );
		if ( empty( $result['ok'] ) ) {
			$loan->update_meta_data( '_creature_demo_refund_recorded_pence', $refund );
			$loan->save();
			self::note( $loan, 'Stripe refund was not confirmed (' . $result['code'] . '). It is not marked done.' );
			return array(
				'ok'     => false,
				'reason' => 'not_confirmed',
				'amount' => $refund,
			);
		}
		self::note( $loan, 'Stripe refund submitted.' . ( '' !== $result['id'] ? ' Refund ' . $result['id'] . '.' : '' ) );
		return array( 'ok' => true );
	}

	/**
	 * A paid charge order cannot be paid again from its checkout link.
	 *
	 * @param mixed  $needed
	 * @param object $order
	 * @return mixed
	 */
	public static function filter_needs_payment( $needed, $order = null ) {
		if ( ! is_object( $order ) || ! self::demo_customer_mail_order( $order ) ) {
			return $needed;
		}
		if ( '' === self::meta_string( $order, '_creature_demo_parent_loan_id' ) && 'yes' !== self::meta_string( $order, '_creature_demo_charge_order' ) ) {
			return $needed;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_charge_paid' ) ) {
			return false;
		}
		if ( self::keep_payment_on_crash_hold( $order ) ) {
			return false;
		}
		if ( self::saved_card_lock_active( $order ) ) {
			return false;
		}
		$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		if ( in_array( $status, array( 'processing', 'completed', 'refunded', 'cancelled' ), true ) ) {
			return false;
		}
		return $needed;
	}

	public static function on_payment_complete( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( '' === self::meta_string( $order, '_creature_demo_parent_loan_id' ) ) {
			return;
		}
		$loan = self::parent_loan( $order );
		if ( ! $loan ) {
			return;
		}
		self::on_charge_paid( $loan, $order );
	}

	/**
	 * @param object $order
	 * @return string
	 */
	public static function choice_url( $order, $choice ) {
		$choice = self::normalize_choice( $choice );
		$args   = array(
			'creature_demo_choice' => $choice,
			'creature_demo_order'  => (int) $order->get_id(),
			'creature_demo_token'  => self::meta_string( $order, '_creature_demo_action_token' ),
		);
		$base = '';
		if ( function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_page_permalink' ) ) {
			$base = wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
		}
		$url = function_exists( 'add_query_arg' ) ? add_query_arg( $args, $base ) : $base;
		if ( function_exists( 'wp_nonce_url' ) ) {
			$url = wp_nonce_url( $url, 'creature_demo_choice_' . (int) $order->get_id() );
		}
		return $url;
	}

	/**
	 * @param object $order
	 * @param int    $user_id
	 * @param bool   $nonce_ok
	 * @param string $token
	 * @return bool
	 */
	public static function authorize_choice_request( $order, $user_id, $nonce_ok, $token ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		$owner = (int) $order->get_user_id() === (int) $user_id && (int) $user_id > 0;
		if ( $nonce_ok && $owner ) {
			return true;
		}
		$stored = self::meta_string( $order, '_creature_demo_action_token' );
		$token  = (string) $token;
		if ( '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
			return false;
		}
		return $owner;
	}

	public static function maybe_handle_choice_request() {
		if ( empty( $_GET['creature_demo_choice'] ) || empty( $_GET['creature_demo_order'] ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order_id = function_exists( 'absint' ) ? absint( $_GET['creature_demo_order'] ) : (int) $_GET['creature_demo_order'];
		$choice   = isset( $_GET['creature_demo_choice'] ) ? self::unslash_string( $_GET['creature_demo_choice'] ) : '';
		if ( function_exists( 'sanitize_key' ) ) {
			$choice = sanitize_key( $choice );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? self::unslash_string( $_GET['_wpnonce'] ) : '';
		$token = isset( $_GET['creature_demo_token'] ) ? self::unslash_string( $_GET['creature_demo_token'] ) : '';
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$nonce_ok = function_exists( 'wp_verify_nonce' ) ? (bool) wp_verify_nonce( $nonce, 'creature_demo_choice_' . $order_id ) : false;
		$user_id  = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! self::authorize_choice_request( $order, $user_id, $nonce_ok, $token ) ) {
			return;
		}
		if ( 'keep' === self::normalize_choice( $choice ) && '1' !== self::request_value( 'creature_demo_confirm' ) ) {
			return;
		}
		$result = self::choose( $order, $choice, null );
		if ( ! empty( $result['ok'] ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( Creature_Demo_Loan_Copy::text( 'choice_recorded' ), 'success' );
		}
	}

	/**
	 * @param object $order
	 */
	public static function render_my_account_choices( $order ) {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return;
		}
		if ( ! self::order_is_demo( $order ) ) {
			return;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$admin   = function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
		if ( $user_id && (int) $order->get_user_id() !== $user_id && ! $admin ) {
			return;
		}
		echo '<section class="creature-demo-loan-choices"><h2>' . self::esc( Creature_Demo_Loan_Copy::text( 'my_account_heading' ) ) . '</h2>';
		self::render_choice_actions( $order );
		echo '</section>';
	}

	/**
	 * The crash notice (terms 3A.3) and the choice buttons for one loan. Used by the
	 * order's own page and by the Demo loan tab. Keep is not offered while the crash
	 * flag is on, and then crash_hold_notice says why (empty wording shows nothing).
	 *
	 * @param object $order
	 */
	private static function render_choice_actions( $order ) {
		$as_of = Creature_Demo_Loan_Calendar::today( null );
		foreach ( array( 'return', 'keep' ) as $choice ) {
			$current = self::normalize_choice( self::meta_string( $order, '_creature_demo_choice' ) );
			if ( 'keep' === $choice && self::crash_hold( $order ) ) {
				$notice = trim( Creature_Demo_Loan_Copy::text( 'crash_hold_notice' ) );
				if ( '' !== $notice ) {
					echo '<p class="creature-demo-loan-crash-notice">' . self::esc( $notice ) . '</p>';
				}
				continue;
			}
			if ( ! self::choice_allowed( $order, $choice, $as_of ) && $current !== $choice ) {
				continue;
			}
			$url = self::choice_url( $order, $choice );
			if ( 'keep' === $choice && self::keep_confirm_pending( $order ) ) {
				$amount  = Creature_Demo_Loan_Money::keep_amount_pence(
					self::loan_keep_price_pence( $order ),
					(int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' )
				);
				$confirm = function_exists( 'add_query_arg' ) ? add_query_arg( array( 'creature_demo_confirm' => '1' ), $url ) : $url;
				echo '<p>' . self::esc( Creature_Demo_Loan_Copy::text( 'keep_confirm', array( 'amount' => Creature_Demo_Loan_Money::format_gbp( $amount ) ) ) ) . '</p>';
				echo '<p><a class="button" href="' . self::esc_url( $confirm ) . '">' . self::esc( Creature_Demo_Loan_Copy::text( 'button_keep' ) ) . '</a></p>';
				continue;
			}
			echo '<p><a class="button" href="' . self::esc_url( $url ) . '">' . self::esc( Creature_Demo_Loan_Copy::text( 'button_' . $choice ) ) . '</a></p>';
		}
	}

	/** The My account endpoint for the Demo loan tab. */
	const ACCOUNT_ENDPOINT = 'demo-loan';

	/** Option: the plugin version the rewrite rules were last flushed for. */
	const REWRITE_OPTION = 'creature_demo_loan_rewrite_version';

	/**
	 * Register the Demo loan endpoint (on init). The flush is separate and runs later, on wp_loaded,
	 * once every plugin has registered its own rules.
	 */
	public static function register_account_endpoint() {
		if ( function_exists( 'add_rewrite_endpoint' ) ) {
			$mask = ( defined( 'EP_ROOT' ) ? EP_ROOT : 1 ) | ( defined( 'EP_PAGES' ) ? EP_PAGES : 4096 );
			add_rewrite_endpoint( self::ACCOUNT_ENDPOINT, $mask );
		}
	}

	/**
	 * Flush the rewrite rules once per plugin version (a flush on every request is slow). Hooked to
	 * wp_loaded, after the endpoint is registered on init.
	 */
	public static function maybe_flush_rewrite_rules() {
		$version = defined( 'CREATURE_DEMO_LOAN_VERSION' ) ? CREATURE_DEMO_LOAN_VERSION : '';
		if ( function_exists( 'get_option' ) && function_exists( 'update_option' ) && function_exists( 'flush_rewrite_rules' ) && get_option( self::REWRITE_OPTION, '' ) !== $version ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, $version, false );
		}
	}

	/**
	 * @param array<string, string> $vars
	 * @return array<string, string>
	 */
	public static function filter_query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::ACCOUNT_ENDPOINT;
		}
		return $vars;
	}

	/**
	 * Add the Demo loan item to the My account menu, above Log out.
	 *
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public static function filter_account_menu( $items ) {
		$label = trim( Creature_Demo_Loan_Copy::text( 'my_account_heading' ) );
		if ( ! is_array( $items ) || '' === $label ) {
			return $items;
		}
		// Only a customer with at least one demo order sees the item.
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( ! self::account_has_demo_order( $user_id ) ) {
			return $items;
		}
		$out   = array();
		$added = false;
		foreach ( $items as $key => $text ) {
			if ( 'customer-logout' === $key && ! $added ) {
				$out[ self::ACCOUNT_ENDPOINT ] = $label;
				$added                         = true;
			}
			$out[ $key ] = $text;
		}
		if ( ! $added ) {
			$out[ self::ACCOUNT_ENDPOINT ] = $label;
		}
		return $out;
	}

	/**
	 * @param string $title
	 * @return string
	 */
	public static function filter_account_title( $title ) {
		$label = trim( Creature_Demo_Loan_Copy::text( 'my_account_heading' ) );
		return '' !== $label ? $label : $title;
	}

	/**
	 * The logged-in customer's active loans: on loan, overdue or return booked, with a due date.
	 *
	 * @param int $user_id
	 * @return object[]
	 */
	public static function account_loans( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$active = array( self::STATUS_LOAN, self::STATUS_OVERDUE, self::STATUS_BOOKED );
		$found  = wc_get_orders(
			array(
				'customer' => $user_id,
				'limit'    => -1,
				'status'   => $active,
				'return'   => 'objects',
				'type'     => 'shop_order',
			)
		);
		$loans = array();
		foreach ( is_array( $found ) ? $found : array() as $order ) {
			// The query is not trusted to filter: owner, loan flag, status and due date are checked here.
			if ( ! is_object( $order ) || (int) $order->get_user_id() !== $user_id || 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
				continue;
			}
			if ( ! in_array( (string) $order->get_status(), $active, true ) || ! Creature_Demo_Loan_Calendar::is_real_ymd( self::meta_string( $order, '_creature_demo_due_date' ) ) ) {
				continue;
			}
			$loans[] = $order;
		}
		return $loans;
	}

	/**
	 * Every demo order of this customer, owner-checked (the query is not trusted to filter).
	 *
	 * @param int $user_id
	 * @return object[]
	 */
	private static function account_demo_orders( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$found = wc_get_orders(
			array(
				'customer' => $user_id,
				'limit'    => -1,
				'return'   => 'objects',
				'type'     => 'shop_order',
			)
		);
		$out = array();
		foreach ( is_array( $found ) ? $found : array() as $order ) {
			if ( is_object( $order ) && (int) $order->get_user_id() === $user_id && self::order_is_demo( $order ) ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * Whether the Demo loan menu item is shown: the customer has at least one demo order, in any state.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function account_has_demo_order( $user_id ) {
		return (bool) self::account_demo_orders( $user_id );
	}

	/**
	 * What the Demo loan tab lists, each with how much to show:
	 * - active: on loan, overdue or return booked, with a due date. Dates, keep price and choices.
	 * - deemed: demo-deemed with a due date. Dates and keep price, no choices.
	 * - inspect: demo-inspect. Listed, no choices.
	 * - booked: a paid booking the kit has not reached yet (no due date). Keep price only.
	 *
	 * @param int $user_id
	 * @return array<int, array{order: object, mode: string}>
	 */
	public static function account_entries( $user_id ) {
		$entries = array();
		foreach ( self::account_demo_orders( $user_id ) as $order ) {
			$status   = (string) $order->get_status();
			$has_due  = Creature_Demo_Loan_Calendar::is_real_ymd( self::meta_string( $order, '_creature_demo_due_date' ) );
			$is_loan  = 'yes' === self::meta_string( $order, '_creature_demo_loan' );
			$mode     = '';
			if ( self::STATUS_INSPECT === $status ) {
				$mode = 'inspect';
			} elseif ( self::STATUS_DEEMED === $status && $has_due ) {
				$mode = 'deemed';
			} elseif ( $is_loan && $has_due && in_array( $status, array( self::STATUS_LOAN, self::STATUS_OVERDUE, self::STATUS_BOOKED ), true ) ) {
				$mode = 'active';
			} elseif ( $is_loan && ! $has_due && in_array( $status, array( 'processing', 'on-hold' ), true ) ) {
				$mode = 'booked';
			}
			if ( '' !== $mode ) {
				$entries[] = array(
					'order' => $order,
					'mode'  => $mode,
				);
			}
		}
		return $entries;
	}

	/**
	 * The Demo loan tab in My account (terms 3A.1 and 3.2): each active loan with its due date,
	 * the last day of grace, the deemed purchase date and the keep price, the choice buttons, and
	 * a link to the order. Booked kits not yet delivered show the keep price only; deemed kits show
	 * the dates and keep price with no choices; kits being inspected are listed with no choices.
	 * A row whose label slot has no wording yet is left out rather than shown with a made-up label.
	 */
	public static function render_account_tab() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		echo '<section class="creature-demo-loan-account"><h2>' . self::esc( Creature_Demo_Loan_Copy::text( 'my_account_heading' ) ) . '</h2>';
		$entries = self::account_entries( $user_id );
		if ( ! $entries ) {
			$none = trim( Creature_Demo_Loan_Copy::text( 'my_account_no_loans' ) );
			if ( '' !== $none ) {
				echo '<p>' . self::esc( $none ) . '</p>';
			}
			echo '</section>';
			return;
		}
		foreach ( $entries as $entry ) {
			$order = $entry['order'];
			$mode  = $entry['mode'];
			$due   = self::meta_string( $order, '_creature_demo_due_date' );
			$rows  = array(
				'my_account_label_due'        => Creature_Demo_Loan_Calendar::format_uk( $due ),
				'my_account_label_grace_end'  => Creature_Demo_Loan_Calendar::format_uk( Creature_Demo_Loan_Calendar::grace_end( $due, self::grace_days() ) ),
				'my_account_label_deemed_on'  => Creature_Demo_Loan_Calendar::format_uk( self::deemed_on_for( $order ) ),
				'my_account_label_keep_price' => Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $order ) ),
			);
			if ( 'booked' === $mode ) {
				$rows = array( 'my_account_label_keep_price' => $rows['my_account_label_keep_price'] );
			} elseif ( 'inspect' === $mode ) {
				$rows = array();
			}
			echo '<div class="creature-demo-loan-account-loan">';
			$number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id();
			$base   = function_exists( 'wc_get_endpoint_url' ) && function_exists( 'wc_get_page_permalink' ) ? wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) ) : '';
			echo '<p><a href="' . self::esc_url( $base ) . '">#' . self::esc( $number ) . '</a></p>';
			foreach ( $rows as $slot => $value ) {
				$label = trim( Creature_Demo_Loan_Copy::text( $slot ) );
				if ( '' !== $label ) {
					echo '<p><strong>' . self::esc( $label ) . '</strong> ' . self::esc( $value ) . '</p>';
				}
			}
			// Only a loan that is out gets choices. Booked, deemed and inspecting kits do not.
			if ( 'active' === $mode ) {
				self::render_choice_actions( $order );
			}
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * Terms 11.1 and Appendix B: extra content on the order confirmation for a
	 * demo order, with the record of the consent and the rider's answer to the
	 * express request. The wording is the booking_confirmation copy slot. While
	 * that slot is empty nothing is added.
	 *
	 * @param object $order
	 * @param bool   $sent_to_admin
	 * @param bool   $plain_text
	 * @param mixed  $email
	 */
	public static function render_booking_confirmation( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! self::is_booking_email( $email ) || ! is_object( $order ) || ! self::order_is_demo( $order ) ) {
			return;
		}
		// Both the on-hold and the processing email can follow one order. The
		// booking block goes in the first one only. A resend by hand does not
		// repeat it.
		if ( 'yes' === self::meta_string( $order, '_creature_demo_booking_block_sent' ) ) {
			return;
		}
		$record = self::consent_record( $order );
		$text   = Creature_Demo_Loan_Copy::text(
			'booking_confirmation',
			array(
				'order_id'        => (string) $order->get_id(),
				// Terms: "Your keep price is on your booking confirmation". Formatted like the other emails.
				'price'           => Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $order ) ),
				'consent'         => $record['wording'],
				'consent_version' => $record['label'],
				'consent_at'      => $record['date'],
				'express_answer'  => self::express_answer( $order ),
			)
		);
		if ( '' === trim( $text ) ) {
			return;
		}
		// With no "Send my kit now" label, or no recorded answer, the rider was
		// never asked. Leave out the whole sentence, including the Yes / No
		// follow-ups, rather than print "…: ." with nothing after it.
		if ( '' === self::express_label() || '' === self::express_answer( $order ) ) {
			$text = self::drop_express_answer_paragraph( $text );
			if ( '' === trim( $text ) ) {
				return;
			}
		}
		// The wording says the terms PDF and the cancellation form are attached.
		// Leave it out unless both are really there, and tell the shop.
		$missing = self::missing_confirmation_files();
		if ( $missing ) {
			$text = str_replace(
				array( 'Your demo terms (version 5) and our model cancellation form are attached to this email. ', ' or use the attached model cancellation form' ),
				'',
				$text
			);
			Creature_Demo_Loan_Mail::send_admin( $order, 'admin_attachments_missing', array( 'missing' => implode( ', ', $missing ) ) );
			self::note( $order, 'The booking confirmation went out without the attachment wording because no file can be attached for: ' . implode( ', ', $missing ) . '.' );
		}
		$order->update_meta_data( '_creature_demo_booking_block_sent', 'yes' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		if ( $plain_text ) {
			echo "\n" . $text . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="creature-demo-loan-booking"><p>' . nl2br( self::esc( $text ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Remove the paragraph that starts "Your answer to “Send my kit now”:" from
	 * the booking confirmation text. Nothing else is changed.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function drop_express_answer_paragraph( $text ) {
		$out = preg_replace( '/Your answer to “Send my kit now”:[^\n]*(?:\n\n+|\n|$)/u', '', (string) $text );
		return is_string( $out ) ? $out : (string) $text;
	}

	/**
	 * Labels of the confirmation files (terms PDF, cancellation form) that are
	 * not set or cannot be attached. Empty when both can be.
	 *
	 * @return string[]
	 */
	private static function missing_confirmation_files() {
		$report  = self::confirmation_file_report();
		$missing = array();
		foreach ( array( 'terms_pdf', 'cancellation_form' ) as $key ) {
			if ( empty( $report[ $key ]['path'] ) ) {
				$missing[] = $report[ $key ]['label'];
			}
		}
		return $missing;
	}

	/**
	 * The rider's answer to the express request at checkout (Appendix B), as
	 * stored in `_creature_demo_express_request`. Empty when it was not recorded.
	 *
	 * @param object $order
	 * @return string
	 */
	public static function express_answer( $order ) {
		$value = strtolower( self::meta_string( $order, '_creature_demo_express_request' ) );
		if ( in_array( $value, array( 'yes', '1', 'true' ), true ) ) {
			return 'Yes';
		}
		if ( in_array( $value, array( 'no', '0', 'false' ), true ) ) {
			return 'No';
		}
		return '';
	}

	/**
	 * @param mixed $email
	 * @return bool
	 */
	private static function is_booking_email( $email ) {
		return is_object( $email ) && isset( $email->id ) && in_array( (string) $email->id, self::BOOKING_EMAIL_IDS, true );
	}

	/**
	 * Attach the terms PDF and the model cancellation form to the booking
	 * confirmation of a demo order. Nothing is attached when no file is set.
	 *
	 * @param mixed       $attachments
	 * @param string      $email_id
	 * @param object|null $object
	 * @param mixed       $email
	 * @return mixed
	 */
	public static function filter_booking_attachments( $attachments, $email_id = '', $object = null, $email = null ) {
		unset( $email );
		if ( ! in_array( (string) $email_id, self::BOOKING_EMAIL_IDS, true ) || ! is_object( $object ) || ! self::order_is_demo( $object ) ) {
			return $attachments;
		}
		$attachments = is_array( $attachments ) ? $attachments : array();
		foreach ( self::confirmation_attachment_paths() as $path ) {
			if ( ! in_array( $path, $attachments, true ) ) {
				$attachments[] = $path;
			}
		}
		return $attachments;
	}

	/**
	 * Every customer email for a demo order carries the shop's Reply-To,
	 * including WooCommerce's own order emails.
	 *
	 * @param mixed       $headers
	 * @param string      $email_id
	 * @param object|null $object
	 * @param mixed       $email
	 * @return mixed
	 */
	public static function filter_email_headers( $headers, $email_id = '', $object = null, $email = null ) {
		unset( $email );
		if ( 0 !== strpos( (string) $email_id, 'customer_' ) || ! self::demo_customer_mail_order( $object ) ) {
			return $headers;
		}
		$headers = is_string( $headers ) ? $headers : '';
		if ( false !== stripos( $headers, 'reply-to:' ) ) {
			return $headers;
		}
		if ( '' !== $headers && "\n" !== substr( $headers, -1 ) ) {
			$headers .= "\r\n";
		}
		return $headers . 'Reply-To: ' . Creature_Demo_Loan_Mail::REPLY_TO . "\r\n";
	}

	/**
	 * What is set for the booking confirmation attachments, and whether each
	 * file can be attached. The files come from the creature_demo_loan_confirmation_files
	 * filter (the config screen fills it): an array keyed terms_pdf and
	 * cancellation_form, each a media id or a file path.
	 *
	 * @return array<string, array{label: string, set: bool, path: string}>
	 */
	public static function confirmation_file_report() {
		$raw = array();
		if ( function_exists( 'apply_filters' ) ) {
			$raw = apply_filters( 'creature_demo_loan_confirmation_files', array() );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$labels = array(
			'terms_pdf'         => 'Terms PDF (version 5)',
			'cancellation_form' => 'Model cancellation form',
		);
		$report = array();
		foreach ( $labels as $key => $label ) {
			$value          = isset( $raw[ $key ] ) ? $raw[ $key ] : '';
			$report[ $key ] = array(
				'label' => $label,
				'set'   => is_scalar( $value ) && '' !== trim( (string) $value ),
				'path'  => self::resolve_attachment_path( $value ),
			);
		}
		foreach ( $raw as $key => $value ) {
			if ( isset( $labels[ $key ] ) ) {
				continue;
			}
			$report[ 'extra_' . $key ] = array(
				'label' => 'Extra file',
				'set'   => is_scalar( $value ) && '' !== trim( (string) $value ),
				'path'  => self::resolve_attachment_path( $value ),
			);
		}
		return $report;
	}

	/**
	 * @return string[] Absolute paths that can be attached.
	 */
	public static function confirmation_attachment_paths() {
		$paths = array();
		foreach ( self::confirmation_file_report() as $row ) {
			if ( '' !== $row['path'] && ! in_array( $row['path'], $paths, true ) ) {
				$paths[] = $row['path'];
			}
		}
		return $paths;
	}

	/**
	 * A media id or a file path, turned into a path that is safe to attach. The
	 * file must exist, be a document type, and sit inside the uploads folder (or
	 * a folder added with creature_demo_loan_attachment_roots). Anything else is
	 * refused, so a path typed into the settings screen cannot attach a server
	 * file such as wp-config.php to a customer email.
	 *
	 * @param mixed $value
	 * @return string Real path, or '' when it cannot be attached.
	 */
	private static function resolve_attachment_path( $value ) {
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[0-9]+$/', trim( $value ) ) ) ) {
			$id = (int) $value;
			if ( $id < 1 || ! function_exists( 'get_attached_file' ) ) {
				return '';
			}
			$path = get_attached_file( $id );
		} elseif ( is_string( $value ) ) {
			$path = trim( $value );
		} else {
			return '';
		}
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return '';
		}
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return '';
		}
		if ( ! in_array( strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ), array( 'pdf', 'doc', 'docx', 'odt', 'rtf' ), true ) ) {
			return '';
		}
		foreach ( self::attachment_roots() as $root ) {
			$root_real = is_string( $root ) ? realpath( $root ) : false;
			if ( false !== $root_real && 0 === strpos( $real, rtrim( $root_real, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
				return $real;
			}
		}
		return '';
	}

	/**
	 * @return string[]
	 */
	private static function attachment_roots() {
		$roots = array();
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload = wp_upload_dir( null, false );
			if ( is_array( $upload ) && ! empty( $upload['basedir'] ) && empty( $upload['error'] ) ) {
				$roots[] = $upload['basedir'];
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$roots = apply_filters( 'creature_demo_loan_attachment_roots', $roots );
		}
		return is_array( $roots ) ? $roots : array();
	}

	/**
	 * Admin notice when a booking confirmation file is not set, or is set and
	 * cannot be attached. The confirmation still goes out, with no attachment.
	 */
	public static function render_attachment_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) || ! self::notice_screen_ok() ) {
			return;
		}
		$missing    = array();
		$unreadable = array();
		foreach ( self::confirmation_file_report() as $key => $row ) {
			if ( 0 === strpos( $key, 'extra_' ) ) {
				continue;
			}
			if ( ! $row['set'] ) {
				$missing[] = $row['label'];
			} elseif ( '' === $row['path'] ) {
				$unreadable[] = $row['label'];
			}
		}
		if ( ! $missing && ! $unreadable ) {
			return;
		}
		$message = 'De-Reacher demo loan: the booking confirmation goes out without these attachments.';
		if ( $missing ) {
			$message .= ' No file is set for: ' . implode( ', ', $missing ) . '.';
		}
		if ( $unreadable ) {
			$message .= ' Set but cannot be attached (missing, not a document, or outside the uploads folder): ' . implode( ', ', $unreadable ) . '.';
		}
		$message .= ' Set them under WooCommerce → Demo loan, as a media library ID or a file path inside the uploads folder.';
		echo '<div class="notice notice-warning"><p>' . self::esc( $message ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * @return bool
	 */
	private static function notice_screen_ok() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return true;
		}
		$screen = get_current_screen();
		if ( ! is_object( $screen ) || empty( $screen->id ) ) {
			return true;
		}
		foreach ( array( 'woocommerce', 'wc-', 'shop_order' ) as $needle ) {
			if ( false !== strpos( (string) $screen->id, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param mixed $actions
	 * @return array<string, string>
	 */
	public static function filter_order_actions( $actions, $order = null ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}
		$charge_order = is_object( $order ) && ( 'yes' === self::meta_string( $order, '_creature_demo_charge_order' ) || '' !== self::meta_string( $order, '_creature_demo_parent_loan_id' ) );
		if ( ! self::order_is_demo( $order ) && ! $charge_order ) {
			return $actions;
		}
		$actions['creature_demo_start_loan']           = 'Start demo loan';
		$actions['creature_demo_charge_saved_card']    = 'Charge saved card';
		$actions['creature_demo_mark_disputed']        = 'Mark charge disputed';
		$actions['creature_demo_uphold_dispute']       = 'Dispute decision: uphold charge';
		$actions['creature_demo_waive_dispute']        = 'Dispute decision: waive charge';
		$actions['creature_demo_record_scan']          = 'Record return scan (today)';
		$actions['creature_demo_complete_inspection']  = 'Complete demo inspection';
		$actions['creature_demo_refund_deemed']        = 'Refund deemed purchase (after inspection)';
		$actions['creature_demo_raise_damage']         = 'Email a damage or missing-parts charge';
		return $actions;
	}

	/**
	 * @param object $order
	 */
	public static function action_start( $order ) {
		$dispatch = self::posted_date( 'creature_demo_dispatch_date' );
		if ( '' === $dispatch ) {
			$dispatch = Creature_Demo_Loan_Calendar::today( null );
		}
		$delivery = self::posted_date( 'creature_demo_delivery_date' );
		if ( 'yes' === self::meta_string( $order, '_creature_demo_loan' ) && '' !== self::meta_string( $order, '_creature_demo_due_date' ) ) {
			if ( '' !== $delivery ) {
				self::correct_delivery_date( $order, $delivery );
			}
			return;
		}
		self::start_loan( $order, $dispatch, $delivery );
	}

	/**
	 * @param object $order
	 */
	public static function action_charge( $order ) {
		self::charge_saved_card( $order, null );
	}

	/**
	 * @param object $order
	 */
	public static function action_dispute( $order ) {
		$charge = self::charge_from_context( $order );
		if ( ! $charge ) {
			self::note( $order, self::refusal_message( 'no_notice' ) );
			return;
		}
		self::mark_disputed( $charge, null );
	}

	/**
	 * @param object $order
	 */
	public static function action_uphold( $order ) {
		$charge = self::charge_from_context( $order );
		if ( $charge ) {
			// Blank amount: uphold in full. A filled amount is the amount kept (what the shop keeps
			// of the noticed charge); the rest is released. Waive takes no amount.
			$raw    = trim( self::posted_field( 'creature_demo_decision_amount' ) );
			$amount = null;
			if ( '' !== $raw ) {
				$amount = self::parse_pounds( $raw );
				if ( null === $amount || $amount < 1 ) {
					self::note( $charge, 'Decision not recorded or sent: the amount to keep must be a sum in pounds above £0.00 (for example 12.50). Leave it blank to uphold in full, or use Waive.' );
					return;
				}
			}
			self::record_dispute_decision( $charge, 'upheld', null, $amount, self::posted_field( 'creature_demo_decision_reasons' ) );
		}
	}

	/**
	 * @param object $order
	 */
	public static function action_waive( $order ) {
		$charge = self::charge_from_context( $order );
		if ( $charge ) {
			self::record_dispute_decision( $charge, 'waived', null, null, self::posted_field( 'creature_demo_decision_reasons' ) );
		}
	}

	/**
	 * @param object $order
	 */
	public static function action_scan( $order ) {
		$scan = self::posted_date( 'creature_demo_return_scan_date' );
		if ( '' === $scan ) {
			$scan = Creature_Demo_Loan_Calendar::today( null );
		}
		// The posting date and the received date go in first so the dispatch
		// date and the bill clock are right when the scan is recorded.
		self::save_return_dates_from_post( $order );
		self::record_return_scan( $order, $scan, null );
	}

	/**
	 * Admin checkboxes on the order screen: crash reported (terms 3A.3) and
	 * refunded under 10.4/11 (terms 3A.4). An unticked box is not posted, so the
	 * hidden creature_demo_flags_present field says the boxes were on the form.
	 *
	 * @param object $order
	 */
	private static function save_flags_from_post( $order ) {
		if ( ! isset( $_POST['creature_demo_flags_present'] ) ) {
			return;
		}
		foreach ( array(
			'creature_demo_crash_reported'  => array( '_creature_demo_crash_reported', 'Crash, impact or failure reported: the keep choice is hidden until the inspection is recorded (terms 3A.3).', 'Crash flag cleared.' ),
			'creature_demo_refunded_10411' => array( '_creature_demo_fee_refunded_admin', 'Marked as refunded under 10.4/11: no £25 code will be issued (terms 3A.4).', 'Refunded under 10.4/11 mark cleared.' ),
		) as $field => $row ) {
			$want = isset( $_POST[ $field ] ) && '1' === self::unslash_string( $_POST[ $field ] ) ? 'yes' : '';
			if ( $want === self::meta_string( $order, $row[0] ) ) {
				continue;
			}
			$order->update_meta_data( $row[0], $want );
			$order->save();
			self::note( $order, '' !== $want ? $row[1] : $row[2] );
		}
	}

	/**
	 * Proof of posting date and kit received date from the order screen.
	 *
	 * @param object $order
	 */
	private static function save_return_dates_from_post( $order ) {
		$posted = self::posted_date( 'creature_demo_return_posted_date' );
		if ( '' !== $posted ) {
			self::record_return_posted( $order, $posted );
		}
		$received = self::posted_date( 'creature_demo_received_date' );
		if ( '' !== $received ) {
			self::record_kit_received( $order, $received );
		}
	}

	/**
	 * @param object $order
	 */
	public static function action_inspect( $order ) {
		self::save_flags_from_post( $order );
		self::save_return_dates_from_post( $order );
		self::save_damage_from_post( $order );
		self::complete_inspection( $order, null );
	}

	/**
	 * @param object $order
	 */
	public static function action_refund( $order ) {
		self::save_damage_from_post( $order );
		self::refund_deemed( $order, null );
	}

	/**
	 * Raise the one bill now: late fees, damage and missing parts. Notice and
	 * payment link only. The saved card is not charged here. A second bill is
	 * refused (terms 4.4).
	 *
	 * @param object $order
	 */
	public static function action_damage( $order ) {
		self::save_return_dates_from_post( $order );
		self::save_damage_from_post( $order );
		$result = self::raise_bill( $order, null );
		if ( empty( $result['ok'] ) && 'bill_closed' === $result['reason'] ) {
			self::note( $order, 'Bill refused: the single bill for this loan is already final (' . $result['state'] . ').' );
		}
	}

	/**
	 * Raise the one bill with a single damage amount. The bill's caps and
	 * findings rule apply (see raise_bill()). The amount replaces the damage
	 * amount saved on the order. A loan whose bill is already a late-fee-only
	 * bill, or was decided as "nothing owed", is refused: the terms allow one
	 * bill (4.4).
	 *
	 * @param object $order
	 * @param int    $amount_pence
	 * @param string $reason Name for the damage line.
	 * @param mixed  $as_of
	 * @return array<string, mixed>
	 */
	public static function raise_damage_charge( $order, $amount_pence, $reason, $as_of = null ) {
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_loan' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_loan',
			);
		}
		$state = self::meta_string( $order, '_creature_demo_bill_state' );
		if ( '' !== $state && 'raised' !== $state ) {
			return array(
				'ok'     => false,
				'reason' => 'bill_closed',
				'state'  => $state,
			);
		}
		if ( '' === $state ) {
			$order->update_meta_data( '_creature_demo_damage_pence', max( 0, (int) $amount_pence ) );
			$order->save();
		}
		return self::raise_bill( $order, $as_of, false, $reason );
	}

	/**
	 * Late fees already on this loan. The late-fee charge order wins, then
	 * fees already paid, then zero.
	 *
	 * @param object $order
	 * @return int
	 */
	private static function billed_late_fee_pence( $order ) {
		$id = (int) self::meta_string( $order, '_creature_demo_late_fee_order_id' );
		if ( $id > 0 && function_exists( 'wc_get_order' ) ) {
			$charge = wc_get_order( $id );
			if ( is_object( $charge ) ) {
				$amount = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
				if ( $amount > 0 ) {
					return $amount;
				}
			}
		}
		return max( 0, (int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' ) );
	}

	/**
	 * What the inspection found (terms 6.4(b)). Goes into the bill as {findings}.
	 *
	 * @param object $order
	 * @return string
	 */
	public static function findings_text( $order ) {
		return trim( self::meta_string( $order, '_creature_demo_findings' ) );
	}

	/**
	 * Photo slots as saved: up to 6 entries, each a media id or an http(s) URL.
	 *
	 * @param object $order
	 * @return string[]
	 */
	public static function photo_slots( $order ) {
		$raw = is_object( $order ) && method_exists( $order, 'get_meta' ) ? $order->get_meta( '_creature_demo_photos', true ) : array();
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $slot ) {
			$clean = self::sanitize_photo_slot( $slot );
			if ( '' !== $clean ) {
				$out[] = $clean;
			}
			if ( count( $out ) >= self::PHOTO_SLOTS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * One photo slot: a media id (digits) or an http(s) URL. Anything else is
	 * dropped.
	 *
	 * @param mixed $raw
	 * @return string
	 */
	public static function sanitize_photo_slot( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( preg_match( '/^[0-9]+$/', $raw ) ) {
			return (int) $raw > 0 ? (string) (int) $raw : '';
		}
		if ( ! preg_match( '#^https?://#i', $raw ) || preg_match( '/[\s<>"\']/', $raw ) || false === filter_var( $raw, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Photo links for the bill, one per line. A media id becomes its URL. An id
	 * that does not resolve is left out.
	 *
	 * @param object $order
	 * @return string
	 */
	public static function photo_lines( $order ) {
		$lines = array();
		foreach ( self::photo_slots( $order ) as $slot ) {
			if ( preg_match( '/^[0-9]+$/', $slot ) ) {
				$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( (int) $slot ) : '';
				if ( is_string( $url ) && '' !== $url ) {
					$lines[] = $url;
				}
				continue;
			}
			$lines[] = $slot;
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param object $order
	 */
	public static function render_meta_box( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		echo '<div class="creature-demo-loan-admin">';
		echo '<h3>De-Reacher demo loan</h3>';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'creature_demo_loan_admin', 'creature_demo_loan_admin_nonce' );
		}
		$record = self::consent_record( $order );
		if ( '' !== $record['version'] ) {
			echo '<p>Consent: ' . self::esc( $record['label'] ) . ( '' !== $record['at'] ? ' (agreed ' . self::esc( $record['at'] ) . ')' : '' ) . '</p>';
			if ( '' !== $record['wording'] ) {
				echo '<details><summary>Wording agreed</summary><p>' . self::esc( $record['wording'] ) . '</p></details>';
			}
		}
		echo '<input type="hidden" name="creature_demo_flags_present" value="1" />';
		echo '<p><label><input type="checkbox" name="creature_demo_refunded_10411" value="1"' . ( self::fee_was_refunded( $order ) ? ' checked="checked"' : '' ) . ' /> Refunded under 10.4/11 (no £25 code)</label></p>';
		echo '<p>Admin step for an 11.3 cancellation and for a 10.4 refund: tick &ldquo;Refunded under 10.4/11 (no £25 code)&rdquo; above and update the order. Without the tick the rider is sent a £25 code when the kit is inspected (terms 3A.4).</p>';
		echo '<p><label><input type="checkbox" name="creature_demo_crash_reported" value="1"' . ( 'yes' === self::meta_string( $order, '_creature_demo_crash_reported' ) ? ' checked="checked"' : '' ) . ' /> Crash, impact or failure reported (terms 3A.3): the keep choice stays hidden until the inspection is recorded</label></p>';
		$express = self::express_answer( $order );
		echo '<p>Send my kit now (terms 11.3, asked at checkout): ' . self::esc( '' !== $express ? $express : 'not asked' ) . '. ' . ( 'Yes' === $express ? 'The rider asked for the kit within the 14-day cancellation period. If they cancel after dispatch, the pro-rata refund is worked out by hand.' : '' ) . '</p>';
		self::date_input( 'creature_demo_dispatch_date', 'Dispatch date', self::meta_string( $order, '_creature_demo_dispatch_date' ) );
		$estimated = 'yes' === self::meta_string( $order, '_creature_demo_delivery_estimated' );
		$delivery_label = $estimated ? 'Tracked delivery date (current value is an estimate: dispatch + 2 working days)' : 'Tracked delivery date';
		self::date_input( 'creature_demo_delivery_date', $delivery_label, self::meta_string( $order, '_creature_demo_delivery_date' ) );
		if ( $estimated ) {
			echo '<p>This delivery date is an estimate, not a tracked scan. Enter the tracked date and update the order to recompute the due date.</p>';
		}
		self::date_input( 'creature_demo_return_scan_date', 'Return scan date', self::meta_string( $order, '_creature_demo_return_scan_date' ) );
		self::date_input( 'creature_demo_return_posted_date', 'Proof of posting date', self::meta_string( $order, '_creature_demo_return_posted_date' ) );
		self::date_input( 'creature_demo_received_date', 'Kit received date (starts the 5 working day bill clock)', self::meta_string( $order, '_creature_demo_received_date' ) );
		$dispatch = self::dispatch_date( $order );
		if ( '' !== $dispatch ) {
			echo '<p>Return dispatch date (earlier of posting and first scan): ' . self::esc( $dispatch ) . '</p>';
		}
		$damage = self::meta_string( $order, '_creature_demo_damage_pence' );
		$pounds = '' === $damage ? '' : Creature_Demo_Loan_Money::pence_to_decimal( (int) $damage );
		echo '<p><label>Damage amount (GBP) <input type="text" name="creature_demo_damage" value="' . self::esc( $pounds ) . '" /></label></p>';
		$missing = self::meta_string( $order, '_creature_demo_missing_pence' );
		$m_pound = '' === $missing ? '' : Creature_Demo_Loan_Money::pence_to_decimal( (int) $missing );
		echo '<p><label>Missing parts amount (GBP) <input type="text" name="creature_demo_missing" value="' . self::esc( $m_pound ) . '" /></label></p>';
		echo '<p><label>What the inspection found (required for a damage or missing-parts line, with at least one photo below)<br /><textarea name="creature_demo_findings" rows="4" cols="60">' . self::esc( self::findings_text( $order ) ) . '</textarea></label></p>';
		$photos = self::photo_slots( $order );
		echo '<p>Photos (up to ' . (int) self::PHOTO_SLOTS . '): a media library ID or a full https:// link in each slot. A damage or missing-parts charge needs at least one, and the bill will not send without it (terms 6.4(b)).</p>';
		for ( $i = 1; $i <= self::PHOTO_SLOTS; $i++ ) {
			$value = isset( $photos[ $i - 1 ] ) ? $photos[ $i - 1 ] : '';
			echo '<p><label>Photo ' . (int) $i . ' <input type="text" name="creature_demo_photo_' . (int) $i . '" value="' . self::esc( $value ) . '" /></label></p>';
		}
		echo '<p><label>Amount to keep when upholding (GBP, optional) <input type="text" name="creature_demo_decision_amount" value="" /></label> Blank upholds the charge in full. A smaller amount upholds in part: the difference is released. Not used by Waive.</p>';
		echo '<p><label>Reasons for decision (required to uphold or waive a disputed charge; it goes in the decision email and the decision is refused while it is blank)<br /><textarea name="creature_demo_decision_reasons" rows="3" cols="60"></textarea></label></p>';
		$state = self::meta_string( $order, '_creature_demo_bill_state' );
		if ( '' !== $state ) {
			$names = array(
				'raised'    => 'bill raised',
				'late_only' => 'late-fee-only bill (5 working days passed), no damage can be charged',
				'none'      => 'nothing owed, no bill',
				'deduction' => 'deduction from the deemed purchase refund billed (7 days to pay or dispute)',
			);
			echo '<p>Bill: ' . self::esc( isset( $names[ $state ] ) ? $names[ $state ] : $state ) . '</p>';
		} else {
			$received = self::meta_string( $order, '_creature_demo_received_date' );
			if ( Creature_Demo_Loan_Calendar::is_real_ymd( $received ) ) {
				echo '<p>Bill due by: ' . self::esc( Creature_Demo_Loan_Calendar::add_working_days( $received, self::BILL_WORKING_DAYS, array() ) ) . ' (5 working days after the kit arrived)</p>';
			} elseif ( '' !== self::meta_string( $order, '_creature_demo_return_scan_date' ) ) {
				echo '<p>No Kit received date is entered, so the 5 working day bill clock has not started.</p>';
			}
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		if ( '' !== $due ) {
			echo '<p>Due date: ' . self::esc( $due ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param mixed $order_id
	 * @param mixed $order
	 */
	public static function save_meta_box( $order_id, $order = null ) {
		if ( ! self::admin_nonce_ok() ) {
			return;
		}
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! is_object( $order ) ) {
			return;
		}
		self::save_damage_from_post( $order );
		self::save_return_dates_from_post( $order );
		self::save_flags_from_post( $order );
		$delivery = self::posted_date( 'creature_demo_delivery_date' );
		if ( '' !== $delivery ) {
			self::correct_delivery_date( $order, $delivery );
		}
	}

	/**
	 * @param mixed $statuses
	 * @return mixed
	 */
	public static function filter_post_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}
		foreach ( self::statuses() as $slug => $label ) {
			$statuses[ 'wc-' . $slug ] = array(
				'label'                     => $label,
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => array(
					$label . ' <span class="count">(%s)</span>',
					$label . ' <span class="count">(%s)</span>',
				),
			);
		}
		return $statuses;
	}

	/**
	 * @param mixed $statuses
	 * @return mixed
	 */
	public static function filter_order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		$updated = array();
		$added   = false;
		foreach ( $statuses as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				foreach ( self::statuses() as $slug => $name ) {
					$updated[ 'wc-' . $slug ] = $name;
				}
				$added = true;
			}
		}
		if ( ! $added ) {
			foreach ( self::statuses() as $slug => $name ) {
				$updated[ 'wc-' . $slug ] = $name;
			}
		}
		return $updated;
	}

	/**
	 * @param mixed $statuses
	 * @return mixed
	 */
	public static function filter_paid_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}
		foreach ( array_keys( self::statuses() ) as $slug ) {
			if ( ! in_array( $slug, $statuses, true ) ) {
				$statuses[] = $slug;
			}
		}
		return $statuses;
	}

	/**
	 * @param mixed $actions
	 * @return mixed
	 */
	public static function filter_bulk( $actions ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}
		foreach ( self::statuses() as $slug => $label ) {
			$actions[ 'mark_' . $slug ] = 'Change status to ' . $label;
		}
		return $actions;
	}

	/**
	 * @param mixed $columns
	 * @return mixed
	 */
	public static function filter_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key || 'status' === $key ) {
				$new['creature_demo_due'] = 'Due date';
			}
		}
		if ( ! isset( $new['creature_demo_due'] ) ) {
			$new['creature_demo_due'] = 'Due date';
		}
		return $new;
	}

	/**
	 * @param string $column
	 * @param mixed  $order
	 */
	public static function render_column( $column, $order ) {
		if ( 'creature_demo_due' !== $column || ! is_object( $order ) ) {
			return;
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		echo self::esc( '' !== $due ? $due : '—' );
	}

	/**
	 * @param string $column
	 * @param int    $post_id
	 */
	public static function render_legacy_column( $column, $post_id ) {
		if ( 'creature_demo_due' !== $column || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			self::render_column( $column, $order );
		}
	}

	public static function register_statuses() {
		if ( ! function_exists( 'register_post_status' ) ) {
			return;
		}
		foreach ( self::statuses() as $slug => $label ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => array(
						$label . ' <span class="count">(%s)</span>',
						$label . ' <span class="count">(%s)</span>',
					),
				)
			);
		}
	}

	public static function unschedule_daily() {
		$hook = 'creature_demo_loan_daily';
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), 'creature-demo-loan' );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * The next 22:00 in the site's time zone (terms 4.4: the bill is due by the
	 * end of working day 5, so the daily job runs late in the day, not an hour
	 * after the plugin loads).
	 *
	 * @param int|null $now Unix time. Default: now.
	 * @return int
	 */
	public static function daily_run_timestamp( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$dt  = new DateTime( '@' . $now );
		$dt->setTimezone( self::daily_timezone() );
		$dt->setTime( self::DAILY_RUN_HOUR, 0, 0 );
		if ( $dt->getTimestamp() <= $now ) {
			$dt->modify( '+1 day' );
			$dt->setTime( self::DAILY_RUN_HOUR, 0, 0 );
		}
		return $dt->getTimestamp();
	}

	/**
	 * @return DateTimeZone
	 */
	private static function daily_timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			$tz = wp_timezone();
			if ( $tz instanceof DateTimeZone ) {
				return $tz;
			}
		}
		return new DateTimeZone( 'Europe/London' );
	}

	/**
	 * @param mixed $timestamp
	 * @return bool True when this is a real Unix time that is not 22:00 local.
	 */
	private static function daily_time_is_wrong( $timestamp ) {
		if ( ! is_int( $timestamp ) || $timestamp < 1000000000 ) {
			// false (nothing scheduled) or true (running now): nothing to judge.
			return false;
		}
		$dt = new DateTime( '@' . $timestamp );
		$dt->setTimezone( self::daily_timezone() );
		return sprintf( '%02d:00', self::DAILY_RUN_HOUR ) !== $dt->format( 'H:i' );
	}

	/**
	 * Schedule the daily job for 22:00 local time. A job that an earlier version
	 * scheduled at another time is moved to 22:00 once; after that it is at the
	 * right time and is left alone.
	 *
	 * @param int|null $now Unix time. Default: now.
	 */
	public static function schedule_daily( $now = null ) {
		$hook = 'creature_demo_loan_daily';
		$day  = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		$run  = self::daily_run_timestamp( $now );
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			$next = as_next_scheduled_action( $hook );
			if ( false !== $next && self::daily_time_is_wrong( $next ) && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'creature-demo-loan' );
				$next = false;
			}
			if ( false === $next ) {
				as_schedule_recurring_action( $run, $day, $hook, array(), 'creature-demo-loan' );
			}
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			$next = wp_next_scheduled( $hook );
			if ( false !== $next && self::daily_time_is_wrong( $next ) && function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( $hook );
				$next = false;
			}
			if ( ! $next ) {
				wp_schedule_event( $run, 'daily', $hook );
			}
		}
	}

	/**
	 * The scheduled daily job. It is meant to run at 22:00 London on a working day and
	 * to make that day's decisions (the day-4 alert, the late-fee-only bill on working
	 * day 5) as of that day. WP-Cron and Action Scheduler only start on a page visit
	 * unless the server runs a real cron, so a run can start late or a day can be
	 * skipped. This finds out which London date the run is for, runs every uncovered
	 * date up to it in order with that date as the "as of" day (never the clock time of
	 * the run), and records a late-run flag when a run started after midnight London on
	 * the following day or a scheduled day had no run. It can only see a missed run
	 * once some later run happens (see the README).
	 *
	 * @param int|null $now Unix time. Default: now.
	 * @return int Orders ticked, summed over the dates run.
	 */
	public static function run_scheduled( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		try {
			$london = new DateTime( '@' . $now );
			$london->setTimezone( new DateTimeZone( 'Europe/London' ) );
		} catch ( Exception $e ) {
			return self::run_daily( null );
		}
		$today    = $london->format( 'Y-m-d' );
		$intended = (int) $london->format( 'G' ) >= self::DAILY_RUN_HOUR ? $today : Creature_Demo_Loan_Calendar::add_days( $today, -1 );
		$covered  = function_exists( 'get_option' ) ? get_option( self::DAILY_COVERED_OPTION, '' ) : '';
		$dates    = array( $intended );
		$gap_cut  = false;
		if ( is_string( $covered ) && Creature_Demo_Loan_Calendar::is_real_ymd( $covered ) && $covered < $intended ) {
			$dates = array();
			$day   = Creature_Demo_Loan_Calendar::add_days( $covered, 1 );
			while ( $day <= $intended ) {
				$dates[] = $day;
				$day     = Creature_Demo_Loan_Calendar::add_days( $day, 1 );
			}
			if ( count( $dates ) > self::DAILY_CATCH_UP_MAX ) {
				$dates   = array_slice( $dates, -self::DAILY_CATCH_UP_MAX );
				$gap_cut = true;
			}
		}
		$events = array();
		$count  = 0;
		foreach ( $dates as $date ) {
			$count += self::run_daily( $date );
			if ( $date < $intended ) {
				$events[] = array(
					'date' => $date,
					'kind' => 'skipped',
				);
			} elseif ( $date < $today ) {
				$events[] = array(
					'date' => $date,
					'kind' => 'after_midnight',
				);
			}
		}
		if ( $gap_cut ) {
			$events[] = array(
				'date' => $dates[0],
				'kind' => 'gap_not_run',
			);
		}
		if ( function_exists( 'update_option' ) && ( ! is_string( $covered ) || $covered < $intended ) ) {
			update_option( self::DAILY_COVERED_OPTION, $intended, false );
		}
		if ( $events ) {
			self::record_late_run( $events, $london->format( 'Y-m-d H:i' ), $intended );
		}
		return $count;
	}

	/**
	 * Record a late-run flag and tell the shop once for each occurrence (one date and kind).
	 *
	 * @param array<int, array{date: string, kind: string}> $events
	 * @param string                                        $ran_at   London time the run started.
	 * @param string                                        $intended The date the run was meant for.
	 */
	private static function record_late_run( $events, $ran_at, $intended ) {
		$stored = function_exists( 'get_option' ) ? get_option( self::LATE_RUN_OPTION, array() ) : array();
		$stored = is_array( $stored ) ? $stored : array();
		$seen   = isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array();
		$fresh  = array();
		foreach ( $events as $event ) {
			$key = $event['date'] . '|' . $event['kind'];
			foreach ( $seen as $old ) {
				if ( isset( $old['key'] ) && $old['key'] === $key ) {
					continue 2;
				}
			}
			$event['key']    = $key;
			$event['ran_at'] = $ran_at;
			$fresh[]         = $event;
		}
		if ( ! $fresh ) {
			return;
		}
		$seen = array_slice( array_merge( $seen, $fresh ), -20 );
		if ( function_exists( 'update_option' ) ) {
			update_option(
				self::LATE_RUN_OPTION,
				array(
					'flag'   => 'yes',
					'last'   => $ran_at,
					'events' => $seen,
				),
				false
			);
		}
		$lines = array();
		foreach ( $fresh as $event ) {
			if ( 'skipped' === $event['kind'] ) {
				$lines[] = $event['date'] . ': no run happened that day; caught up at ' . $ran_at . '.';
			} elseif ( 'gap_not_run' === $event['kind'] ) {
				$lines[] = 'More than ' . self::DAILY_CATCH_UP_MAX . ' days had no run; days before ' . $event['date'] . ' were not caught up.';
			} else {
				$lines[] = $event['date'] . ': the run started after midnight London, at ' . $ran_at . '.';
			}
		}
		Creature_Demo_Loan_Mail::send_admin( null, 'admin_daily_job_late', array( 'late_runs' => implode( "\n", $lines ) ) );
	}

	/**
	 * Admin notice while a late-run flag from the last 7 days is set.
	 */
	public static function render_late_run_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'get_option' ) ) {
			return;
		}
		$stored = get_option( self::LATE_RUN_OPTION, array() );
		if ( ! is_array( $stored ) || 'yes' !== ( isset( $stored['flag'] ) ? $stored['flag'] : '' ) || empty( $stored['events'] ) || ! is_array( $stored['events'] ) ) {
			return;
		}
		$last  = end( $stored['events'] );
		$since = isset( $last['date'] ) ? $last['date'] : '';
		if ( ! Creature_Demo_Loan_Calendar::is_real_ymd( $since ) || Creature_Demo_Loan_Calendar::days_between( $since, Creature_Demo_Loan_Calendar::today( null ) ) > 7 ) {
			return;
		}
		$message = 'De-Reacher demo loan: the daily 22:00 job ran late or missed a day (most recent: ' . $since . ', run at ' . ( isset( $stored['last'] ) ? $stored['last'] : '' ) . '). Bills and alerts were caught up for the right dates, but a bill may have gone out after the 5 working days in clause 4.4 if the day was missed. Set up a real server cron for wp-cron.php (see the plugin README).';
		echo '<div class="notice notice-warning"><p>' . self::esc( $message ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function print_checkout_script() {
		$on_checkout = function_exists( 'is_checkout' ) && is_checkout();
		if ( ! $on_checkout ) {
			return;
		}
		$ids  = self::demo_product_ids();
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $ids ) : json_encode( $ids );
		$flag = self::cart_is_demo_only() ? 'true' : 'false';
		echo '<script>window.creatureDemoLoanIds=' . $json . ';window.creatureDemoLoanDemoCart=' . $flag . ';</script>';
		echo '<script>(function(){var ids=window.creatureDemoLoanIds||[];function demoItem(item){var id=item.id||item.product_id||0;var parent=item.parent_id||item.parent||0;return ids.indexOf(id)!==-1||ids.indexOf(parent)!==-1;}function cartDemo(items){if(!items||!items.length)return false;for(var i=0;i<items.length;i++){if(!demoItem(items[i]))return false;}return true;}function toggle(on){var nodes=document.querySelectorAll("[id*=\'creature-demo-loan-consent\'],[for*=\'creature-demo-loan-consent\'],[id*=\'creature-demo-express-request\'],[for*=\'creature-demo-express-request\']");for(var i=0;i<nodes.length;i++){var row=nodes[i].closest? (nodes[i].closest(".wc-block-components-checkbox")||nodes[i].closest("p")||nodes[i].parentNode):nodes[i].parentNode;if(row&&row.style)row.style.display=on?"":"none";}}function read(){try{if(window.wp&&wp.data&&wp.data.select){var store=wp.data.select("wc/store/cart");if(store&&store.getCartData){var data=store.getCartData();if(data&&data.items)return data.items;}}}catch(e){}return null;}function tick(){var items=read();if(items)toggle(cartDemo(items));else toggle(!!window.creatureDemoLoanDemoCart);}tick();if(window.wp&&wp.data&&wp.data.subscribe)wp.data.subscribe(tick);})();</script>';
	}

	/**
	 * @param object                                  $loan
	 * @param string                                  $kind
	 * @param int                                     $amount_pence
	 * @param string                                  $reason
	 * @param string                                  $as_of
	 * @param array<int, array<string, mixed>>|null   $lines One entry per fee line (name, pence, kind). The bill has one line per item.
	 * @return object|null
	 */
	public static function ensure_charge_order( $loan, $kind, $amount_pence, $reason, $as_of, $lines = null, $notice_kind = '' ) {
		$kind = preg_replace( '/[^a-z_]/', '', (string) $kind );
		if ( ! in_array( $kind, array( 'keep', 'late_fee', 'deemed', 'damage', 'bill' ), true ) ) {
			return null;
		}
		$as_of       = Creature_Demo_Loan_Calendar::today( $as_of );
		$meta_key    = '_creature_demo_' . $kind . '_order_id';
		$existing_id = (int) self::meta_string( $loan, $meta_key );
		if ( $existing_id && function_exists( 'wc_get_order' ) ) {
			$existing = wc_get_order( $existing_id );
			if ( $existing && ! in_array( (string) $existing->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
				if ( 'yes' !== self::meta_string( $existing, '_creature_demo_charge_order' ) ) {
					$existing->update_meta_data( '_creature_demo_charge_order', 'yes' );
					$existing->save();
				}
				return $existing;
			}
		}
		if ( ! function_exists( 'wc_create_order' ) ) {
			return null;
		}
		$created = wc_create_order(
			array(
				'customer_id' => method_exists( $loan, 'get_user_id' ) ? (int) $loan->get_user_id() : 0,
				'status'      => 'pending',
			)
		);
		if ( ! is_object( $created ) ) {
			return null;
		}
		if ( method_exists( $created, 'set_currency' ) ) {
			$created->set_currency( 'GBP' );
		}
		if ( method_exists( $loan, 'get_billing_email' ) && method_exists( $created, 'set_billing_email' ) ) {
			$created->set_billing_email( $loan->get_billing_email() );
		}
		$decimal = Creature_Demo_Loan_Money::pence_to_decimal( (int) $amount_pence );
		$fees    = ( is_array( $lines ) && $lines ) ? $lines : array(
			array(
				'kind'  => $kind,
				'name'  => $reason,
				'pence' => (int) $amount_pence,
			),
		);
		if ( class_exists( 'WC_Order_Item_Fee' ) && method_exists( $created, 'add_item' ) ) {
			foreach ( $fees as $line ) {
				$fee = new WC_Order_Item_Fee();
				if ( method_exists( $fee, 'set_name' ) ) {
					$fee->set_name( (string) $line['name'] );
				}
				if ( method_exists( $fee, 'set_total' ) ) {
					$fee->set_total( Creature_Demo_Loan_Money::pence_to_decimal( (int) $line['pence'] ) );
				}
				if ( method_exists( $fee, 'set_tax_status' ) ) {
					$fee->set_tax_status( 'none' );
				}
				$created->add_item( $fee );
			}
			if ( method_exists( $created, 'calculate_totals' ) ) {
				$created->calculate_totals( false );
			}
		}
		if ( method_exists( $created, 'set_total' ) ) {
			$created->set_total( $decimal );
		}
		if ( method_exists( $created, 'set_payment_method' ) ) {
			$created->set_payment_method( 'stripe' );
		}
		$created->update_meta_data( '_creature_demo_parent_loan_id', (int) $loan->get_id() );
		$created->update_meta_data( '_creature_demo_charge_order', 'yes' );
		$created->update_meta_data( '_creature_demo_charge_kind', $kind );
		$created->update_meta_data( '_creature_demo_charge_amount_pence', (int) $amount_pence );
		$created->update_meta_data( '_creature_demo_charge_paid', 'no' );
		$created->update_meta_data( '_creature_demo_charge_lines', $fees );
		$late_pence = 0;
		foreach ( $fees as $line ) {
			if ( 'late_fee' === $line['kind'] ) {
				$late_pence += (int) $line['pence'];
			}
		}
		$created->update_meta_data( '_creature_demo_charge_late_pence', $late_pence );
		$refs = self::saved_card_refs( $loan );
		if ( '' !== $refs['customer'] ) {
			$created->update_meta_data( '_stripe_customer_id', $refs['customer'] );
		}
		if ( '' !== $refs['payment_method'] ) {
			$created->update_meta_data( '_stripe_source_id', $refs['payment_method'] );
		}
		$created->save();
		$loan->update_meta_data( $meta_key, (int) $created->get_id() );
		$loan->update_meta_data( '_creature_demo_open_charge_id', (int) $created->get_id() );
		$loan->save();
		self::log_charge_notice( $loan, $created, (int) $amount_pence, $reason, $as_of, '' !== $notice_kind ? $notice_kind : $kind );
		return $created;
	}

	/**
	 * @param object $charge
	 * @return string
	 */
	public static function idempotency_key( $charge ) {
		$existing = self::meta_string( $charge, '_creature_demo_idempotency_key' );
		if ( '' !== $existing ) {
			return $existing;
		}
		$key = 'demo-loan-' . (int) self::meta_string( $charge, '_creature_demo_parent_loan_id' )
			. '-' . preg_replace( '/[^a-z0-9_]/', '', self::meta_string( $charge, '_creature_demo_charge_kind' ) )
			. '-' . (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' )
			. '-' . preg_replace( '/[^0-9]/', '', self::meta_string( $charge, '_creature_demo_charge_notice_at' ) );
		$charge->update_meta_data( '_creature_demo_idempotency_key', $key );
		$charge->save();
		return $key;
	}

	/**
	 * The one bill uses the late-fee wording when it is late fees alone, and the
	 * damage wording when it carries a damage or missing-parts line.
	 *
	 * @param string                                $kind
	 * @param array<int, array<string, mixed>>|null $lines Lines on a bill.
	 * @return string
	 */
	public static function notice_slot( $kind, $lines = null ) {
		$map  = array(
			'keep'     => 'charge_notice_keep',
			'late_fee' => 'charge_notice_late_fee',
			'deemed'   => 'charge_notice_deemed',
			'damage'   => 'charge_notice_damage',
			// A deduction from the deemed refund: no pay link, no saved-card wording.
			'deduction_deemed' => 'deduction_notice_deemed',
			'bill'     => 'charge_notice_late_fee',
		);
		$slot = isset( $map[ $kind ] ) ? $map[ $kind ] : 'charge_notice_late_fee';
		if ( 'bill' === $kind && is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				if ( isset( $line['kind'] ) && in_array( $line['kind'], array( 'damage', 'missing_parts' ), true ) ) {
					$slot = 'charge_notice_damage';
				}
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$slot = (string) apply_filters( 'creature_demo_loan_notice_slot', $slot, $kind );
		}
		return $slot;
	}

	/**
	 * @param object $order
	 * @param string $current
	 * @param string $as_of
	 * @return array<string, mixed>
	 */
	private static function choose_keep( $order, $current, $as_of ) {
		$order->update_meta_data( '_creature_demo_choice_before_keep', $current );
		$order->update_meta_data( '_creature_demo_ever_chose_keep', 'yes' );
		$order->update_meta_data( '_creature_demo_keep_lapsed', 'no' );
		$order->update_meta_data( '_creature_demo_choice', 'keep' );
		$order->update_meta_data( '_creature_demo_choice_at', $as_of );
		$order->save();
		self::cancel_unpaid_charge( $order, 'late_fee' );
		$amount = Creature_Demo_Loan_Money::keep_amount_pence(
			self::loan_keep_price_pence( $order ),
			(int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' )
		);
		$before = (int) self::meta_string( $order, '_creature_demo_keep_order_id' );
		$charge = self::ensure_charge_order( $order, 'keep', $amount, 'Keep the demo kit', $as_of );
		if ( ! $charge ) {
			$order->update_meta_data( '_creature_demo_choice', $current );
			$order->save();
			return array( 'ok' => false, 'reason' => 'order_failed' );
		}
		if ( (int) $charge->get_id() !== $before || '' === self::meta_string( $order, '_creature_demo_keep_link_sent_at' ) ) {
			$order->update_meta_data( '_creature_demo_keep_link_sent_at', $as_of );
			$order->save();
		}
		return array(
			'ok'       => true,
			'choice'   => 'keep',
			'amount'   => $amount,
			'order_id' => (int) $charge->get_id(),
		);
	}

	/**
	 * @param object $order
	 * @param string $as_of
	 * @return array<string, mixed>
	 */
	private static function choose_return( $order, $as_of ) {
		$order->update_meta_data( '_creature_demo_choice', 'return' );
		$order->update_meta_data( '_creature_demo_choice_at', $as_of );
		$order->update_status( self::STATUS_BOOKED, 'Return booked.' );
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_return_admin_sent' ) ) {
			Creature_Demo_Loan_Mail::send_admin( $order, 'admin_return_booked', array() );
			$order->update_meta_data( '_creature_demo_return_admin_sent', 'yes' );
		}
		// `_creature_demo_buy_ack_sent` is the old buy acknowledgement. An
		// order that already got it is not emailed a second time.
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_return_rider_sent' ) && 'yes' !== self::meta_string( $order, '_creature_demo_buy_ack_sent' ) ) {
			Creature_Demo_Loan_Mail::send_customer( $order, 'choice_return_recorded', array() );
			$order->update_meta_data( '_creature_demo_return_rider_sent', 'yes' );
		}
		$order->save();
		return array( 'ok' => true, 'choice' => 'return' );
	}

	/**
	 * @param object $order
	 * @param string $as_of
	 */
	private static function send_due_reminders( $order, $as_of ) {
		$due  = self::meta_string( $order, '_creature_demo_due_date' );
		$sent = self::reminders_sent( $order );
		$due_now = array();
		foreach ( self::reminder_schedule() as $key => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['offset_days'], $row['slot'] ) ) {
				continue;
			}
			$when = Creature_Demo_Loan_Calendar::add_days( $due, (int) $row['offset_days'] );
			if ( $as_of < $when || in_array( (string) $key, $sent, true ) ) {
				continue;
			}
			$due_now[] = array(
				'key'  => (string) $key,
				'row'  => $row,
				'when' => $when,
			);
		}
		if ( ! $due_now ) {
			return;
		}
		$latest = $due_now[0];
		foreach ( $due_now as $item ) {
			if ( $item['when'] > $latest['when'] ) {
				$latest = $item;
			}
		}
		foreach ( $due_now as $item ) {
			if ( $item['key'] === $latest['key'] ) {
				$args = array(
					'due'   => $due,
					'price' => Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $order ) ),
				);
				if ( ! empty( $item['row']['includes_choices'] ) ) {
					$args['choice_links'] = self::choice_links_plain( $order, $as_of );
				}
				Creature_Demo_Loan_Mail::send_customer( $order, (string) $item['row']['slot'], $args );
			}
			$sent[] = $item['key'];
		}
		$order->update_meta_data( '_creature_demo_reminders_sent', implode( ',', $sent ) );
		$order->save();
	}

	/**
	 * @param object $order
	 * @param string $as_of The reminder's as-of date, not the wall clock.
	 * @return string
	 */
	private static function choice_links_plain( $order, $as_of ) {
		$lines = array();
		$names  = array(
			'return' => 'Return it (£25 code once it’s back)',
			'keep'   => 'Keep it for your keep price',
		);
		$stored = self::normalize_choice( self::meta_string( $order, '_creature_demo_choice' ) );
		foreach ( $names as $choice => $label ) {
			if ( 'keep' === $choice && self::crash_hold( $order ) ) {
				continue;
			}
			if ( ! self::choice_allowed( $order, $choice, $as_of ) && $stored !== $choice ) {
				continue;
			}
			$lines[] = $label . ': ' . self::choice_url( $order, $choice );
		}
		return implode( "\n", $lines );
	}

	/**
	 * @param object $loan
	 * @param object $charge
	 * @param int    $amount_pence
	 * @param string $reason
	 * @param string $as_of
	 * @param string $kind
	 */
	private static function log_charge_notice( $loan, $charge, $amount_pence, $reason, $as_of, $kind ) {
		$charge->update_meta_data( '_creature_demo_charge_notice_logged', 'yes' );
		$charge->update_meta_data( '_creature_demo_charge_notice_at', $as_of );
		$charge->update_meta_data( '_creature_demo_charge_notice_amount', (int) $amount_pence );
		$charge->update_meta_data( '_creature_demo_charge_notice_reason', $reason );
		$charge->save();
		$lines     = $charge->get_meta( '_creature_demo_charge_lines', true );
		$deduction = 'deduction_deemed' === $kind;
		Creature_Demo_Loan_Mail::send_customer(
			$loan,
			self::notice_slot( $kind, is_array( $lines ) ? $lines : null ),
			array(
				'amount'   => Creature_Demo_Loan_Money::format_gbp( $amount_pence ),
				'price'    => Creature_Demo_Loan_Money::format_gbp( self::loan_keep_price_pence( $loan ) ),
				'reason'   => $reason,
				// A deduction comes out of money already held. It never carries a pay link.
				'link'     => $deduction ? '' : self::payment_link( $charge ),
				'due'      => self::meta_string( $loan, '_creature_demo_due_date' ),
				'findings' => self::findings_text( $loan ),
				'photos'   => self::photo_lines( $loan ),
			)
		);
		if ( $deduction ) {
			self::note( $loan, 'Deduction notice logged for ' . Creature_Demo_Loan_Money::format_gbp( $amount_pence ) . ' (' . $reason . '). No payment link is sent. Saved card not charged.' );
			self::note( $charge, 'Deduction notice logged. Saved card not charged.' );
			return;
		}
		self::note( $loan, 'Charge notice logged for ' . Creature_Demo_Loan_Money::format_gbp( $amount_pence ) . ' (' . $reason . '). Payment link emailed. Saved card not charged.' );
		self::note( $charge, 'Charge notice logged. Saved card not charged.' );
	}

	/**
	 * @param object $loan
	 * @param string $kind
	 */
	private static function cancel_unpaid_charge( $loan, $kind ) {
		$kind = preg_replace( '/[^a-z_]/', '', (string) $kind );
		$id   = (int) self::meta_string( $loan, '_creature_demo_' . $kind . '_order_id' );
		if ( ! $id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $id );
		if ( ! $order || 'yes' === self::meta_string( $order, '_creature_demo_charge_paid' ) ) {
			return;
		}
		if ( 'cancelled' === (string) $order->get_status() ) {
			return;
		}
		$order->update_status( 'cancelled', 'Unpaid demo charge cancelled so it is not taken as well as a later charge.' );
		if ( (int) self::meta_string( $loan, '_creature_demo_open_charge_id' ) === $id ) {
			$loan->update_meta_data( '_creature_demo_open_charge_id', 0 );
			$loan->save();
		}
	}

	/**
	 * @param object $loan
	 * @return bool
	 */
	private static function has_unpaid_open_charge( $loan ) {
		$charge = self::open_charge_order( $loan );
		if ( ! $charge ) {
			return false;
		}
		if ( in_array( (string) $charge->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			return false;
		}
		return 'yes' !== self::meta_string( $charge, '_creature_demo_charge_paid' );
	}

	/**
	 * @param object $loan
	 * @return object|null
	 */
	private static function open_charge_order( $loan ) {
		$id = (int) self::meta_string( $loan, '_creature_demo_open_charge_id' );
		if ( ! $id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $id );
		return $order ? $order : null;
	}

	/**
	 * @param object $order
	 * @return object|null
	 */
	private static function charge_from_context( $order ) {
		if ( '' !== self::meta_string( $order, '_creature_demo_charge_kind' ) ) {
			return $order;
		}
		return self::open_charge_order( $order );
	}

	/**
	 * @param object $charge
	 * @return object|null
	 */
	private static function parent_loan( $charge ) {
		$id = (int) self::meta_string( $charge, '_creature_demo_parent_loan_id' );
		if ( ! $id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$loan = wc_get_order( $id );
		return $loan ? $loan : null;
	}

	/**
	 * @param object $order
	 * @param string $as_of
	 * @return bool
	 */
	private static function deemed_reached( $order, $as_of ) {
		if ( 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' ) ) {
			return true;
		}
		$due = self::meta_string( $order, '_creature_demo_due_date' );
		if ( '' === $due ) {
			return false;
		}
		return $as_of >= self::deemed_on_for( $order );
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function deemed_on_for( $order ) {
		return Creature_Demo_Loan_Calendar::deemed_on( self::meta_string( $order, '_creature_demo_due_date' ), self::deemed_after_days() );
	}

	/**
	 * @param object $order
	 * @return string[]
	 */
	private static function reminders_sent( $order ) {
		$raw = self::meta_string( $order, '_creature_demo_reminders_sent' );
		if ( '' === $raw ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * @param object               $coupon
	 * @param string               $code
	 * @param array<string, mixed> $spec
	 */
	private static function apply_coupon_spec( $coupon, $code, $spec ) {
		if ( method_exists( $coupon, 'set_code' ) ) {
			$coupon->set_code( $code );
		}
		if ( method_exists( $coupon, 'set_discount_type' ) ) {
			$coupon->set_discount_type( $spec['discount_type'] );
		}
		if ( method_exists( $coupon, 'set_amount' ) ) {
			$coupon->set_amount( $spec['amount'] );
		}
		if ( method_exists( $coupon, 'set_individual_use' ) ) {
			$coupon->set_individual_use( true );
		}
		if ( method_exists( $coupon, 'set_usage_limit' ) ) {
			$coupon->set_usage_limit( 1 );
		}
		if ( method_exists( $coupon, 'set_usage_limit_per_user' ) ) {
			$coupon->set_usage_limit_per_user( 1 );
		}
		if ( method_exists( $coupon, 'set_email_restrictions' ) ) {
			$coupon->set_email_restrictions( $spec['email_restrictions'] );
		}
		if ( method_exists( $coupon, 'set_product_ids' ) ) {
			$coupon->set_product_ids( $spec['product_ids'] );
		}
		if ( method_exists( $coupon, 'set_date_expires' ) ) {
			$coupon->set_date_expires( $spec['date_expires'] );
		}
		if ( method_exists( $coupon, 'set_description' ) ) {
			$coupon->set_description( 'De-Reacher demo loan credit' );
		}
		if ( method_exists( $coupon, 'save' ) ) {
			$coupon->save();
		}
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function coupon_suffix( $order ) {
		return substr( hash( 'sha256', (string) $order->get_id() . '|creature-demo-loan' ), 0, 8 );
	}

	/**
	 * @return bool
	 */
	private static function stripe_charge_allowed() {
		$mode = self::stripe_mode_flags();
		return '' === self::mode_refusal( $mode['test_mode'], $mode['test_key'], self::live_charges_allowed() );
	}

	/**
	 * PaymentIntent id from our off-session charge, or from the gateway after a pay link.
	 *
	 * @param object $order
	 * @return string
	 */
	private static function payment_intent_id( $order ) {
		$candidates = array(
			self::meta_string( $order, '_creature_demo_payment_intent' ),
			self::meta_string( $order, '_stripe_intent_id' ),
		);
		if ( is_object( $order ) && method_exists( $order, 'get_transaction_id' ) ) {
			$candidates[] = (string) $order->get_transaction_id();
		}
		foreach ( $candidates as $id ) {
			if ( is_string( $id ) && 0 === strpos( $id, 'pi_' ) && '' !== Creature_Demo_Loan_Stripe::safe_id( $id ) ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function payment_link( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_checkout_payment_url' ) ) {
			$url = $order->get_checkout_payment_url();
			if ( is_string( $url ) ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Pay-for-order is starting. Refuse when the saved card already holds the order.
	 *
	 * @param object $order
	 */
	public static function on_before_pay_action( $order ) {
		$result = self::begin_pay_link( $order );
		if ( empty( $result['ok'] ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::refusal_message( isset( $result['code'] ) ? $result['code'] : 'payment_in_progress' ), 'error' );
		}
	}

	/**
	 * Mark a charge order while its pay link is being paid.
	 *
	 * @param object $order
	 * @return array{ok: bool, code: string}
	 */
	public static function begin_pay_link( $order ) {
		$order = self::reload_order( $order );
		if ( ! self::is_charge_order( $order ) ) {
			return array(
				'ok'   => true,
				'code' => '',
			);
		}
		if ( self::pay_link_lock_active( $order ) && '' === self::charge_already_settled( $order ) ) {
			$intent = self::pay_link_intent_conflict( $order );
			if ( '' !== $intent ) {
				return array(
					'ok'   => false,
					'code' => $intent,
				);
			}
			return array(
				'ok'   => true,
				'code' => 'pay_link',
			);
		}
		$blocked = self::pay_link_blocked_by( $order );
		if ( '' !== $blocked ) {
			return array(
				'ok'   => false,
				'code' => $blocked,
			);
		}
		$token = self::acquire_charge_lock( $order, 'pay_link' );
		if ( false === $token ) {
			return array(
				'ok'   => false,
				'code' => 'payment_in_progress',
			);
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'creature_demo_loan_pay_link_lock_acquired', $order );
		}
		$fresh   = self::reload_order( $order );
		$blocked = self::pay_link_blocked_by( $fresh );
		if ( '' !== $blocked ) {
			self::release_charge_lock( $fresh, 'pay_link', $token );
			return array(
				'ok'   => false,
				'code' => $blocked,
			);
		}
		return array(
			'ok'   => true,
			'code' => 'pay_link',
		);
	}

	/**
	 * Take the saved-card lock, then read the order again.
	 *
	 * @param object $charge
	 * @return string Refusal code, or an empty string when the lock is held.
	 */
	private static function claim_saved_card_lock( $charge ) {
		$charge = self::reload_order( $charge );
		$code   = self::charge_payment_conflict( $charge );
		if ( '' !== $code ) {
			return $code;
		}
		$token = self::acquire_charge_lock( $charge, 'saved_card' );
		if ( false === $token ) {
			return 'payment_in_progress';
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'creature_demo_loan_saved_card_lock_acquired', $charge );
		}
		$fresh = self::reload_order( $charge );
		$again = self::charge_payment_conflict( $fresh );
		if ( '' !== $again ) {
			self::release_charge_lock( $fresh, 'saved_card', $token );
			return $again;
		}
		return '';
	}

	/**
	 * @param object $charge
	 * @return string
	 */
	private static function charge_payment_conflict( $charge ) {
		if ( ! is_object( $charge ) ) {
			return 'payment_in_progress';
		}
		$settled = self::charge_already_settled( $charge );
		if ( '' !== $settled ) {
			return $settled;
		}
		$intent = self::pay_link_intent_conflict( $charge );
		if ( '' !== $intent ) {
			return $intent;
		}
		if ( self::pay_link_lock_active( $charge ) ) {
			return 'payment_in_progress';
		}
		return '';
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function pay_link_blocked_by( $order ) {
		if ( self::keep_payment_on_crash_hold( $order ) ) {
			return 'crash_hold';
		}
		$settled = self::charge_already_settled( $order );
		if ( '' !== $settled ) {
			return $settled;
		}
		if ( self::saved_card_lock_active( $order ) ) {
			return 'payment_in_progress';
		}
		return self::pay_link_intent_conflict( $order );
	}

	/**
	 * Terms 3A.3: is this the keep payment of a loan with a reported crash that
	 * has not been inspected yet?
	 *
	 * @param object $charge
	 * @return bool
	 */
	private static function keep_payment_on_crash_hold( $charge ) {
		if ( ! is_object( $charge ) || 'keep' !== self::meta_string( $charge, '_creature_demo_charge_kind' ) ) {
			return false;
		}
		$loan = self::parent_loan( $charge );
		return $loan && self::crash_hold( $loan );
	}

	/**
	 * @param object $order
	 * @return string
	 */
	private static function charge_already_settled( $order ) {
		if ( ! is_object( $order ) ) {
			return 'already_paid';
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_charge_paid' ) ) {
			return 'already_paid';
		}
		$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		if ( in_array( $status, array( 'processing', 'completed', 'refunded' ), true ) ) {
			return 'already_paid';
		}
		if ( method_exists( $order, 'get_transaction_id' ) ) {
			$txn = (string) $order->get_transaction_id();
			if ( '' !== $txn && 0 === strpos( $txn, 'pi_' ) ) {
				return 'already_paid';
			}
		}
		return '';
	}

	/**
	 * Seconds after which a pay-link lock no longer blocks Charge saved card.
	 *
	 * @return int
	 */
	public static function charge_lock_stale_seconds() {
		$seconds = self::CHARGE_LOCK_STALE_SECONDS;
		if ( defined( 'CREATURE_DEMO_LOAN_CHARGE_LOCK_STALE_SECONDS' ) ) {
			$seconds = (int) CREATURE_DEMO_LOAN_CHARGE_LOCK_STALE_SECONDS;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_charge_lock_stale_seconds', $seconds );
			if ( is_numeric( $filtered ) ) {
				$seconds = (int) $filtered;
			}
		}
		return $seconds > 0 ? $seconds : self::CHARGE_LOCK_STALE_SECONDS;
	}

	/**
	 * @return int
	 */
	public static function charge_lock_now() {
		$now = time();
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_charge_lock_now', $now );
			if ( is_numeric( $filtered ) ) {
				$now = (int) $filtered;
			}
		}
		return $now;
	}

	/**
	 * A failed or cancelled pay-link payment drops the lock so the saved card can be charged.
	 *
	 * @param mixed       $order_id
	 * @param object|null $order
	 */
	public static function on_pay_link_failed( $order_id, $order = null ) {
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! self::release_owned_charge_lock( $order, 'pay_link' ) ) {
			return;
		}
		self::note( $order, 'Pay-link payment failed or was cancelled. The saved card can be charged.' );
	}

	/**
	 * Stripe Gateway 11.1.1 fires this as ( WC_Stripe_Exception $e, WC_Order $order ).
	 *
	 * @param mixed  $error
	 * @param object $order
	 */
	public static function on_stripe_payment_error( $error, $order ) {
		unset( $error );
		if ( ! self::release_owned_charge_lock( $order, 'pay_link' ) ) {
			return;
		}
		self::note( $order, 'Pay-link payment failed at Stripe. The saved card can be charged.' );
	}

	/**
	 * @param object $order
	 * @return bool
	 */
	private static function pay_link_lock_active( $order ) {
		$row = self::read_charge_lock( $order );
		return is_array( $row ) && 'pay_link' === (string) ( $row['owner'] ?? '' ) && ! self::charge_lock_payload_is_stale( $row );
	}

	/**
	 * @param object $order
	 * @return bool
	 */
	private static function saved_card_lock_active( $order ) {
		$row = self::read_charge_lock( $order );
		return is_array( $row ) && 'saved_card' === (string) ( $row['owner'] ?? '' ) && ! self::charge_lock_payload_is_stale( $row );
	}

	/**
	 * A missing pay-link lock, or one with no timestamp, is already stale.
	 *
	 * @param object $order
	 * @return bool
	 */
	private static function charge_lock_is_stale( $order ) {
		$row = self::read_charge_lock( $order );
		if ( ! is_array( $row ) || 'pay_link' !== (string) ( $row['owner'] ?? '' ) ) {
			return true;
		}
		return self::charge_lock_payload_is_stale( $row );
	}

	/**
	 * @param mixed $payload
	 * @return bool
	 */
	private static function charge_lock_payload_is_stale( $payload ) {
		if ( ! is_array( $payload ) || ! isset( $payload['at'] ) || '' === $payload['at'] || (int) $payload['at'] < 1 ) {
			return true;
		}
		return ( self::charge_lock_now() - (int) $payload['at'] ) >= self::charge_lock_stale_seconds();
	}

	/**
	 * The intent id is `_stripe_intent_id`. Gateway 11.1.1 writes no status meta,
	 * so the status always comes from the PaymentIntent API.
	 *
	 * Succeeded, processing and requires_capture always refuse. Any other status
	 * stops blocking once the pay-link lock is stale. A failed lookup refuses
	 * while that lock is younger than the window, and after that allows the
	 * charge and leaves an order note.
	 *
	 * @param object $order
	 * @return string
	 */
	private static function pay_link_intent_conflict( $order ) {
		$id = self::meta_string( $order, '_stripe_intent_id' );
		if ( '' === $id || ! is_object( $order ) ) {
			return '';
		}
		$cache_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( array_key_exists( $cache_id, self::$intent_status_cache ) ) {
			$status = self::$intent_status_cache[ $cache_id ];
		} else {
			$status = Creature_Demo_Loan_Stripe::payment_intent_status( $id );
			self::$intent_status_cache[ $cache_id ] = $status;
		}
		if ( '' === $status ) {
			if ( self::charge_lock_is_stale( $order ) ) {
				self::note_intent_lookup_once( $order );
				return '';
			}
			return 'payment_in_progress';
		}
		return self::intent_status_conflict( $order, $status );
	}

	/**
	 * Succeeded, processing and requires_capture always refuse.
	 * Every other status, including requires_confirmation, refuses only while the pay-link lock is fresh.
	 *
	 * @param object $order
	 * @param string $status
	 * @return string
	 */
	private static function intent_status_conflict( $order, $status ) {
		$status = strtolower( trim( (string) $status ) );
		if ( 'succeeded' === $status ) {
			return 'already_paid';
		}
		if ( in_array( $status, array( 'processing', 'requires_capture' ), true ) ) {
			return 'payment_in_progress';
		}
		return self::charge_lock_is_stale( $order ) ? '' : 'payment_in_progress';
	}

	/**
	 * @param object $order
	 */
	private static function note_intent_lookup_once( $order ) {
		$id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( isset( self::$intent_lookup_noted[ $id ] ) ) {
			return;
		}
		self::$intent_lookup_noted[ $id ] = true;
		self::note( $order, 'Stripe did not return the pay-link PaymentIntent status. The charge lock is past the stale window, so Charge saved card is allowed.' );
	}

	/**
	 * Option name for the charge lock. One row per order, autoload no.
	 *
	 * @param int $order_id
	 * @return string
	 */
	public static function charge_lock_option_name( $order_id ) {
		return 'creature_demo_charge_lock_' . (int) $order_id;
	}

	/**
	 * Insert the lock. False when another live lock already holds the order.
	 *
	 * add_option() is not used. When the name is in the notoptions cache, core
	 * skips the existence check and runs INSERT ... ON DUPLICATE KEY UPDATE,
	 * which returns true for a row that already exists. INSERT IGNORE reports
	 * a new row only when rows_affected is 1.
	 *
	 * A stale row, or one with no timestamp, is removed with a value-matched
	 * DELETE and inserted again. If that insert does not affect one row, another
	 * process won.
	 *
	 * @param object $order
	 * @param string $owner saved_card|pay_link
	 * @return string|false Token, or false when the lock was not acquired.
	 */
	public static function acquire_charge_lock( $order, $owner ) {
		self::forget_legacy_charge_lock_meta( $order );
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( $order_id < 1 || ! in_array( $owner, array( 'saved_card', 'pay_link' ), true ) || ! self::wpdb_ready() ) {
			return false;
		}
		$name    = self::charge_lock_option_name( $order_id );
		$payload = array(
			'owner' => $owner,
			'at'    => self::charge_lock_now(),
			'token' => self::charge_lock_token(),
		);
		if ( self::insert_charge_lock( $name, $payload ) ) {
			self::$held_lock_tokens[ $order_id ] = $payload['token'];
			self::clear_charge_lock_cache( $name );
			return $payload['token'];
		}
		$raw = self::charge_lock_db_value( $name );
		if ( null === $raw ) {
			if ( self::insert_charge_lock( $name, $payload ) ) {
				self::$held_lock_tokens[ $order_id ] = $payload['token'];
				self::clear_charge_lock_cache( $name );
				return $payload['token'];
			}
			self::clear_charge_lock_cache( $name );
			return false;
		}
		$existing = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! self::charge_lock_payload_is_stale( $existing ) ) {
			self::clear_charge_lock_cache( $name );
			return false;
		}
		if ( ! self::delete_charge_lock_value( $name, $raw ) ) {
			self::clear_charge_lock_cache( $name );
			return false;
		}
		if ( self::insert_charge_lock( $name, $payload ) ) {
			self::$held_lock_tokens[ $order_id ] = $payload['token'];
			self::clear_charge_lock_cache( $name );
			return $payload['token'];
		}
		self::clear_charge_lock_cache( $name );
		return false;
	}

	/**
	 * Delete the lock only when the owner and token both match the database row.
	 *
	 * @param object $order
	 * @param string $owner
	 * @param string $token
	 * @return bool
	 */
	public static function release_charge_lock( $order, $owner, $token = '' ) {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( $order_id < 1 || ! self::wpdb_ready() ) {
			return false;
		}
		if ( '' === (string) $token && isset( self::$held_lock_tokens[ $order_id ] ) ) {
			$token = self::$held_lock_tokens[ $order_id ];
		}
		if ( '' === (string) $token ) {
			return false;
		}
		$name = self::charge_lock_option_name( $order_id );
		$raw  = self::charge_lock_db_value( $name );
		if ( null === $raw ) {
			return false;
		}
		$current = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
		if ( ! is_array( $current ) || (string) ( $current['owner'] ?? '' ) !== (string) $owner ) {
			return false;
		}
		if ( '' === (string) ( $current['token'] ?? '' ) || ! hash_equals( (string) $current['token'], (string) $token ) ) {
			return false;
		}
		$deleted = self::delete_charge_lock_value( $name, $raw );
		if ( $deleted ) {
			unset( self::$held_lock_tokens[ $order_id ] );
			self::clear_charge_lock_cache( $name );
		}
		return $deleted;
	}

	/**
	 * @param object $order
	 * @param string $owner
	 * @return bool
	 */
	private static function release_owned_charge_lock( $order, $owner ) {
		if ( ! self::is_charge_order( $order ) ) {
			return false;
		}
		$row = self::read_charge_lock( $order );
		if ( ! is_array( $row ) || (string) ( $row['owner'] ?? '' ) !== (string) $owner || '' === (string) ( $row['token'] ?? '' ) ) {
			return false;
		}
		return self::release_charge_lock( $order, $owner, (string) $row['token'] );
	}

	/**
	 * @param object $order
	 */
	private static function release_current_charge_lock( $order ) {
		$row = self::read_charge_lock( $order );
		if ( ! is_array( $row ) || '' === (string) ( $row['owner'] ?? '' ) || '' === (string) ( $row['token'] ?? '' ) ) {
			return;
		}
		self::release_charge_lock( $order, (string) $row['owner'], (string) $row['token'] );
	}

	/**
	 * @return bool
	 */
	private static function wpdb_ready() {
		global $wpdb;
		return isset( $wpdb )
			&& is_object( $wpdb )
			&& ! empty( $wpdb->options )
			&& method_exists( $wpdb, 'query' )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_var' )
			&& function_exists( 'maybe_serialize' );
	}

	/**
	 * @param string               $name
	 * @param array<string, mixed> $payload
	 * @return bool
	 */
	private static function insert_charge_lock( $name, $payload ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				maybe_serialize( $payload ),
				'off'
			)
		);
		return isset( $wpdb->rows_affected ) && 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Serialized option_value from the options table, not from the object cache.
	 *
	 * @param string $name
	 * @return string|null
	 */
	private static function charge_lock_db_value( $name ) {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);
		if ( null === $value || false === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * Delete only the exact row that was read. A newer lock does not match.
	 *
	 * @param string $name
	 * @param string $serialized
	 * @return bool
	 */
	private static function delete_charge_lock_value( $name, $serialized ) {
		global $wpdb;
		if ( '' === (string) $serialized ) {
			return false;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$name,
				$serialized
			)
		);
		return isset( $wpdb->rows_affected ) && 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Drop the per-key cache and this name in notoptions.
	 *
	 * The row is inserted with autoload off, so it is never stored in
	 * alloptions. Deleting that cache entry does nothing useful. The single
	 * option key and the notoptions entry are the ones that can go stale.
	 *
	 * @param string $name
	 */
	private static function clear_charge_lock_cache( $name ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}
		if ( ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) || ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && array_key_exists( $name, $notoptions ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}

	/**
	 * @param object $order
	 * @return array<string, mixed>|false
	 */
	private static function read_charge_lock( $order ) {
		self::forget_legacy_charge_lock_meta( $order );
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		if ( $order_id < 1 || ! self::wpdb_ready() ) {
			return false;
		}
		$raw = self::charge_lock_db_value( self::charge_lock_option_name( $order_id ) );
		if ( null === $raw ) {
			return false;
		}
		$row = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
		return is_array( $row ) ? $row : false;
	}

	/**
	 * Old order-meta locks are not the lock. Drop them so they cannot block.
	 *
	 * @param object $order
	 */
	private static function forget_legacy_charge_lock_meta( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$dirty = false;
		foreach ( array( '_creature_demo_charge_lock', '_creature_demo_charge_lock_at' ) as $key ) {
			$value = $order->get_meta( $key, true );
			if ( null === $value || false === $value || '' === $value || array() === $value ) {
				continue;
			}
			if ( method_exists( $order, 'delete_meta_data' ) ) {
				$order->delete_meta_data( $key );
			} elseif ( method_exists( $order, 'update_meta_data' ) ) {
				$order->update_meta_data( $key, '' );
			}
			$dirty = true;
		}
		if ( $dirty && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * @return string
	 */
	private static function charge_lock_token() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			unset( $e );
			return uniqid( 'lock', true );
		}
	}

	/**
	 * HPOS deletes the order without a shop_order post. The lock is an option either way.
	 *
	 * @param mixed $order_id
	 */
	public static function on_charge_order_deleted( $order_id ) {
		if ( is_object( $order_id ) && method_exists( $order_id, 'get_id' ) ) {
			$order_id = $order_id->get_id();
		}
		$order_id = (int) $order_id;
		if ( $order_id < 1 ) {
			return;
		}
		$name = self::charge_lock_option_name( $order_id );
		if ( self::wpdb_ready() ) {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s",
					$name
				)
			);
			self::clear_charge_lock_cache( $name );
		} elseif ( function_exists( 'delete_option' ) ) {
			delete_option( $name );
		}
		unset( self::$held_lock_tokens[ $order_id ] );
	}

	/**
	 * Legacy shop_order posts. HPOS order ids are not posts, so the post type is required.
	 *
	 * @param mixed $post_id
	 */
	public static function on_before_delete_shop_order( $post_id ) {
		if ( ! function_exists( 'get_post_type' ) || 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}
		self::on_charge_order_deleted( $post_id );
	}

	/**
	 * Plugin uninstall removes every charge lock option.
	 */
	public static function delete_all_charge_locks() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}
		$like = 'creature_demo_charge_lock_%';
		if ( method_exists( $wpdb, 'esc_like' ) ) {
			$like = $wpdb->esc_like( 'creature_demo_charge_lock_' ) . '%';
		}
		$sql = "DELETE FROM {$wpdb->options} WHERE option_name LIKE '{$like}'";
		if ( method_exists( $wpdb, 'prepare' ) ) {
			$sql = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
		}
		$wpdb->query( $sql );
		self::$held_lock_tokens = array();
	}

	/**
	 * @param object $order
	 * @return object
	 */
	private static function reload_order( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! function_exists( 'wc_get_order' ) ) {
			return $order;
		}
		$fresh = wc_get_order( $order->get_id() );
		return $fresh ? $fresh : $order;
	}

	/**
	 * @param object $order
	 * @return bool
	 */
	private static function is_charge_order( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_charge_order' ) ) {
			return true;
		}
		return '' !== self::meta_string( $order, '_creature_demo_parent_loan_id' );
	}

	/**
	 * Loan orders and the charge orders raised from them.
	 *
	 * @param object $order
	 * @return bool
	 */
	private static function demo_customer_mail_order( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_loan' ) ) {
			return true;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_charge_order' ) ) {
			return true;
		}
		return '' !== self::meta_string( $order, '_creature_demo_parent_loan_id' );
	}

	/**
	 * Demo and charge orders, plus a new account opened for one of those riders.
	 *
	 * @param mixed $order
	 * @param mixed $email
	 * @return bool
	 */
	private static function redirects_customer_mail( $order, $email ) {
		if ( self::demo_customer_mail_order( $order ) ) {
			return true;
		}
		$email_id = '';
		if ( is_object( $email ) && isset( $email->id ) ) {
			$email_id = (string) $email->id;
		}
		if ( 'customer_new_account' !== $email_id ) {
			return false;
		}
		if ( self::cart_is_demo_only() ) {
			return true;
		}
		return self::user_has_demo_mail_order( self::mail_user_id( $order ) );
	}

	/**
	 * @param mixed $object
	 * @return int
	 */
	private static function mail_user_id( $object ) {
		if ( ! is_object( $object ) || method_exists( $object, 'get_billing_email' ) ) {
			return 0;
		}
		if ( isset( $object->ID ) ) {
			return (int) $object->ID;
		}
		if ( isset( $object->id ) ) {
			return (int) $object->id;
		}
		return 0;
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	private static function user_has_demo_mail_order( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		$orders = wc_get_orders(
			array(
				'customer' => $user_id,
				'limit'    => 50,
				'return'   => 'objects',
			)
		);
		if ( ! is_array( $orders ) ) {
			return false;
		}
		foreach ( $orders as $candidate ) {
			if ( self::demo_customer_mail_order( $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark the charge order paid so its pay link cannot take the money again.
	 *
	 * @param object $charge
	 * @param string $transaction_id
	 */
	private static function complete_charge_payment( $charge, $transaction_id ) {
		$transaction_id = is_string( $transaction_id ) ? $transaction_id : '';
		if ( '' !== $transaction_id && method_exists( $charge, 'set_transaction_id' ) ) {
			$charge->set_transaction_id( $transaction_id );
		}
		if ( method_exists( $charge, 'payment_complete' ) ) {
			$charge->payment_complete( $transaction_id );
			return;
		}
		$status = method_exists( $charge, 'get_status' ) ? (string) $charge->get_status() : '';
		if ( in_array( $status, array( 'pending', 'on-hold', 'failed', 'checkout-draft', '' ), true ) && method_exists( $charge, 'update_status' ) ) {
			$charge->update_status( 'processing', 'Demo charge paid.' );
		}
		if ( method_exists( $charge, 'save' ) ) {
			$charge->save();
		}
	}

	/**
	 * @param object $order
	 * @param string $as_of
	 */
	private static function alert_open_disputes( $order, $as_of ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$ids = array();
		foreach ( array( 'keep', 'late_fee', 'deemed', 'damage', 'bill' ) as $kind ) {
			$id = (int) self::meta_string( $order, '_creature_demo_' . $kind . '_order_id' );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$open = (int) self::meta_string( $order, '_creature_demo_open_charge_id' );
		if ( $open > 0 ) {
			$ids[] = $open;
		}
		foreach ( array_values( array_unique( $ids ) ) as $id ) {
			$charge = wc_get_order( $id );
			if ( ! $charge || 'open' !== self::meta_string( $charge, '_creature_demo_dispute_status' ) ) {
				continue;
			}
			$opened = self::meta_string( $charge, '_creature_demo_dispute_opened_at' );
			if ( ! Creature_Demo_Loan_Calendar::is_ymd( $opened ) ) {
				continue;
			}
			$days = Creature_Demo_Loan_Calendar::days_between( $opened, $as_of );
			if ( $days >= 14 && 'yes' !== self::meta_string( $charge, '_creature_demo_dispute_alert_14' ) ) {
				self::send_dispute_alert( $order, $opened, $days );
				$charge->update_meta_data( '_creature_demo_dispute_alert_14', 'yes' );
				$charge->update_meta_data( '_creature_demo_dispute_alert_10', 'yes' );
				$charge->save();
			} elseif ( $days >= 10 && $days < 14 && 'yes' !== self::meta_string( $charge, '_creature_demo_dispute_alert_10' ) ) {
				self::send_dispute_alert( $order, $opened, $days );
				$charge->update_meta_data( '_creature_demo_dispute_alert_10', 'yes' );
				$charge->save();
			}
		}
	}

	/**
	 * @param object $order
	 * @param string $opened
	 * @param int    $days
	 */
	private static function send_dispute_alert( $order, $opened, $days ) {
		Creature_Demo_Loan_Mail::send_admin(
			$order,
			'admin_dispute_nearing',
			array(
				'day'    => (string) $days,
				'opened' => $opened,
			)
		);
	}

	/**
	 * @param object $order
	 * @return bool
	 */
	private static function keep_confirm_pending( $order ) {
		if ( '1' === self::request_value( 'creature_demo_confirm' ) ) {
			return false;
		}
		$choice = self::request_value( 'creature_demo_choice' );
		if ( function_exists( 'sanitize_key' ) ) {
			$choice = sanitize_key( $choice );
		}
		if ( 'keep' !== $choice ) {
			return false;
		}
		$raw = self::request_value( 'creature_demo_order' );
		$id  = function_exists( 'absint' ) ? absint( $raw ) : (int) $raw;
		return $id === (int) $order->get_id();
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private static function request_value( $key ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}
		return self::unslash_string( $_GET[ $key ] );
	}

	/**
	 * @param object $order
	 * @param string $text
	 */
	private static function note( $order, $text ) {
		if ( is_object( $order ) && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( self::redact( $text ) );
		}
		if ( is_object( $order ) && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * @param string $choice
	 * @return string
	 */
	private static function normalize_choice( $choice ) {
		$choice = strtolower( trim( (string) $choice ) );
		// `buy` and `buy_new` were the old third choice. Terms 3A.1 have two
		// choices, so both mean return. Old email links still work.
		if ( in_array( $choice, array( 'buy', 'buy_new', 'return' ), true ) ) {
			return 'return';
		}
		if ( 'keep' === $choice ) {
			return 'keep';
		}
		return '';
	}

	/**
	 * @param object $order
	 * @return bool
	 */
	private static function order_is_demo( $order ) {
		if ( ! is_object( $order ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_loan' ) ) {
			return true;
		}
		if ( ! method_exists( $order, 'get_items' ) ) {
			return false;
		}
		$ids = self::demo_product_ids();
		foreach ( $order->get_items() as $item ) {
			$pid = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$vid = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
			if ( in_array( $pid, $ids, true ) || ( $vid && in_array( $vid, $ids, true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function cart_items() {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_cart_items', null );
			if ( is_array( $filtered ) ) {
				return $filtered;
			}
		}
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && isset( $wc->cart ) && is_object( $wc->cart ) && method_exists( $wc->cart, 'get_cart' ) ) {
				$cart = $wc->cart->get_cart();
				return is_array( $cart ) ? $cart : array();
			}
		}
		return array();
	}

	/**
	 * @param mixed $item
	 * @return bool
	 */
	private static function item_is_demo( $item ) {
		if ( ! is_array( $item ) ) {
			return false;
		}
		$ids = self::demo_product_ids();
		$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
		$vid = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		return ( $pid && in_array( $pid, $ids, true ) ) || ( $vid && in_array( $vid, $ids, true ) );
	}

	/**
	 * @param mixed $product
	 * @return bool
	 */
	private static function product_is_demo( $product ) {
		$ids = self::demo_product_ids();
		if ( is_numeric( $product ) ) {
			return in_array( (int) $product, $ids, true );
		}
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$id     = (int) $product->get_id();
			$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			return in_array( $id, $ids, true ) || ( $parent && in_array( $parent, $ids, true ) );
		}
		return false;
	}

	/**
	 * @param object $order
	 * @param mixed  $request
	 * @return mixed
	 */
	private static function block_consent_value( $order, $request ) {
		return self::block_field_value( $order, $request, 'creature/demo-loan-consent' );
	}

	/**
	 * A checkout block additional field, from the order meta or the request.
	 *
	 * @param object $order
	 * @param mixed  $request
	 * @param string $id Field id, for example creature/demo-loan-consent.
	 * @return mixed
	 */
	private static function block_field_value( $order, $request, $id ) {
		$keys = array(
			$id,
			'_wc_other/' . $id,
		);
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			foreach ( $keys as $key ) {
				$value = $order->get_meta( $key, true );
				if ( null !== $value && '' !== $value && array() !== $value ) {
					return $value;
				}
			}
		}
		if ( is_array( $request ) ) {
			if ( isset( $request['additional_fields'][ $id ] ) ) {
				return $request['additional_fields'][ $id ];
			}
			if ( isset( $request[ $id ] ) ) {
				return $request[ $id ];
			}
		}
		return null;
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	private static function value_is_yes( $value ) {
		if ( true === $value || 1 === $value ) {
			return true;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on' ), true );
	}

	/**
	 * @param object $order
	 * @param string $key
	 * @return string
	 */
	private static function meta_string( $order, $key ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$value = $order->get_meta( $key, true );
		if ( is_array( $value ) || null === $value || false === $value ) {
			return '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param mixed $raw
	 * @return array<int, mixed>
	 */
	private static function constant_id_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		return is_array( $raw ) ? $raw : array();
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
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @return string
	 */
	private static function random_token() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 32, false, false );
		}
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * @param string $field
	 * @return string
	 */
	private static function posted_date( $field ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return '';
		}
		$value = trim( self::unslash_string( $_POST[ $field ] ) );
		return Creature_Demo_Loan_Calendar::is_real_ymd( $value ) ? $value : '';
	}

	/**
	 * @param object $order
	 */
	private static function save_damage_from_post( $order ) {
		// Once the bill is final, changing the amounts or the findings would
		// not change what was billed, so the screen does not overwrite them.
		if ( '' !== self::meta_string( $order, '_creature_demo_bill_state' ) ) {
			return;
		}
		$dirty = false;
		foreach ( array(
			'creature_demo_damage'  => '_creature_demo_damage_pence',
			'creature_demo_missing' => '_creature_demo_missing_pence',
		) as $field => $meta ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$pence = self::parse_pounds( self::unslash_string( $_POST[ $field ] ) );
			if ( null === $pence ) {
				continue;
			}
			$order->update_meta_data( $meta, $pence );
			$dirty = true;
		}
		if ( isset( $_POST['creature_demo_findings'] ) ) {
			$text = self::unslash_string( $_POST['creature_demo_findings'] );
			if ( function_exists( 'sanitize_textarea_field' ) ) {
				$text = sanitize_textarea_field( $text );
			} else {
				$text = trim( strip_tags( $text ) );
			}
			$order->update_meta_data( '_creature_demo_findings', substr( $text, 0, 5000 ) );
			$dirty = true;
		}
		$photos = array();
		$posted = false;
		for ( $i = 1; $i <= self::PHOTO_SLOTS; $i++ ) {
			$field = 'creature_demo_photo_' . $i;
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$posted = true;
			$clean  = self::sanitize_photo_slot( self::unslash_string( $_POST[ $field ] ) );
			if ( '' !== $clean ) {
				$photos[] = $clean;
			}
		}
		if ( $posted ) {
			$order->update_meta_data( '_creature_demo_photos', $photos );
			$dirty = true;
		}
		if ( $dirty ) {
			$order->save();
		}
	}

	/**
	 * "12", "12.5", "12,50" to pence. Null when it is not an amount.
	 *
	 * @param string $raw
	 * @return int|null
	 */
	private static function parse_pounds( $raw ) {
		$raw = str_replace( ',', '.', trim( (string) $raw ) );
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $raw ) ) {
			return null;
		}
		$parts = explode( '.', $raw );
		$pence = ( (int) $parts[0] ) * 100;
		if ( isset( $parts[1] ) ) {
			$pence += (int) str_pad( substr( $parts[1], 0, 2 ), 2, '0' );
		}
		return $pence;
	}

	/**
	 * @return bool
	 */
	private static function admin_nonce_ok() {
		if ( ! isset( $_POST['creature_demo_loan_admin_nonce'] ) ) {
			return false;
		}
		if ( ! function_exists( 'wp_verify_nonce' ) ) {
			return false;
		}
		return (bool) wp_verify_nonce( self::unslash_string( $_POST['creature_demo_loan_admin_nonce'] ), 'creature_demo_loan_admin' );
	}

	/**
	 * @param string $name
	 * @param string $label
	 * @param string $value
	 */
	private static function date_input( $name, $label, $value ) {
		echo '<p><label>' . self::esc( $label ) . ' <input type="date" name="' . self::esc( $name ) . '" value="' . self::esc( $value ) . '" /></label></p>';
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function unslash_string( $value ) {
		if ( is_array( $value ) ) {
			return '';
		}
		if ( function_exists( 'wp_unslash' ) ) {
			$value = wp_unslash( $value );
		}
		return is_string( $value ) ? $value : (string) $value;
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

	/**
	 * @param string $url
	 * @return string
	 */
	private static function esc_url( $url ) {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}
