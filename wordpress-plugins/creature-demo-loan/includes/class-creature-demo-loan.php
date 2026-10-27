<?php
/**
 * De-Reacher postal demo loan.
 *
 * Checkout consent and Stripe-only baskets, loan statuses, the three end-of-loan
 * choices, charge notices, and the off-session charge gate. Staging and Stripe
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

	/** @var bool */
	private static $booted = false;

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
		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_action( 'creature_demo_loan_daily', array( __CLASS__, 'run_daily' ) );

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
		// Stripe Gateway UPE (11.x) reads this to save the card for a later
		// off-session charge. Confirm on staging that 11.2.1 still applies it.
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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_choice_request' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ) );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'filter_needs_payment' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_payment_complete' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_payment_complete' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_checkout_script' ) );

		foreach ( array(
			'customer_processing_order',
			'customer_completed_order',
			'customer_on_hold_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
			'customer_failed_order',
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
		unset( $email );
		if ( self::customer_email_allowed() || ! self::demo_customer_mail_order( $order ) ) {
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
		unset( $email );
		if ( self::customer_email_allowed() || ! self::demo_customer_mail_order( $order ) ) {
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
		self::schedule_daily();
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
	 * @return bool
	 */
	public static function late_fees_included_in_keep() {
		$value = true;
		if ( function_exists( 'apply_filters' ) ) {
			$value = apply_filters( 'creature_demo_loan_late_fees_included_in_keep', true );
		}
		return (bool) $value;
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
	public static function notice_working_days() {
		$days = 3;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_notice_working_days', 3 );
		}
		return $days > 0 ? $days : 3;
	}

	/**
	 * @return int
	 */
	public static function keep_lapse_working_days() {
		$days = 3;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_keep_lapse_working_days', 3 );
		}
		return $days >= 0 ? $days : 3;
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
	 * @return int[]
	 */
	public static function demo_product_ids() {
		$ids = array();
		if ( defined( 'CREATURE_DEMO_LOAN_PRODUCT_IDS' ) ) {
			$raw = CREATURE_DEMO_LOAN_PRODUCT_IDS;
			if ( is_string( $raw ) ) {
				$raw = explode( ',', $raw );
			}
			if ( is_array( $raw ) ) {
				$ids = $raw;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$ids = apply_filters( 'creature_demo_loan_product_ids', $ids );
		}
		return self::normalize_ids( $ids );
	}

	/**
	 * Coupon products. Default is the De-Reacher stem, 6687. Terms 3A.1(a)
	 * also describe the bundle; add that id with the filter.
	 *
	 * @return int[]
	 */
	public static function coupon_product_ids() {
		$ids = array( 6687 );
		if ( defined( 'CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS' ) ) {
			$raw = CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS;
			if ( is_string( $raw ) ) {
				$raw = explode( ',', $raw );
			}
			if ( is_array( $raw ) ) {
				$ids = $raw;
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$ids = apply_filters( 'creature_demo_loan_coupon_product_ids', $ids );
		}
		$ids = self::normalize_ids( $ids );
		return $ids ? $ids : array( 6687 );
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
		$need = isset( $state['required_working_days'] ) ? (int) $state['required_working_days'] : 3;
		if ( (int) $state['working_days_since_notice'] < $need ) {
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
		$need = self::notice_working_days();
		$map  = array(
			'no_notice'          => 'Charge refused: no charge notice is logged on the order.',
			'notice_too_recent'  => 'Charge refused: fewer than ' . $need . ' working days have passed since the notice.',
			'disputed'           => 'Charge refused: a dispute is open.',
			'dispute_waived'     => 'Charge refused: the dispute decision did not uphold the charge.',
			'already_paid'       => 'Charge refused: this charge is already paid.',
			'no_consent'         => 'Charge refused: the loan has no recorded consent for a later charge.',
			'live_mode'          => 'Charge refused: the Stripe gateway is not in test mode.',
			'live_key'           => 'Charge refused: the Stripe secret key is not a test key.',
			'no_saved_method'    => 'Charge refused: no saved Stripe customer and payment method were found.',
			'stripe_api_missing' => 'Charge refused: the Stripe gateway API helper is not available.',
			'stripe_error'       => 'Charge failed. The saved card was not charged. A payment link remains available.',
		);
		$msg = isset( $map[ $code ] ) ? $map[ $code ] : 'Charge refused.';
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
	 * @param mixed       $force
	 * @param object|null $order
	 * @return mixed
	 */
	public static function filter_force_save( $force, $order = null ) {
		if ( self::cart_is_demo_only() ) {
			return true;
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
	}

	public static function on_before_submit() {
		if ( ! self::cart_is_demo_only() ) {
			return;
		}
		echo self::classic_checkbox_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		if ( self::posted_consent() ) {
			self::store_consent( $order, true, gmdate( 'c' ) );
		}
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
		}
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
		$version = 'v4';
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_consent_version', 'v4' );
			if ( is_string( $filtered ) && '' !== $filtered ) {
				$version = $filtered;
			}
		}
		$order->update_meta_data( '_creature_demo_consent', 'yes' );
		$order->update_meta_data( '_creature_demo_consent_wording', Creature_Demo_Loan_Copy::text( 'consent_checkbox' ) );
		$order->update_meta_data( '_creature_demo_consent_version', $version );
		$order->update_meta_data( '_creature_demo_consent_at', $when );
		$order->update_meta_data( '_creature_demo_loan', 'yes' );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		return true;
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
		return array(
			'ok'        => true,
			'due'       => $due,
			'delivery'  => $delivery,
			'dispatch'  => $dispatch,
			'estimated' => $estimated,
		);
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
		$current = self::meta_string( $order, '_creature_demo_choice' );
		$lapsed  = 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' );
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
		if ( 'return' === $choice ) {
			return self::choose_return( $order, $as_of );
		}
		return self::choose_buy( $order, $as_of );
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
		$status = (string) $order->get_status();
		if ( in_array( $status, array( self::STATUS_KEPT, self::STATUS_CLOSED, self::STATUS_RETURNED ), true ) ) {
			return false;
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return false;
		}
		$scan    = self::meta_string( $order, '_creature_demo_return_scan_date' );
		$current = self::meta_string( $order, '_creature_demo_choice' );
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
	 * Empty means return (terms 3A.4). A lapsed keep is not an active choice.
	 *
	 * @param object $order
	 * @return string
	 */
	public static function effective_choice( $order ) {
		$choice = self::meta_string( $order, '_creature_demo_choice' );
		if ( 'keep' === $choice && 'yes' === self::meta_string( $order, '_creature_demo_keep_lapsed' ) ) {
			$choice = '';
		}
		if ( ! in_array( $choice, array( 'buy', 'return', 'keep' ), true ) ) {
			return 'return';
		}
		return $choice;
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
		$days = Creature_Demo_Loan_Calendar::working_days_since( $sent, $as_of, self::holidays() );
		if ( $days < self::keep_lapse_working_days() ) {
			return array(
				'ok'     => false,
				'reason' => 'too_soon',
				'days'   => $days,
			);
		}
		$previous = self::meta_string( $order, '_creature_demo_choice_before_keep' );
		$order->update_meta_data( '_creature_demo_keep_lapsed', 'yes' );
		if ( 'buy' === $previous || 'return' === $previous ) {
			$order->update_meta_data( '_creature_demo_choice', $previous );
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
		$deemed_on = '' !== $due ? self::deemed_on_for( $order ) : '';
		$after     = ( '' !== $deemed_on && $scan >= $deemed_on ) || 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' );
		if ( $after ) {
			$order->update_meta_data( '_creature_demo_arrived_after_deemed', 'yes' );
			$order->save();
			self::note( $order, 'Return scan recorded after the deemed-purchase point (' . $scan . '). Inspect the kit before any refund.' );
			if ( 'yes' !== self::meta_string( $order, '_creature_demo_deemed_raised' ) ) {
				self::maybe_raise_deemed( $order, $scan );
			}
			return array(
				'ok'           => true,
				'after_deemed' => true,
			);
		}
		$fee    = Creature_Demo_Loan_Money::late_fee_pence_for_dates( $due, $scan );
		$notice = $as_of ? Creature_Demo_Loan_Calendar::today( $as_of ) : $scan;
		$order->update_status( self::STATUS_INSPECT, 'Return scan ' . $scan . ' recorded. Kit is being inspected.' );
		if ( $fee > 0 ) {
			self::ensure_charge_order( $order, 'late_fee', $fee, 'Late fees for the demo loan', $notice );
		}
		$order->save();
		return array(
			'ok'           => true,
			'late_fee'     => $fee,
			'after_deemed' => false,
		);
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
		$order->update_meta_data( '_creature_demo_inspection_done', 'yes' );
		$order->save();
		if ( 'yes' === self::meta_string( $order, '_creature_demo_arrived_after_deemed' ) || 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' ) ) {
			self::note( $order, 'Inspection recorded after a deemed purchase. Refund is a separate admin action.' );
			return array(
				'ok'               => true,
				'awaiting_refund'  => true,
			);
		}
		$order->update_status( self::STATUS_RETURNED, 'Inspection complete.' );
		$coupon = null;
		if ( 'buy' === self::meta_string( $order, '_creature_demo_choice' ) ) {
			$coupon = self::issue_coupon( $order, $as_of );
		}
		if ( ! self::has_unpaid_open_charge( $order ) ) {
			$order->update_status( self::STATUS_CLOSED, 'Demo loan closed after the kit was returned.' );
		}
		$order->save();
		return array(
			'ok'     => true,
			'coupon' => $coupon,
		);
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
		if ( 'buy' !== self::meta_string( $order, '_creature_demo_choice' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'not_buy',
			);
		}
		if ( 'yes' === self::meta_string( $order, '_creature_demo_deemed_raised' ) || 'yes' === self::meta_string( $order, '_creature_demo_keep_paid' ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no_coupon',
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
		if ( '' !== $scan && $scan < $deemed_on ) {
			return array( 'ok' => false, 'reason' => 'scanned' );
		}
		if ( $as_of < $deemed_on ) {
			return array( 'ok' => false, 'reason' => 'too_soon' );
		}
		self::cancel_unpaid_charge( $order, 'late_fee' );
		self::cancel_unpaid_charge( $order, 'keep' );
		$paid   = (int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' );
		$amount = Creature_Demo_Loan_Money::deemed_amount_pence( $paid );
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
	 * @param object $loan
	 * @param object $charge
	 * @param mixed  $as_of
	 * @return array{code: string, state: array<string, mixed>}
	 */
	public static function evaluate_charge( $loan, $charge, $as_of = null ) {
		$as_of   = Creature_Demo_Loan_Calendar::today( $as_of );
		$logged  = $charge && 'yes' === self::meta_string( $charge, '_creature_demo_charge_notice_logged' );
		$notice  = $charge ? self::meta_string( $charge, '_creature_demo_charge_notice_at' ) : '';
		$days    = '' !== $notice ? Creature_Demo_Loan_Calendar::working_days_since( $notice, $as_of, self::holidays() ) : 0;
		$mode    = self::stripe_mode_flags();
		$state   = array(
			'notice_logged'             => $logged,
			'working_days_since_notice' => $days,
			'required_working_days'     => self::notice_working_days(),
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
			self::note( $loan, self::refusal_message( $result['code'] ) );
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
	 * @return array<string, mixed>
	 */
	public static function record_dispute_decision( $charge, $decision, $as_of = null, $amount_pence = null ) {
		$as_of    = Creature_Demo_Loan_Calendar::today( $as_of );
		$decision = 'upheld' === $decision ? 'upheld' : 'waived';
		$opened   = self::meta_string( $charge, '_creature_demo_dispute_opened_at' );
		$within   = true;
		if ( Creature_Demo_Loan_Calendar::is_ymd( $opened ) ) {
			$within = Creature_Demo_Loan_Calendar::days_between( $opened, $as_of ) <= 14;
		}
		$capped  = false;
		$changed = false;
		$amount  = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
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
				if ( method_exists( $charge, 'set_total' ) ) {
					$charge->set_total( Creature_Demo_Loan_Money::pence_to_decimal( $amount ) );
				}
			}
		}
		$charge->update_meta_data( '_creature_demo_dispute_status', $decision );
		$charge->update_meta_data( '_creature_demo_dispute_decision_at', $as_of );
		$charge->update_meta_data( '_creature_demo_dispute_decision_within_14_days', $within ? 'yes' : 'no' );
		$charge->save();
		if ( $changed ) {
			$loan = self::parent_loan( $charge );
			if ( $loan ) {
				$kind   = self::meta_string( $charge, '_creature_demo_charge_kind' );
				$reason = self::meta_string( $charge, '_creature_demo_charge_notice_reason' );
				self::log_charge_notice( $loan, $charge, $amount, $reason, $as_of, $kind );
			}
		}
		$loan   = self::parent_loan( $charge );
		$target = $loan ? $loan : $charge;
		$amount = (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' );
		Creature_Demo_Loan_Mail::send_customer(
			$target,
			'dispute_decision',
			array(
				'decision' => $decision,
				'amount'   => Creature_Demo_Loan_Money::format_gbp( $amount ),
				'reason'   => self::meta_string( $charge, '_creature_demo_charge_notice_reason' ),
				'link'     => self::payment_link( $charge ),
			)
		);
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
		if ( 'keep' === $kind ) {
			return self::close_as_kept( $loan );
		}
		if ( 'deemed' === $kind ) {
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
	public static function mark_deemed_paid( $loan, $amount ) {
		if ( 'yes' === self::meta_string( $loan, '_creature_demo_deemed_paid' ) ) {
			return array( 'ok' => true, 'idempotent' => true );
		}
		$loan->update_meta_data( '_creature_demo_deemed_paid', 'yes' );
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
		unset( $as_of );
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
		$refund = Creature_Demo_Loan_Money::post_deemed_refund_pence(
			(int) self::meta_string( $loan, '_creature_demo_deemed_paid_pence' ),
			(int) self::meta_string( $loan, '_creature_demo_late_fees_paid_pence' ),
			(int) self::meta_string( $loan, '_creature_demo_damage_pence' )
		);
		$pi = self::meta_string( $loan, '_creature_demo_deemed_payment_intent' );
		if ( $refund > 0 ) {
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
			$result = Creature_Demo_Loan_Stripe::refund( $pi, $refund, 'demo-loan-refund-' . (int) $loan->get_id() . '-' . $refund );
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
		}
		$loan->update_meta_data( '_creature_demo_refund_done', 'yes' );
		$loan->update_meta_data( '_creature_demo_refund_pence', $refund );
		$loan->save();
		self::note( $loan, 'Post-deemed refund confirmed: ' . Creature_Demo_Loan_Money::format_gbp( $refund ) . '.' );
		return array( 'ok' => true, 'amount' => $refund );
	}

	/**
	 * @param mixed $order_id
	 */
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
		$as_of = Creature_Demo_Loan_Calendar::today( null );
		foreach ( array( 'buy', 'return', 'keep' ) as $choice ) {
			$current = self::meta_string( $order, '_creature_demo_choice' );
			if ( ! self::choice_allowed( $order, $choice, $as_of ) && $current !== $choice ) {
				continue;
			}
			$url = self::choice_url( $order, $choice );
			if ( 'keep' === $choice && self::keep_confirm_pending( $order ) ) {
				$amount  = Creature_Demo_Loan_Money::keep_amount_pence(
					(int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' ),
					self::late_fees_due_pence( $order, $as_of ),
					self::late_fees_included_in_keep()
				);
				$confirm = function_exists( 'add_query_arg' ) ? add_query_arg( array( 'creature_demo_confirm' => '1' ), $url ) : $url;
				echo '<p>' . self::esc( Creature_Demo_Loan_Copy::text( 'keep_confirm', array( 'amount' => Creature_Demo_Loan_Money::format_gbp( $amount ) ) ) ) . '</p>';
				echo '<p><a class="button" href="' . self::esc_url( $confirm ) . '">' . self::esc( Creature_Demo_Loan_Copy::text( 'button_keep' ) ) . '</a></p>';
				continue;
			}
			echo '<p><a class="button" href="' . self::esc_url( $url ) . '">' . self::esc( Creature_Demo_Loan_Copy::text( 'button_' . $choice ) ) . '</a></p>';
		}
		echo '</section>';
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
			self::record_dispute_decision( $charge, 'upheld', null, null );
		}
	}

	/**
	 * @param object $order
	 */
	public static function action_waive( $order ) {
		$charge = self::charge_from_context( $order );
		if ( $charge ) {
			self::record_dispute_decision( $charge, 'waived', null, null );
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
		self::record_return_scan( $order, $scan, null );
	}

	/**
	 * @param object $order
	 */
	public static function action_inspect( $order ) {
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
	 * Notice and payment link only. The saved card is not charged here.
	 *
	 * @param object $order
	 */
	public static function action_damage( $order ) {
		self::save_damage_from_post( $order );
		$amount = (int) self::meta_string( $order, '_creature_demo_damage_pence' );
		self::raise_damage_charge( $order, $amount, 'Damage or missing parts', null );
	}

	/**
	 * Damage or missing parts, capped at the £212 keep price unless filtered.
	 * Creates the charge notice. Does not charge the saved card.
	 *
	 * @param object $order
	 * @param int    $amount_pence
	 * @param string $reason
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
		$amount = max( 0, (int) $amount_pence );
		$cap    = Creature_Demo_Loan_Money::KEEP_PENCE;
		if ( function_exists( 'apply_filters' ) ) {
			$cap = (int) apply_filters( 'creature_demo_loan_damage_cap_pence', $cap, $order );
		}
		if ( $cap < 0 ) {
			$cap = Creature_Demo_Loan_Money::KEEP_PENCE;
		}
		if ( $amount > $cap ) {
			$amount = $cap;
		}
		if ( $amount < 1 ) {
			return array(
				'ok'     => false,
				'reason' => 'no_amount',
			);
		}
		$as_of  = Creature_Demo_Loan_Calendar::today( $as_of );
		$reason = trim( (string) $reason );
		if ( '' === $reason ) {
			$reason = 'Damage or missing parts';
		}
		$charge = self::ensure_charge_order( $order, 'damage', $amount, $reason, $as_of );
		if ( ! $charge ) {
			return array(
				'ok'     => false,
				'reason' => 'order_failed',
			);
		}
		return array(
			'ok'       => true,
			'amount'   => (int) self::meta_string( $charge, '_creature_demo_charge_amount_pence' ),
			'order_id' => (int) $charge->get_id(),
		);
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
		self::date_input( 'creature_demo_dispatch_date', 'Dispatch date', self::meta_string( $order, '_creature_demo_dispatch_date' ) );
		$estimated = 'yes' === self::meta_string( $order, '_creature_demo_delivery_estimated' );
		$delivery_label = $estimated ? 'Tracked delivery date (current value is an estimate: dispatch + 2 working days)' : 'Tracked delivery date';
		self::date_input( 'creature_demo_delivery_date', $delivery_label, self::meta_string( $order, '_creature_demo_delivery_date' ) );
		if ( $estimated ) {
			echo '<p>This delivery date is an estimate, not a tracked scan. Enter the tracked date and update the order to recompute the due date.</p>';
		}
		self::date_input( 'creature_demo_return_scan_date', 'Return scan date', self::meta_string( $order, '_creature_demo_return_scan_date' ) );
		$damage = self::meta_string( $order, '_creature_demo_damage_pence' );
		$pounds = '' === $damage ? '' : Creature_Demo_Loan_Money::pence_to_decimal( (int) $damage );
		echo '<p><label>Damage amount (GBP) <input type="text" name="creature_demo_damage" value="' . self::esc( $pounds ) . '" /></label></p>';
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

	public static function schedule_daily() {
		$hook = 'creature_demo_loan_daily';
		if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false === as_next_scheduled_action( $hook ) ) {
				$delay = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
				$day   = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
				as_schedule_recurring_action( time() + $delay, $day, $hook, array(), 'creature-demo-loan' );
			}
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( $hook ) ) {
			$delay = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
			wp_schedule_event( time() + $delay, 'daily', $hook );
		}
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
		echo '<script>(function(){var ids=window.creatureDemoLoanIds||[];function demoItem(item){var id=item.id||item.product_id||0;var parent=item.parent_id||item.parent||0;return ids.indexOf(id)!==-1||ids.indexOf(parent)!==-1;}function cartDemo(items){if(!items||!items.length)return false;for(var i=0;i<items.length;i++){if(!demoItem(items[i]))return false;}return true;}function toggle(on){var nodes=document.querySelectorAll("[id*=\'creature-demo-loan-consent\'],[for*=\'creature-demo-loan-consent\']");for(var i=0;i<nodes.length;i++){var row=nodes[i].closest? (nodes[i].closest(".wc-block-components-checkbox")||nodes[i].closest("p")||nodes[i].parentNode):nodes[i].parentNode;if(row&&row.style)row.style.display=on?"":"none";}}function read(){try{if(window.wp&&wp.data&&wp.data.select){var store=wp.data.select("wc/store/cart");if(store&&store.getCartData){var data=store.getCartData();if(data&&data.items)return data.items;}}}catch(e){}return null;}function tick(){var items=read();if(items)toggle(cartDemo(items));else toggle(!!window.creatureDemoLoanDemoCart);}tick();if(window.wp&&wp.data&&wp.data.subscribe)wp.data.subscribe(tick);})();</script>';
	}

	/**
	 * @param object $loan
	 * @param string $kind
	 * @param int    $amount_pence
	 * @param string $reason
	 * @param string $as_of
	 * @return object|null
	 */
	public static function ensure_charge_order( $loan, $kind, $amount_pence, $reason, $as_of ) {
		$kind = preg_replace( '/[^a-z_]/', '', (string) $kind );
		if ( ! in_array( $kind, array( 'keep', 'late_fee', 'deemed', 'damage' ), true ) ) {
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
		if ( class_exists( 'WC_Order_Item_Fee' ) && method_exists( $created, 'add_item' ) ) {
			$fee = new WC_Order_Item_Fee();
			if ( method_exists( $fee, 'set_name' ) ) {
				$fee->set_name( $reason );
			}
			if ( method_exists( $fee, 'set_total' ) ) {
				$fee->set_total( $decimal );
			}
			if ( method_exists( $fee, 'set_tax_status' ) ) {
				$fee->set_tax_status( 'none' );
			}
			$created->add_item( $fee );
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
		self::log_charge_notice( $loan, $created, (int) $amount_pence, $reason, $as_of, $kind );
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
	 * @param string $kind
	 * @return string
	 */
	public static function notice_slot( $kind ) {
		$map  = array(
			'keep'     => 'charge_notice_keep',
			'late_fee' => 'charge_notice_late_fee',
			'deemed'   => 'charge_notice_deemed',
			'damage'   => 'charge_notice_damage',
		);
		$slot = isset( $map[ $kind ] ) ? $map[ $kind ] : 'charge_notice_late_fee';
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
			(int) self::meta_string( $order, '_creature_demo_late_fees_paid_pence' ),
			self::late_fees_due_pence( $order, $as_of ),
			self::late_fees_included_in_keep()
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
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_return_rider_sent' ) ) {
			Creature_Demo_Loan_Mail::send_customer( $order, 'choice_return_recorded', array() );
			$order->update_meta_data( '_creature_demo_return_rider_sent', 'yes' );
		}
		$order->save();
		return array( 'ok' => true, 'choice' => 'return' );
	}

	/**
	 * @param object $order
	 * @param string $as_of
	 * @return array<string, mixed>
	 */
	private static function choose_buy( $order, $as_of ) {
		$order->update_meta_data( '_creature_demo_choice', 'buy' );
		$order->update_meta_data( '_creature_demo_choice_at', $as_of );
		$status = (string) $order->get_status();
		if ( in_array( $status, array( self::STATUS_LOAN, self::STATUS_OVERDUE ), true ) ) {
			$order->update_status( self::STATUS_BOOKED, 'Return booked so the kit can come back before the £25 code is issued.' );
		}
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_return_admin_sent' ) ) {
			Creature_Demo_Loan_Mail::send_admin( $order, 'admin_return_booked', array() );
			$order->update_meta_data( '_creature_demo_return_admin_sent', 'yes' );
		}
		if ( 'yes' !== self::meta_string( $order, '_creature_demo_buy_ack_sent' ) ) {
			Creature_Demo_Loan_Mail::send_customer( $order, 'choice_buy_recorded', array() );
			$order->update_meta_data( '_creature_demo_buy_ack_sent', 'yes' );
		}
		$order->save();
		return array( 'ok' => true, 'choice' => 'buy' );
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
				$args = array( 'due' => $due );
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
		$names = array(
			'buy'    => 'Buy a new one',
			'return' => 'Return it',
			'keep'   => 'Keep the demo kit',
		);
		foreach ( $names as $choice => $label ) {
			if ( ! self::choice_allowed( $order, $choice, $as_of ) && self::meta_string( $order, '_creature_demo_choice' ) !== $choice ) {
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
		Creature_Demo_Loan_Mail::send_customer(
			$loan,
			self::notice_slot( $kind ),
			array(
				'amount' => Creature_Demo_Loan_Money::format_gbp( $amount_pence ),
				'reason' => $reason,
				'link'   => self::payment_link( $charge ),
				'due'    => self::meta_string( $loan, '_creature_demo_due_date' ),
			)
		);
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
	 * @param string $as_of
	 * @return int
	 */
	private static function late_fees_due_pence( $order, $as_of ) {
		$due  = self::meta_string( $order, '_creature_demo_due_date' );
		$scan = self::meta_string( $order, '_creature_demo_return_scan_date' );
		$end  = '' !== $scan ? $scan : $as_of;
		return Creature_Demo_Loan_Money::late_fee_pence_for_dates( $due, $end );
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
	 * @param object $order
	 * @return string
	 */
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
		foreach ( array( 'keep', 'late_fee', 'deemed', 'damage' ) as $kind ) {
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
		if ( 'buy_new' === $choice ) {
			return 'buy';
		}
		if ( in_array( $choice, array( 'buy', 'return', 'keep' ), true ) ) {
			return $choice;
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
		$keys = array(
			'creature/demo-loan-consent',
			'_wc_other/creature/demo-loan-consent',
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
			if ( isset( $request['additional_fields']['creature/demo-loan-consent'] ) ) {
				return $request['additional_fields']['creature/demo-loan-consent'];
			}
			if ( isset( $request['creature/demo-loan-consent'] ) ) {
				return $request['creature/demo-loan-consent'];
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
		$value = self::unslash_string( $_POST[ $field ] );
		return Creature_Demo_Loan_Calendar::is_ymd( $value ) ? $value : '';
	}

	/**
	 * @param object $order
	 */
	private static function save_damage_from_post( $order ) {
		if ( ! isset( $_POST['creature_demo_damage'] ) ) {
			return;
		}
		$raw = self::unslash_string( $_POST['creature_demo_damage'] );
		$raw = str_replace( ',', '.', $raw );
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $raw ) ) {
			return;
		}
		$parts = explode( '.', $raw );
		$pence = ( (int) $parts[0] ) * 100;
		if ( isset( $parts[1] ) ) {
			$pence += (int) str_pad( substr( $parts[1], 0, 2 ), 2, '0' );
		}
		$order->update_meta_data( '_creature_demo_damage_pence', $pence );
		$order->save();
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
