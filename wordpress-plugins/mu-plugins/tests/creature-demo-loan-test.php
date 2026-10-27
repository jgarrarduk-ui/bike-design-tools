<?php
/**
 * Decision tests for the De-Reacher demo loan plugin.
 *
 *   php wordpress-plugins/mu-plugins/tests/creature-demo-loan-test.php
 *
 * No WooCommerce install and no Stripe calls. Stubs stand in for the hooks,
 * orders, coupons, and WC_Stripe_API.
 */

if ( PHP_SAPI !== 'cli' || ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) !== realpath( __FILE__ ) ) ) {
	return;
}

$GLOBALS['creature_demo_filters']       = array();
$GLOBALS['creature_demo_hooks']         = array();
$GLOBALS['creature_demo_notices']       = array();
$GLOBALS['creature_demo_orders']        = array();
$GLOBALS['creature_demo_next_id']       = 1000;
$GLOBALS['creature_demo_options']       = array();
$GLOBALS['creature_demo_coupons']       = array();
$GLOBALS['creature_demo_cart']          = null;
$GLOBALS['creature_demo_use_product']   = false;
$GLOBALS['creature_demo_keep_included'] = null;
$GLOBALS['creature_demo_refund_flag']   = null;
$GLOBALS['creature_demo_switch_until']  = '';
$GLOBALS['creature_demo_lapse_days']    = null;
$GLOBALS['creature_demo_lock_now']      = null;
$GLOBALS['creature_demo_user']          = 0;
$GLOBALS['creature_demo_scheduled']     = array();
$GLOBALS['creature_demo_post_statuses'] = array();
$GLOBALS['creature_demo_block_fields']  = array();
$GLOBALS['creature_demo_daily_orders']  = array();
$GLOBALS['creature_demo_fleet']         = array();
$GLOBALS['creature_demo_is_checkout']   = false;
$GLOBALS['creature_demo_mail_calls']    = 0;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['creature_demo_hooks'][] = array( $hook, $callback, (int) $priority, (int) $accepted_args );
		if ( ! isset( $GLOBALS['creature_demo_filters'][ $hook ] ) ) {
			$GLOBALS['creature_demo_filters'][ $hook ] = array();
		}
		$GLOBALS['creature_demo_filters'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
		ksort( $GLOBALS['creature_demo_filters'][ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['creature_demo_filters'][ $hook ][ (int) $priority ] ) ) {
			return false;
		}
		$kept = array();
		foreach ( $GLOBALS['creature_demo_filters'][ $hook ][ (int) $priority ] as $row ) {
			if ( $row[0] !== $callback ) {
				$kept[] = $row;
			}
		}
		if ( ! $kept ) {
			unset( $GLOBALS['creature_demo_filters'][ $hook ][ (int) $priority ] );
		} else {
			$GLOBALS['creature_demo_filters'][ $hook ][ (int) $priority ] = $kept;
		}
		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		return remove_filter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['creature_demo_filters'][ $hook ] ) ) {
			return $value;
		}
		ksort( $GLOBALS['creature_demo_filters'][ $hook ] );
		foreach ( $GLOBALS['creature_demo_filters'][ $hook ] as $rows ) {
			foreach ( $rows as $row ) {
				$params = array_merge( array( $value ), $args );
				if ( $row[1] > 0 && count( $params ) > $row[1] ) {
					$params = array_slice( $params, 0, $row[1] );
				}
				$value = call_user_func_array( $row[0], $params );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		if ( empty( $GLOBALS['creature_demo_filters'][ $hook ] ) ) {
			return;
		}
		$priorities = array_keys( $GLOBALS['creature_demo_filters'][ $hook ] );
		sort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $priority ) {
			if ( empty( $GLOBALS['creature_demo_filters'][ $hook ][ $priority ] ) ) {
				continue;
			}
			foreach ( $GLOBALS['creature_demo_filters'][ $hook ][ $priority ] as $row ) {
				$params = $args;
				if ( $row[1] > 0 && count( $params ) > $row[1] ) {
					$params = array_slice( $params, 0, $row[1] );
				}
				call_user_func_array( $row[0], $params );
			}
		}
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'notice' ) {
		$GLOBALS['creature_demo_notices'][] = array( (string) $message, (string) $type );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return (string) $nonce === (string) $action;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) $GLOBALS['creature_demo_user'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		unset( $cap );
		return false;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		$qs  = http_build_query( (array) $args );
		$url = (string) $url;
		if ( '' === $url ) {
			return '?' . $qs;
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $qs;
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action ) {
		return add_query_arg( array( '_wpnonce' => (string) $action ), $url );
	}
}

if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	function wc_get_page_permalink( $page ) {
		unset( $page );
		return 'https://staging.example/my-account/';
	}
}

if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
	function wc_get_endpoint_url( $endpoint, $value, $permalink ) {
		return rtrim( (string) $permalink, '/' ) . '/' . $endpoint . '/' . $value . '/';
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		unset( $special_chars, $extra_special_chars );
		return str_repeat( 'a', max( 1, (int) $length ) );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['creature_demo_options'] ) ? $GLOBALS['creature_demo_options'][ $key ] : $default;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message ) {
		unset( $to, $subject, $message );
		$GLOBALS['creature_demo_mail_calls']++;
		return true;
	}
}

if ( ! function_exists( 'register_post_status' ) ) {
	function register_post_status( $slug, $args = array() ) {
		$GLOBALS['creature_demo_post_statuses'][ $slug ] = $args;
		return true;
	}
}

if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( $hook ) {
		return empty( $GLOBALS['creature_demo_scheduled'][ $hook ] ) ? false : 1;
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ) {
		$GLOBALS['creature_demo_scheduled'][ $hook ] = array( $timestamp, $interval, $args, $group );
		return 1;
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout() {
		return ! empty( $GLOBALS['creature_demo_is_checkout'] );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
		unset( $args );
		$GLOBALS['creature_demo_unscheduled'][] = array( (string) $hook, (string) $group );
		unset( $GLOBALS['creature_demo_scheduled'][ $hook ] );
	}
}

if ( ! function_exists( 'is_order_received_page' ) ) {
	function is_order_received_page() {
		return ! empty( $GLOBALS['creature_demo_order_received'] );
	}
}

if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		$GLOBALS['creature_demo_deactivation'] = array( (string) $file, $callback );
	}
}

if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
	function woocommerce_register_additional_checkout_field( $args ) {
		$GLOBALS['creature_demo_block_fields'][] = $args;
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WC_Stripe_API' ) ) {
	class WC_Stripe_API {
		public static $secret    = '';
		public static $response  = null;
		public static $requests  = array();

		public static function get_secret_key() {
			return self::$secret;
		}

		public static function request( $body, $endpoint = '', $method = 'POST' ) {
			$headers = apply_filters( 'woocommerce_stripe_request_headers', array() );
			self::$requests[] = array(
				'body'     => $body,
				'endpoint' => (string) $endpoint,
				'method'   => (string) $method,
				'headers'  => $headers,
			);
			return self::$response;
		}
	}
}

if ( ! class_exists( 'WC_Coupon' ) ) {
	class WC_Coupon {
		public $props = array();

		public function set_code( $value ) {
			$this->props['code'] = $value;
		}
		public function set_discount_type( $value ) {
			$this->props['discount_type'] = $value;
		}
		public function set_amount( $value ) {
			$this->props['amount'] = $value;
		}
		public function set_individual_use( $value ) {
			$this->props['individual_use'] = $value;
		}
		public function set_usage_limit( $value ) {
			$this->props['usage_limit'] = $value;
		}
		public function set_usage_limit_per_user( $value ) {
			$this->props['usage_limit_per_user'] = $value;
		}
		public function set_email_restrictions( $value ) {
			$this->props['email_restrictions'] = $value;
		}
		public function set_product_ids( $value ) {
			$this->props['product_ids'] = $value;
		}
		public function set_date_expires( $value ) {
			$this->props['date_expires'] = $value;
		}
		public function set_description( $value ) {
			$this->props['description'] = $value;
		}
		public function save() {
			$GLOBALS['creature_demo_coupons'][] = $this->props;
			return count( $GLOBALS['creature_demo_coupons'] );
		}
	}
}

class Creature_Demo_Test_Order {
	public $id;
	public $status = 'pending';
	public $meta = array();
	public $notes = array();
	public $user_id = 0;
	public $billing_email = '';
	public $total = '0.00';
	public $currency = 'GBP';
	public $transaction_id = '';
	public $payment_completed = array();

	public function __construct( $id ) {
		$this->id = (int) $id;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_status() {
		$status = (string) $this->status;
		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}
		return $status;
	}
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== (string) $note ) {
			$this->add_order_note( $note );
		}
		return true;
	}
	public function get_meta( $key, $single = true ) {
		unset( $single );
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
		return $this->id;
	}
	public function add_order_note( $note ) {
		$this->notes[] = (string) $note;
		return count( $this->notes );
	}
	public function get_user_id() {
		return (int) $this->user_id;
	}
	public function get_billing_email() {
		return (string) $this->billing_email;
	}
	public function set_billing_email( $email ) {
		$this->billing_email = (string) $email;
	}
	public function get_items() {
		return array();
	}
	public function set_total( $total ) {
		$this->total = (string) $total;
	}
	public function get_total() {
		return $this->total;
	}
	public function set_currency( $currency ) {
		$this->currency = (string) $currency;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function set_payment_method( $method ) {
		$this->meta['_payment_method'] = (string) $method;
	}
	public function get_checkout_payment_url() {
		return 'https://staging.example/checkout/order-pay/' . $this->id . '/';
	}
	public function set_transaction_id( $id ) {
		$this->transaction_id = (string) $id;
	}
	public function get_transaction_id() {
		return isset( $this->transaction_id ) ? (string) $this->transaction_id : '';
	}
	public function payment_complete( $transaction_id = '' ) {
		if ( '' !== (string) $transaction_id ) {
			$this->set_transaction_id( $transaction_id );
		}
		$this->payment_completed[] = (string) $transaction_id;
		if ( in_array( $this->get_status(), array( 'pending', 'on-hold', 'failed', '' ), true ) ) {
			$this->status = 'processing';
			if ( function_exists( 'do_action' ) ) {
				do_action( 'woocommerce_order_status_processing', $this->id );
			}
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'woocommerce_payment_complete', $this->id );
		}
	}
}

class Creature_Demo_Test_Product {
	public $id;
	public $parent;

	public function __construct( $id, $parent = 0 ) {
		$this->id     = (int) $id;
		$this->parent = (int) $parent;
	}
	public function get_id() {
		return $this->id;
	}
	public function get_parent_id() {
		return $this->parent;
	}
}

function wc_create_order( $args = array() ) {
	$id    = ++$GLOBALS['creature_demo_next_id'];
	$order = new Creature_Demo_Test_Order( $id );
	if ( isset( $args['status'] ) ) {
		$order->status = $args['status'];
	}
	if ( isset( $args['customer_id'] ) ) {
		$order->user_id = (int) $args['customer_id'];
	}
	$GLOBALS['creature_demo_orders'][ $id ] = $order;
	return $order;
}

function wc_get_order( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['creature_demo_orders'][ $id ] ) ? $GLOBALS['creature_demo_orders'][ $id ] : false;
}

function wc_get_orders( $args = array() ) {
	if ( isset( $args['customer'] ) ) {
		$uid   = (int) $args['customer'];
		$found = array();
		foreach ( $GLOBALS['creature_demo_orders'] as $order ) {
			if ( (int) $order->get_user_id() === $uid ) {
				$found[] = $order;
			}
		}
		return $found;
	}
	$page = isset( $args['page'] ) ? (int) $args['page'] : 1;
	if ( $page > 1 ) {
		return array();
	}
	return $GLOBALS['creature_demo_daily_orders'];
}

function creature_demo_filter_products( $ids ) {
	if ( ! empty( $GLOBALS['creature_demo_use_product'] ) ) {
		return array( 9001 );
	}
	return $ids;
}

function creature_demo_filter_cart( $items ) {
	if ( isset( $GLOBALS['creature_demo_cart'] ) && is_array( $GLOBALS['creature_demo_cart'] ) ) {
		return $GLOBALS['creature_demo_cart'];
	}
	return $items;
}

function creature_demo_filter_keep_included( $value ) {
	if ( null !== $GLOBALS['creature_demo_keep_included'] ) {
		return $GLOBALS['creature_demo_keep_included'];
	}
	return $value;
}

function creature_demo_filter_refund_flag( $value ) {
	if ( null !== $GLOBALS['creature_demo_refund_flag'] ) {
		return $GLOBALS['creature_demo_refund_flag'];
	}
	return $value;
}

function creature_demo_filter_switch_until( $until ) {
	if ( '' !== $GLOBALS['creature_demo_switch_until'] ) {
		return $GLOBALS['creature_demo_switch_until'];
	}
	return $until;
}

function creature_demo_filter_lapse( $days ) {
	if ( null !== $GLOBALS['creature_demo_lapse_days'] ) {
		return $GLOBALS['creature_demo_lapse_days'];
	}
	return $days;
}

function creature_demo_filter_lock_now( $now ) {
	if ( null !== $GLOBALS['creature_demo_lock_now'] ) {
		return (int) $GLOBALS['creature_demo_lock_now'];
	}
	return $now;
}

function creature_demo_record_fleet( $id, $order, $reason ) {
	unset( $order );
	$GLOBALS['creature_demo_fleet'][] = array( (int) $id, (string) $reason );
}

require dirname( __DIR__, 2 ) . '/creature-demo-loan/creature-demo-loan.php';

add_filter( 'creature_demo_loan_product_ids', 'creature_demo_filter_products' );
add_filter( 'creature_demo_loan_cart_items', 'creature_demo_filter_cart' );
add_filter( 'creature_demo_loan_late_fees_included_in_keep', 'creature_demo_filter_keep_included' );
add_filter( 'creature_demo_loan_refund_includes_keep_choosers', 'creature_demo_filter_refund_flag' );
add_filter( 'creature_demo_loan_buy_return_switch_until', 'creature_demo_filter_switch_until' );
add_filter( 'creature_demo_loan_keep_lapse_working_days', 'creature_demo_filter_lapse' );
add_filter( 'creature_demo_loan_charge_lock_now', 'creature_demo_filter_lock_now' );
add_action( 'creature_demo_loan_unit_removed_from_fleet', 'creature_demo_record_fleet', 10, 3 );

do_action( 'plugins_loaded' );
do_action( 'init' );

$failures = 0;
$checks   = 0;

function creature_demo_expect( $condition, $message ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL {$message}\n" );
}

function creature_demo_absent( $haystack, $needle, $message ) {
	creature_demo_expect( false === strpos( (string) $haystack, (string) $needle ), $message );
}

function creature_demo_mail_count( $slot ) {
	$n = 0;
	foreach ( Creature_Demo_Loan_Mail::$sent as $row ) {
		if ( $row['slot'] === $slot ) {
			$n++;
		}
	}
	return $n;
}

function creature_demo_last_mail( $slot ) {
	$found = null;
	foreach ( Creature_Demo_Loan_Mail::$sent as $row ) {
		if ( $row['slot'] === $slot ) {
			$found = $row;
		}
	}
	return $found;
}

function creature_demo_clear_cart() {
	$GLOBALS['creature_demo_cart'] = null;
}

function creature_demo_loan( $dispatch = '2026-03-02', $delivery = '2026-03-04' ) {
	$order = wc_create_order(
		array(
			'customer_id' => 42,
			'status'      => 'processing',
		)
	);
	$order->set_billing_email( 'Rider@Example.com' );
	$order->update_meta_data( '_stripe_customer_id', 'cus_test_walker' );
	$order->update_meta_data( '_stripe_source_id', 'pm_test_walker' );
	Creature_Demo_Loan::store_consent( $order, true, '2026-03-02T12:00:00+00:00' );
	Creature_Demo_Loan::start_loan( $order, $dispatch, $delivery );
	return $order;
}

function creature_demo_stripe_test() {
	$GLOBALS['creature_demo_options']['woocommerce_stripe_settings'] = array(
		'enabled'  => 'yes',
		'testmode' => 'yes',
	);
	WC_Stripe_API::$secret   = 'sk_test_' . 'placeholder';
	WC_Stripe_API::$response = (object) array(
		'id'     => 'pi_test_demo_1',
		'status' => 'succeeded',
	);
}

function creature_demo_sensitive_blob() {
	$parts = array();
	foreach ( $GLOBALS['creature_demo_orders'] as $order ) {
		$parts[] = implode( "\n", $order->notes );
	}
	foreach ( Creature_Demo_Loan_Mail::$sent as $row ) {
		$parts[] = $row['subject'] . "\n" . $row['body'] . "\n" . $row['to'];
	}
	$parts[] = json_encode( WC_Stripe_API::$requests );
	return implode( "\n", $parts );
}

$holidays = Creature_Demo_Loan_Calendar::default_bank_holidays();
creature_demo_expect( in_array( '2026-05-04', $holidays, true ), 'early May bank holiday 2026 is listed' );
creature_demo_expect( in_array( '2026-04-03', $holidays, true ), 'Good Friday 2026 is listed' );
creature_demo_expect( in_array( '2026-04-06', $holidays, true ), 'Easter Monday 2026 is listed' );
creature_demo_expect( ! Creature_Demo_Loan_Calendar::is_working_day( '2026-05-04', $holidays ), 'bank holiday is not a working day' );
creature_demo_expect( ! Creature_Demo_Loan_Calendar::is_working_day( '2026-03-07', $holidays ), 'Saturday is not a working day' );
creature_demo_expect( Creature_Demo_Loan_Calendar::is_working_day( '2026-03-04', $holidays ), 'Wednesday is a working day' );

creature_demo_expect(
	'2026-03-04' === Creature_Demo_Loan_Calendar::default_delivery_date( '2026-03-02', $holidays ),
	'Monday dispatch plus 2 working days is Wednesday'
);
creature_demo_expect(
	'2026-05-06' === Creature_Demo_Loan_Calendar::default_delivery_date( '2026-05-01', $holidays ),
	'Friday dispatch skips the weekend and the May bank holiday'
);
creature_demo_expect(
	'2026-03-24' === Creature_Demo_Loan_Calendar::due_date( '2026-03-04', 21 ),
	'21-day loan is due on delivery plus 20 days'
);
creature_demo_expect(
	'2026-03-29' === Creature_Demo_Loan_Calendar::grace_end( '2026-03-24', 5 ),
	'grace ends five days after the due date'
);
creature_demo_expect(
	'2026-04-07' === Creature_Demo_Loan_Calendar::deemed_on( '2026-03-24', 14 ),
	'deemed purchase is 14 days after the due date'
);
creature_demo_expect( 0 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 0 ), 'due day has no late fee' );
creature_demo_expect( 0 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 5 ), 'day 5 is still grace' );
creature_demo_expect( 500 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 6 ), 'day 6 is £5' );
creature_demo_expect( 4000 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 13 ), 'day 13 reaches the £40 cap' );
creature_demo_expect( 4000 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 20 ), 'later days stay at the cap' );
creature_demo_expect(
	0 === Creature_Demo_Loan_Money::late_fee_pence_for_dates( '2026-03-24', '2026-03-29' ),
	'grace-end scan is £0'
);
creature_demo_expect(
	500 === Creature_Demo_Loan_Money::late_fee_pence_for_dates( '2026-03-24', '2026-03-30' ),
	'day-6 scan is £5'
);
creature_demo_expect(
	4000 === Creature_Demo_Loan_Money::late_fee_pence_for_dates( '2026-03-24', '2026-04-06' ),
	'day-13 scan is £40'
);
creature_demo_expect( 0 === Creature_Demo_Loan_Calendar::working_days_since( '2026-03-06', '2026-03-08', $holidays ), 'a weekend adds no working days' );
creature_demo_expect( 1 === Creature_Demo_Loan_Calendar::working_days_since( '2026-03-06', '2026-03-09', $holidays ), 'Monday after a Friday notice is 1 working day' );
creature_demo_expect( 3 === Creature_Demo_Loan_Calendar::working_days_since( '2026-03-06', '2026-03-11', $holidays ), 'Wednesday after a Friday notice is 3 working days' );
creature_demo_expect( 0 === Creature_Demo_Loan_Calendar::working_days_since( '2026-05-01', '2026-05-04', $holidays ), 'the May bank holiday adds nothing' );
creature_demo_expect( 1 === Creature_Demo_Loan_Calendar::working_days_since( '2026-05-01', '2026-05-05', $holidays ), 'Tuesday after the May bank holiday is 1 working day' );
creature_demo_expect( 3 === Creature_Demo_Loan_Calendar::working_days_since( '2026-05-01', '2026-05-07', $holidays ), 'Thursday after the May bank holiday is 3 working days' );
creature_demo_expect( 1 === Creature_Demo_Loan_Calendar::working_days_since( '2026-04-02', '2026-04-07', $holidays ), 'Easter weekend and Easter Monday add nothing' );
creature_demo_expect( 3 === Creature_Demo_Loan_Calendar::working_days_since( '2026-04-02', '2026-04-09', $holidays ), 'three working days after Maundy Thursday land on the next Thursday' );

creature_demo_expect( 19200 === Creature_Demo_Loan_Money::keep_amount_pence( 2000, 4000, true ), 'included flag takes paid late fees off £212 and does not add the rest' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::keep_amount_pence( 0, 4000, true ), 'included flag does not add unpaid late fees' );
creature_demo_expect( 23200 === Creature_Demo_Loan_Money::keep_amount_pence( 2000, 4000, false ), 'excluded flag adds unpaid late fees on top of £212' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::keep_amount_pence( 0, 0, false ), 'excluded flag with no fees is still £212' );
creature_demo_expect( 19200 === Creature_Demo_Loan_Money::deemed_amount_pence( 2000 ), 'deemed purchase nets off late fees already paid' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::deemed_amount_pence( 0 ), 'deemed purchase with no late fees is £212' );
creature_demo_expect( 17200 === Creature_Demo_Loan_Money::deemed_amount_pence( 4000 ), 'a full late-fee cap comes off the deemed price' );
creature_demo_expect( 15700 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 19200, 2000, 1500 ), 'post-deemed refund keeps the rest of the £40 cap and the damage' );
creature_demo_expect( 17200 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 21200, 0, 0 ), 'refund of £212 with no fees paid retains £40' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 21200, 4000, 0 ), 'late fees already at the cap are not retained twice' );
creature_demo_expect( true === Creature_Demo_Loan_Money::refund_applies( true, true ), 'refund flag on includes a rider who chose keep' );
creature_demo_expect( false === Creature_Demo_Loan_Money::refund_applies( true, false ), 'refund flag off skips a rider who chose keep' );
creature_demo_expect( true === Creature_Demo_Loan_Money::refund_applies( false, false ), 'refund flag off still covers a rider who never chose keep' );
creature_demo_expect( true === Creature_Demo_Loan::late_fees_included_in_keep(), 'keep flag defaults on' );
creature_demo_expect( true === Creature_Demo_Loan::refund_includes_keep_choosers(), 'refund flag defaults on' );
$GLOBALS['creature_demo_keep_included'] = false;
creature_demo_expect( false === Creature_Demo_Loan::late_fees_included_in_keep(), 'keep flag filter can turn inclusion off' );
$GLOBALS['creature_demo_keep_included'] = null;
$GLOBALS['creature_demo_refund_flag'] = false;
creature_demo_expect( false === Creature_Demo_Loan::refund_includes_keep_choosers(), 'refund flag filter can turn inclusion off' );
$GLOBALS['creature_demo_refund_flag'] = null;

$plain = wc_create_order();
$started = Creature_Demo_Loan::start_loan( $plain, '2026-03-02', '' );
creature_demo_expect( '2026-03-04' === $started['delivery'], 'blank delivery uses dispatch plus 2 working days' );
creature_demo_expect( '2026-03-24' === $started['due'], 'started loan is due 2026-03-24' );
$again = Creature_Demo_Loan::start_loan( $plain, '2026-06-01', '2026-06-03' );
creature_demo_expect( ! empty( $again['idempotent'] ) && '2026-03-24' === $again['due'], 'starting a loan twice keeps the first due date' );
creature_demo_expect( ! empty( $started['estimated'] ) && 'yes' === $plain->get_meta( '_creature_demo_delivery_estimated' ), 'a blank delivery date is stored as an estimate' );
creature_demo_expect( false !== strpos( implode( "\n", $plain->notes ), 'estimate' ), 'the order note says the delivery date is an estimate' );
$fixed = Creature_Demo_Loan::correct_delivery_date( $plain, '2026-03-06' );
creature_demo_expect( ! empty( $fixed['ok'] ) && '2026-03-26' === $fixed['due'], 'an admin can replace the estimate and recompute the due date' );
creature_demo_expect( 'no' === $plain->get_meta( '_creature_demo_delivery_estimated' ), 'a tracked delivery date clears the estimate' );
$_POST['creature_demo_loan_admin_nonce'] = 'creature_demo_loan_admin';
$_POST['creature_demo_delivery_date']    = '2026-03-09';
Creature_Demo_Loan::save_meta_box( $plain->get_id(), $plain );
creature_demo_expect( '2026-03-29' === $plain->get_meta( '_creature_demo_due_date' ), 'the order screen can correct the delivery date' );
$_POST = array();
$may = wc_create_order();
$may_start = Creature_Demo_Loan::start_loan( $may, '2026-05-01', '' );
creature_demo_expect( '2026-05-06' === $may_start['delivery'], 'May dispatch delivery skips the bank holiday' );

$slots = Creature_Demo_Loan_Copy::slots();
$expected_slots = array(
	'consent_checkbox',
	'consent_error',
	'mixed_basket_error',
	'my_account_heading',
	'button_buy',
	'button_return',
	'button_keep',
	'reminder_choices_due_minus_5',
	'reminder_due_day',
	'reminder_grace_end',
	'reminder_deemed_warning',
	'charge_notice_keep',
	'charge_notice_late_fee',
	'charge_notice_deemed',
	'charge_notice_damage',
	'charge_authentication_required',
	'choice_buy_recorded',
	'choice_return_recorded',
	'coupon_issued',
	'keep_confirm',
	'choice_recorded',
	'keep_choice_lapsed',
	'dispute_decision',
	'admin_dispute_nearing',
	'admin_return_booked',
);
creature_demo_expect( $expected_slots === $slots, 'copy slots match the catalogue' );
foreach ( $slots as $slot ) {
	$subject = Creature_Demo_Loan_Copy::subject( $slot );
	$body    = Creature_Demo_Loan_Copy::text( $slot );
	$joined  = $subject . $body;
	creature_demo_expect( false !== strpos( $joined, '[COPY: Cadence]' ), 'slot ' . $slot . ' is marked for Cadence' );
}
$consent = Creature_Demo_Loan_Copy::text( 'consent_checkbox' );
creature_demo_expect( false !== strpos( $consent, '£40.00' ), 'consent names the £40 late-fee maximum' );
creature_demo_expect( false !== strpos( $consent, '£212.00' ), 'consent names the £212 deemed-purchase maximum' );
creature_demo_expect( false !== strpos( $consent, '£35.00' ), 'consent names the £35 inverted mount kit' );
creature_demo_expect( false !== strpos( $consent, 'damage or missing parts' ), 'consent covers damage as well as missing parts' );
creature_demo_expect( false !== strpos( $consent, 'damage or missing parts, never' ), 'consent has a comma after missing parts' );

creature_demo_expect( array() === Creature_Demo_Loan::demo_product_ids(), 'demo product ids stay empty until configured' );
creature_demo_expect( array( 6687 ) === Creature_Demo_Loan::coupon_product_ids(), 'coupon defaults to De-Reacher product 6687' );
$GLOBALS['creature_demo_use_product'] = true;
creature_demo_expect( array( 9001 ) === Creature_Demo_Loan::demo_product_ids(), 'product id filter supplies the demo id' );

$GLOBALS['creature_demo_notices'] = array();
$GLOBALS['creature_demo_cart']    = array(
	array( 'product_id' => 9001 ),
	array( 'product_id' => 50 ),
);
Creature_Demo_Loan::on_check_cart_items();
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_notices'] ), 'a mixed basket is refused' );
creature_demo_expect( false === Creature_Demo_Loan::cart_is_demo_only(), 'a mixed basket is not demo-only' );
$mixed_gateways = Creature_Demo_Loan::filter_gateways(
	array(
		'stripe'                => 'stripe',
		'woocommerce_payments'  => 'wcpay',
		'ppcp-gateway'          => 'paypal',
	)
);
creature_demo_expect( 3 === count( $mixed_gateways ), 'a mixed basket does not hide gateways' );

$GLOBALS['creature_demo_cart'] = array(
	array(
		'product_id'   => 9001,
		'variation_id' => 0,
	),
);
creature_demo_expect( true === Creature_Demo_Loan::cart_is_demo_only(), 'a demo-only basket is recognised' );
$kept_gateways = Creature_Demo_Loan::filter_gateways(
	array(
		'stripe'               => 'stripe',
		'woocommerce_payments' => 'wcpay',
		'ppcp-gateway'         => 'paypal',
		'cheque'               => 'cheque',
	)
);
creature_demo_expect( array( 'stripe' ) === array_keys( $kept_gateways ), 'a demo basket offers Stripe only' );
creature_demo_expect( true === Creature_Demo_Loan::filter_force_save( false ), 'a demo basket forces the card to be saved' );
creature_demo_expect( true === Creature_Demo_Loan::filter_registration_required( false ), 'a demo basket requires an account' );
creature_demo_expect( true === Creature_Demo_Loan::filter_registration_enabled( false ), 'a demo basket enables registration' );
creature_demo_expect( false === Creature_Demo_Loan::filter_payment_request_off( true ), 'Stripe payment-request buttons are off' );
creature_demo_expect( false === Creature_Demo_Loan::filter_express_supported( true, new Creature_Demo_Test_Product( 9001 ) ), 'express checkout is off for the demo product' );
creature_demo_expect( false === Creature_Demo_Loan::filter_express_supported( true, new Creature_Demo_Test_Product( 50, 9001 ) ), 'express checkout is off for a demo variation' );
creature_demo_expect( true === Creature_Demo_Loan::filter_paypal_buttons_disabled( false ), 'PayPal buttons are disabled for a demo basket' );

$GLOBALS['creature_demo_notices'] = array();
$_POST = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_notices'] ), 'checkout without the demo box is refused' );
$_POST['creature_demo_loan_consent'] = '1';
$GLOBALS['creature_demo_notices']    = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( array() === $GLOBALS['creature_demo_notices'], 'a ticked demo box passes checkout' );

ob_start();
Creature_Demo_Loan::on_before_submit();
$checkbox = ob_get_clean();
creature_demo_expect( false === strpos( $checkbox, 'checked' ), 'the consent checkbox is not pre-ticked' );
creature_demo_expect( false !== strpos( $checkbox, 'name="creature_demo_loan_consent"' ), 'the consent checkbox has its own name' );
creature_demo_expect( false === strpos( $checkbox, 'name="terms"' ), 'the consent checkbox is not the terms checkbox' );
creature_demo_expect( false !== strpos( $checkbox, '£40.00' ) && false !== strpos( $checkbox, '£212.00' ) && false !== strpos( $checkbox, '£35.00' ), 'the checkbox names the three maxima' );

$field = Creature_Demo_Loan::$block_field;
creature_demo_expect( is_array( $field ) && 'creature/demo-loan-consent' === $field['id'], 'block field id is registered' );
creature_demo_expect( false === $field['required'], 'the block field is not marked required for every basket' );
creature_demo_expect( 'checkbox' === $field['type'], 'the block field is a checkbox' );
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_block_fields'] ), 'the additional checkout field is registered once' );
$empty_block = Creature_Demo_Loan::validate_block_consent( '' );
creature_demo_expect( is_object( $empty_block ) && is_a( $empty_block, 'WP_Error' ), 'an empty block checkbox fails a demo basket' );
creature_demo_expect( null === Creature_Demo_Loan::validate_block_consent( '1' ), 'a ticked block checkbox passes' );

$consent_order = wc_create_order();
creature_demo_expect( false === Creature_Demo_Loan::store_consent( $consent_order, false, '2026-03-02T12:00:00+00:00' ), 'an unticked box is not stored' );
creature_demo_expect( true === Creature_Demo_Loan::store_consent( $consent_order, true, '2026-03-02T12:00:00+00:00' ), 'a ticked box is stored' );
creature_demo_expect( 'yes' === $consent_order->get_meta( '_creature_demo_consent' ), 'consent is recorded as yes' );
creature_demo_expect( $consent === $consent_order->get_meta( '_creature_demo_consent_wording' ), 'the agreed wording is stored' );
creature_demo_expect( 'v4' === $consent_order->get_meta( '_creature_demo_consent_version' ), 'the consent version is v4' );
creature_demo_expect( '2026-03-02T12:00:00+00:00' === $consent_order->get_meta( '_creature_demo_consent_at' ), 'the consent timestamp is stored' );

$_POST['creature_demo_loan_consent'] = '1';
$checkout_order = wc_create_order();
Creature_Demo_Loan::on_checkout_create_order( $checkout_order );
creature_demo_expect( 'v4' === $checkout_order->get_meta( '_creature_demo_consent_version' ), 'classic checkout stores the consent version' );
$block_order = wc_create_order();
Creature_Demo_Loan::on_store_api_order(
	$block_order,
	array(
		'additional_fields' => array(
			'creature/demo-loan-consent' => '1',
		),
	)
);
creature_demo_expect( 'yes' === $block_order->get_meta( '_creature_demo_consent' ), 'block checkout stores the additional field' );

creature_demo_clear_cart();
$GLOBALS['creature_demo_notices'] = array();
$_POST = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( array() === $GLOBALS['creature_demo_notices'], 'a normal basket does not require the demo box' );
ob_start();
Creature_Demo_Loan::on_before_submit();
$hidden = ob_get_clean();
creature_demo_expect( '' === $hidden, 'a normal basket does not show the demo box' );
creature_demo_expect( null === Creature_Demo_Loan::validate_block_consent( '' ), 'an empty block checkbox is ignored off a demo basket' );
$normal_gateways = Creature_Demo_Loan::filter_gateways(
	array(
		'stripe' => 'stripe',
		'cheque' => 'cheque',
	)
);
creature_demo_expect( array( 'stripe', 'cheque' ) === array_keys( $normal_gateways ), 'a normal basket keeps its gateways' );
creature_demo_expect( false === Creature_Demo_Loan::filter_force_save( false ), 'a normal basket does not force a saved card' );
$loan_for_save = creature_demo_loan();
creature_demo_expect( true === Creature_Demo_Loan::filter_force_save( false, $loan_for_save ), 'a demo order still forces a saved card' );
creature_demo_expect( true === Creature_Demo_Loan::filter_express_supported( true, new Creature_Demo_Test_Product( 50 ) ), 'a normal product can keep express checkout' );

$statuses = Creature_Demo_Loan::filter_order_statuses(
	array(
		'wc-pending'    => 'Pending',
		'wc-processing' => 'Processing',
		'wc-completed'  => 'Completed',
	)
);
creature_demo_expect( 'On loan' === $statuses['wc-demo-loan'], 'On loan is registered' );
creature_demo_expect( 'Return booked' === $statuses['wc-demo-booked'], 'Return booked is registered' );
creature_demo_expect( 'Returned (inspecting)' === $statuses['wc-demo-inspect'], 'Returned (inspecting) is registered' );
creature_demo_expect( 'Returned' === $statuses['wc-demo-returned'], 'Returned is registered' );
creature_demo_expect( 'Overdue' === $statuses['wc-demo-overdue'], 'Overdue is registered' );
creature_demo_expect( 'Kept (purchased)' === $statuses['wc-demo-kept'], 'Kept (purchased) is registered' );
creature_demo_expect( 'Deemed purchase' === $statuses['wc-demo-deemed'], 'Deemed purchase is registered' );
creature_demo_expect( 'Closed' === $statuses['wc-demo-closed'], 'Closed is registered' );
$keys = array_keys( $statuses );
creature_demo_expect( array_search( 'wc-processing', $keys, true ) < array_search( 'wc-demo-loan', $keys, true ), 'loan statuses follow Processing' );
creature_demo_expect( array_search( 'wc-demo-closed', $keys, true ) < array_search( 'wc-completed', $keys, true ), 'loan statuses sit before Completed' );
foreach ( array_keys( Creature_Demo_Loan::statuses() ) as $slug ) {
	creature_demo_expect( strlen( 'wc-' . $slug ) <= 20, 'status slug wc-' . $slug . ' fits the posts limit' );
	creature_demo_expect( isset( $GLOBALS['creature_demo_post_statuses'][ 'wc-' . $slug ] ), 'post status wc-' . $slug . ' is registered' );
}
$paid = Creature_Demo_Loan::filter_paid_statuses( array( 'processing', 'completed' ) );
creature_demo_expect( in_array( 'demo-loan', $paid, true ), 'On loan stays a paid status so the £25 order is not restocked' );
$columns = Creature_Demo_Loan::filter_columns(
	array(
		'order_number' => 'Order',
		'order_status' => 'Status',
		'order_total'  => 'Total',
	)
);
creature_demo_expect( isset( $columns['creature_demo_due'] ) && 'Due date' === $columns['creature_demo_due'], 'the orders list has a Due date column' );
ob_start();
Creature_Demo_Loan::render_column( 'creature_demo_due', $loan_for_save );
$due_cell = ob_get_clean();
creature_demo_expect( '2026-03-24' === $due_cell, 'the Due date column prints the stored due date' );
$empty_due = wc_create_order();
ob_start();
Creature_Demo_Loan::render_column( 'creature_demo_due', $empty_due );
$dash = ob_get_clean();
creature_demo_expect( '—' === $dash, 'an order without a due date shows a dash' );
creature_demo_expect( isset( $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] ), 'the daily reminder is scheduled' );
creature_demo_expect( DAY_IN_SECONDS === $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'][1], 'the reminder repeats daily' );
creature_demo_expect( 'creature-demo-loan' === $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'][3], 'the reminder uses its own Action Scheduler group' );

$return_route = Creature_Demo_Loan_Mail::route( false, 'rider@example.com', 'demo-loan-test@creaturecycles.co.uk' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $return_route['to'] && true === $return_route['prefix'], 'customer mail is redirected and prefixed when real sends are off' );
$real_route = Creature_Demo_Loan_Mail::route( true, 'rider@example.com', 'demo-loan-test@creaturecycles.co.uk' );
creature_demo_expect( 'rider@example.com' === $real_route['to'] && false === $real_route['prefix'], 'customer mail goes to the rider when real sends are on' );
creature_demo_expect( false === Creature_Demo_Loan::customer_email_allowed(), 'real customer email stays off unless the constant is true' );
creature_demo_expect( false === Creature_Demo_Loan::live_charges_allowed(), 'live charges stay off unless the constant is true' );
creature_demo_expect( '' === Creature_Demo_Loan::mode_refusal( false, false, true ), 'the live-charge constant is the only override for a live key' );
creature_demo_expect( 'live_mode' === Creature_Demo_Loan::mode_refusal( false, true, false ), 'test key in live mode is refused' );
creature_demo_expect( 'live_key' === Creature_Demo_Loan::mode_refusal( true, false, false ), 'a live key in test mode is refused' );
creature_demo_expect( '' === Creature_Demo_Loan::mode_refusal( true, true, false ), 'test mode and a test key are allowed' );

$reminded = creature_demo_loan();
Creature_Demo_Loan::tick( $reminded, '2026-03-18' );
creature_demo_expect( 0 === creature_demo_mail_count( 'reminder_choices_due_minus_5' ), 'the choice reminder waits until five days before the due date' );
Creature_Demo_Loan::tick( $reminded, '2026-03-19' );
Creature_Demo_Loan::tick( $reminded, '2026-03-19' );
creature_demo_expect( 1 === creature_demo_mail_count( 'reminder_choices_due_minus_5' ), 'the five-day reminder is sent once' );
$five = creature_demo_last_mail( 'reminder_choices_due_minus_5' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $five['to'], 'the reminder goes to the test inbox' );
creature_demo_expect( 0 === strpos( $five['subject'], '[TEST] ' ), 'the reminder subject is prefixed [TEST]' );
creature_demo_expect( false !== strpos( $five['body'], 'creature_demo_choice=buy' ), 'the reminder links to buy' );
creature_demo_expect( false !== strpos( $five['body'], 'creature_demo_choice=return' ), 'the reminder links to return' );
creature_demo_expect( false !== strpos( $five['body'], 'creature_demo_choice=keep' ), 'the reminder links to keep' );
creature_demo_expect( 'demo-loan' === $reminded->get_status(), 'the loan is not overdue before the due date' );
Creature_Demo_Loan::tick( $reminded, '2026-03-24' );
creature_demo_expect( 1 === creature_demo_mail_count( 'reminder_due_day' ), 'the due-day reminder is sent' );
creature_demo_expect( 'demo-loan' === $reminded->get_status(), 'the due day itself is not overdue' );
Creature_Demo_Loan::tick( $reminded, '2026-03-25' );
creature_demo_expect( 'demo-overdue' === $reminded->get_status(), 'the day after the due date is Overdue' );
Creature_Demo_Loan::tick( $reminded, '2026-03-29' );
creature_demo_expect( 1 === creature_demo_mail_count( 'reminder_grace_end' ), 'the grace-end reminder is sent' );
Creature_Demo_Loan::tick( $reminded, '2026-04-03' );
Creature_Demo_Loan::tick( $reminded, '2026-04-03' );
creature_demo_expect( 1 === creature_demo_mail_count( 'reminder_deemed_warning' ), 'the deemed-purchase warning is sent once' );
$warn = creature_demo_last_mail( 'reminder_deemed_warning' );
creature_demo_expect( false !== strpos( $warn['body'], '£212' ), 'the warning names the £212 cap' );

$daily = creature_demo_loan();
$GLOBALS['creature_demo_daily_orders'] = array( $daily );
$ran = Creature_Demo_Loan::run_daily( '2026-03-19' );
creature_demo_expect( 1 === $ran, 'the daily job visits the open loan' );
creature_demo_expect( creature_demo_mail_count( 'reminder_choices_due_minus_5' ) >= 2, 'the daily job sends the choice reminder' );
$GLOBALS['creature_demo_daily_orders'] = array();

$buy = creature_demo_loan();
$buy_mails = creature_demo_mail_count( 'choice_buy_recorded' );
$admin_mails = creature_demo_mail_count( 'admin_return_booked' );
$first_buy = Creature_Demo_Loan::choose( $buy, 'buy', '2026-03-10' );
$second_buy = Creature_Demo_Loan::choose( $buy, 'buy', '2026-03-11' );
creature_demo_expect( ! empty( $first_buy['ok'] ) && 'buy' === $first_buy['choice'], 'buy new is recorded' );
creature_demo_expect( ! empty( $second_buy['idempotent'] ), 'choosing buy twice is idempotent' );
creature_demo_expect( $buy_mails + 1 === creature_demo_mail_count( 'choice_buy_recorded' ), 'the buy acknowledgement is sent once' );
creature_demo_expect( $admin_mails + 1 === creature_demo_mail_count( 'admin_return_booked' ), 'buying new still alerts the shop to send the QR' );
creature_demo_expect( 'demo-booked' === $buy->get_status(), 'buying new books the return' );
creature_demo_expect( 'buy' === $buy->get_meta( '_creature_demo_choice' ), 'the stored choice stays buy' );
$admin = creature_demo_last_mail( 'admin_return_booked' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $admin['to'], 'the shop alert goes to the shop' );
creature_demo_expect( 0 !== strpos( $admin['subject'], '[TEST]' ), 'the shop alert is not prefixed [TEST]' );
$buy_mail = creature_demo_last_mail( 'choice_buy_recorded' );
creature_demo_expect( 0 === strpos( $buy_mail['subject'], '[TEST] ' ), 'the buy acknowledgement is prefixed [TEST]' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $buy_mail['to'], 'the buy acknowledgement is redirected' );
$rider_filter = Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $buy );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $rider_filter, 'Woo customer mail for a demo order is redirected' );
$subject_filter = Creature_Demo_Loan::filter_customer_subject( 'Your order', $buy );
creature_demo_expect( 0 === strpos( $subject_filter, '[TEST] ' ), 'Woo customer mail for a demo order is prefixed' );
$other = wc_create_order();
creature_demo_expect( 'rider@example.com' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $other ), 'Woo mail for a normal order is unchanged' );

$on_time = Creature_Demo_Loan::record_return_scan( $buy, '2026-03-24', '2026-03-24' );
creature_demo_expect( 0 === $on_time['late_fee'], 'an on-time scan has no late fee' );
$inspected = Creature_Demo_Loan::complete_inspection( $buy, '2026-03-24' );
$again_inspected = Creature_Demo_Loan::complete_inspection( $buy, '2026-03-25' );
creature_demo_expect( ! empty( $again_inspected['idempotent'] ), 'inspection twice is idempotent' );
creature_demo_expect( 'demo-closed' === $buy->get_status(), 'an on-time buy return closes the loan' );
creature_demo_expect( ! empty( $inspected['coupon']['code'] ), 'inspection issues the £25 code' );
$spec = $inspected['coupon']['spec'];
creature_demo_expect( 'fixed_cart' === $spec['discount_type'], 'the code is a fixed cart discount' );
creature_demo_expect( '25.00' === $spec['amount'], 'the code is £25' );
creature_demo_expect( true === $spec['individual_use'], 'the code is individual use' );
creature_demo_expect( 1 === $spec['usage_limit'] && 1 === $spec['usage_limit_per_user'], 'the code is single use' );
creature_demo_expect( array( 'rider@example.com' ) === $spec['email_restrictions'], 'the code is restricted to the booking email' );
creature_demo_expect( array( 6687 ) === $spec['product_ids'], 'the code is restricted to product 6687' );
creature_demo_expect( Creature_Demo_Loan_Calendar::add_days( '2026-03-24', 60 ) === $spec['date_expires'], 'the code expires 60 days after issue' );
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_coupons'] ), 'one coupon record is saved' );
creature_demo_expect( 1 === creature_demo_mail_count( 'coupon_issued' ), 'the code email is sent once' );
$coupon_again = Creature_Demo_Loan::issue_coupon( $buy, '2026-03-24' );
creature_demo_expect( ! empty( $coupon_again['idempotent'] ) && $inspected['coupon']['code'] === $coupon_again['code'], 'issuing the code twice returns the same code' );
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_coupons'] ), 'a second issue does not create another coupon' );

$switch = creature_demo_loan();
creature_demo_expect( 'return' === Creature_Demo_Loan::effective_choice( $switch ), 'no choice means return' );
$after_due = Creature_Demo_Loan::choose( $switch, 'buy', '2026-03-25' );
creature_demo_expect( ! empty( $after_due['ok'] ), 'buy and return can still switch after the due date before a scan' );
$to_return = Creature_Demo_Loan::choose( $switch, 'return', '2026-03-26' );
creature_demo_expect( ! empty( $to_return['ok'] ) && 'return' === $switch->get_meta( '_creature_demo_choice' ), 'the rider can switch from buy to return' );
$return_mail_count = creature_demo_mail_count( 'choice_return_recorded' );
$admin_before = creature_demo_mail_count( 'admin_return_booked' );
$back_to_buy = Creature_Demo_Loan::choose( $switch, 'buy', '2026-03-27' );
creature_demo_expect( ! empty( $back_to_buy['ok'] ) && 'buy' === $switch->get_meta( '_creature_demo_choice' ), 'the rider can switch from return back to buy' );
creature_demo_expect( $return_mail_count === creature_demo_mail_count( 'choice_return_recorded' ), 'switching away does not send the return email again' );
creature_demo_expect( $admin_before === creature_demo_mail_count( 'admin_return_booked' ), 'the shop QR alert is not repeated' );
Creature_Demo_Loan::record_return_scan( $switch, '2026-03-28', '2026-03-28' );
$after_scan = Creature_Demo_Loan::choose( $switch, 'return', '2026-03-28' );
creature_demo_expect( empty( $after_scan['ok'] ) && 'window' === $after_scan['reason'], 'a scan closes buy and return switching' );

$tight = creature_demo_loan();
$GLOBALS['creature_demo_switch_until'] = 'due_date';
$late_switch = Creature_Demo_Loan::choose( $tight, 'buy', '2026-03-25' );
creature_demo_expect( empty( $late_switch['ok'] ), 'the due-date window refuses a switch after the due date' );
$on_due = Creature_Demo_Loan::choose( $tight, 'return', '2026-03-24' );
creature_demo_expect( ! empty( $on_due['ok'] ), 'the due-date window still allows a choice on the due date' );
$GLOBALS['creature_demo_switch_until'] = '';

$keep_late = creature_demo_loan();
$day_before = Creature_Demo_Loan::choose( $keep_late, 'keep', '2026-04-06' );
creature_demo_expect( ! empty( $day_before['ok'] ) && 21200 === $day_before['amount'], 'keep is open the day before the deemed purchase' );
$keep_closed = creature_demo_loan();
$on_deemed = Creature_Demo_Loan::choose( $keep_closed, 'keep', '2026-04-07' );
creature_demo_expect( empty( $on_deemed['ok'] ), 'keep closes at the deemed-purchase point' );

$lapse = creature_demo_loan( '2026-04-29', '2026-05-01' );
Creature_Demo_Loan::choose( $lapse, 'buy', '2026-05-01' );
$keep_choice = Creature_Demo_Loan::choose( $lapse, 'keep', '2026-05-01' );
creature_demo_expect( ! empty( $keep_choice['ok'] ), 'keep can be chosen on the May delivery date' );
$too_soon = Creature_Demo_Loan::maybe_lapse_keep( $lapse, '2026-05-06' );
creature_demo_expect( empty( $too_soon['ok'] ) && 'too_soon' === $too_soon['reason'], 'two working days is too soon to lapse a keep link' );
$lapsed = Creature_Demo_Loan::maybe_lapse_keep( $lapse, '2026-05-07' );
creature_demo_expect( ! empty( $lapsed['lapsed'] ), 'three working days lapses an unpaid keep link' );
creature_demo_expect( 'buy' === $lapse->get_meta( '_creature_demo_choice' ), 'a lapsed keep restores the earlier buy choice' );
creature_demo_expect( 1 === creature_demo_mail_count( 'keep_choice_lapsed' ), 'the lapse email is sent' );
$lapsed_again = Creature_Demo_Loan::maybe_lapse_keep( $lapse, '2026-05-08' );
creature_demo_expect( ! empty( $lapsed_again['idempotent'] ), 'lapsing twice is idempotent' );

$short = creature_demo_loan( '2026-04-29', '2026-05-01' );
$GLOBALS['creature_demo_lapse_days'] = 1;
Creature_Demo_Loan::choose( $short, 'keep', '2026-05-01' );
$short_lapse = Creature_Demo_Loan::maybe_lapse_keep( $short, '2026-05-05' );
creature_demo_expect( ! empty( $short_lapse['lapsed'] ), 'a one-working-day lapse setting lapses on the Tuesday after the bank holiday' );
$GLOBALS['creature_demo_lapse_days'] = null;

$keep = creature_demo_loan();
$keep_once = Creature_Demo_Loan::choose( $keep, 'keep', '2026-03-10' );
$keep_twice = Creature_Demo_Loan::choose( $keep, 'keep', '2026-03-10' );
creature_demo_expect( 21200 === $keep_once['amount'], 'the default keep price is £212' );
creature_demo_expect( ! empty( $keep_twice['idempotent'] ), 'choosing keep twice does not open a second charge' );
creature_demo_expect( 'demo-loan' === $keep->get_status(), 'an unpaid keep choice leaves the loan open' );
$keep_notice = creature_demo_last_mail( 'charge_notice_keep' );
creature_demo_expect( false !== strpos( $keep_notice['body'], '£212.00' ), 'the keep notice names the amount' );
creature_demo_expect( false !== strpos( $keep_notice['body'], 'https://staging.example/checkout/order-pay/' ), 'the keep notice includes the payment link' );
creature_demo_expect( 0 === strpos( $keep_notice['subject'], '[TEST] ' ), 'the keep notice is a test email' );
$fleet_before = count( $GLOBALS['creature_demo_fleet'] );
Creature_Demo_Loan::on_payment_complete( $keep_once['order_id'] );
Creature_Demo_Loan::on_payment_complete( $keep_once['order_id'] );
creature_demo_expect( 'demo-kept' === $keep->get_status(), 'paying the keep link closes the loan as kept' );
creature_demo_expect( 'yes' === $keep->get_meta( '_creature_demo_unit_sold' ), 'the kept unit is marked sold' );
creature_demo_expect( '' === $keep->get_meta( '_creature_demo_coupon_code' ), 'keeping the kit does not issue a coupon' );
creature_demo_expect( $fleet_before + 1 === count( $GLOBALS['creature_demo_fleet'] ), 'the fleet hook runs once' );
creature_demo_expect( 'keep' === $GLOBALS['creature_demo_fleet'][ count( $GLOBALS['creature_demo_fleet'] ) - 1 ][1], 'the fleet hook records a keep sale' );
$not_buy = Creature_Demo_Loan::issue_coupon( $keep, '2026-03-10' );
creature_demo_expect( empty( $not_buy['ok'] ) && 'not_buy' === $not_buy['reason'], 'a kept loan does not get the £25 code' );

$GLOBALS['creature_demo_keep_included'] = false;
$added = creature_demo_loan();
$added->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$added_keep = Creature_Demo_Loan::choose( $added, 'keep', '2026-04-06' );
creature_demo_expect( 23200 === $added_keep['amount'], 'with the flag off, unpaid late fees are added to £212' );
$GLOBALS['creature_demo_keep_included'] = true;
$inside = creature_demo_loan();
$inside->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$inside_keep = Creature_Demo_Loan::choose( $inside, 'keep', '2026-04-06' );
creature_demo_expect( 19200 === $inside_keep['amount'], 'with the flag on, paid late fees come off £212 and accrued fees are not added' );
$GLOBALS['creature_demo_keep_included'] = null;

$return_only = creature_demo_loan();
$return_a = Creature_Demo_Loan::choose( $return_only, 'return', '2026-03-12' );
$return_b = Creature_Demo_Loan::choose( $return_only, 'return', '2026-03-13' );
creature_demo_expect( ! empty( $return_a['ok'] ) && ! empty( $return_b['idempotent'] ), 'choosing return twice is idempotent' );
creature_demo_expect( 'demo-booked' === $return_only->get_status(), 'return sets Return booked' );
$return_sent = 0;
foreach ( Creature_Demo_Loan_Mail::$sent as $row ) {
	if ( 'choice_return_recorded' === $row['slot'] && false !== strpos( $row['body'], (string) $return_only->get_id() ) ) {
		$return_sent++;
	}
}
creature_demo_expect( 1 === $return_sent, 'this return booking emails the rider once' );

creature_demo_stripe_test();
$late = creature_demo_loan();
$scanned = Creature_Demo_Loan::record_return_scan( $late, '2026-03-30', '2026-03-30' );
creature_demo_expect( 500 === $scanned['late_fee'], 'the day-6 scan raises a £5 late fee' );
$repeat_scan = Creature_Demo_Loan::record_return_scan( $late, '2026-04-06', '2026-04-06' );
creature_demo_expect( ! empty( $repeat_scan['idempotent'] ) && '2026-03-30' === $repeat_scan['scan'], 'a second scan keeps the first date' );
$late_id = (int) $late->get_meta( '_creature_demo_late_fee_order_id' );
$late_charge = wc_get_order( $late_id );
creature_demo_expect( 'yes' === $late_charge->get_meta( '_creature_demo_charge_notice_logged' ), 'the late-fee notice is logged before any charge' );
$late_mail = creature_demo_last_mail( 'charge_notice_late_fee' );
creature_demo_expect( false !== strpos( $late_mail['body'], '£5.00' ), 'the late-fee email names £5' );
$stripe_calls = count( WC_Stripe_API::$requests );
$too_soon_charge = Creature_Demo_Loan::charge_saved_card( $late, '2026-03-30' );
creature_demo_expect( 'notice_too_recent' === $too_soon_charge['code'], 'the notice day itself cannot charge' );
$next_day = Creature_Demo_Loan::charge_saved_card( $late, '2026-03-31' );
creature_demo_expect( 'notice_too_recent' === $next_day['code'], 'one working day is not enough' );
creature_demo_expect( $stripe_calls === count( WC_Stripe_API::$requests ), 'a refused charge does not call Stripe' );

$weekend = creature_demo_loan();
Creature_Demo_Loan::choose( $weekend, 'keep', '2026-03-06' );
$monday = Creature_Demo_Loan::charge_saved_card( $weekend, '2026-03-09' );
creature_demo_expect( 'notice_too_recent' === $monday['code'], 'a weekend does not count towards the 3 working days' );

$holiday = creature_demo_loan( '2026-04-29', '2026-05-01' );
Creature_Demo_Loan::choose( $holiday, 'keep', '2026-05-01' );
$after_holiday = Creature_Demo_Loan::charge_saved_card( $holiday, '2026-05-05' );
creature_demo_expect( 'notice_too_recent' === $after_holiday['code'], 'the May bank holiday does not count towards the 3 working days' );
creature_demo_expect( $stripe_calls === count( WC_Stripe_API::$requests ), 'bank-holiday and weekend refusals do not call Stripe' );

$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'no';
$live_mode = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-02' );
creature_demo_expect( 'live_mode' === $live_mode['code'], 'live mode is refused' );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'yes';
WC_Stripe_API::$secret = 'sk_live_' . 'placeholder';
$live_key = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-02' );
creature_demo_expect( 'live_key' === $live_key['code'], 'a live secret key is refused in test mode' );
WC_Stripe_API::$secret = 'sk_test_' . 'placeholder';
creature_demo_expect( $stripe_calls === count( WC_Stripe_API::$requests ), 'live-mode refusals do not call Stripe' );

$late->update_meta_data( '_stripe_customer_id', '' );
$late->update_meta_data( '_stripe_source_id', '' );
$no_card = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-02' );
creature_demo_expect( 'no_saved_method' === $no_card['code'], 'a loan with no saved card is refused' );
$late->update_meta_data( '_stripe_customer_id', 'cus_test_walker' );
$late->update_meta_data( '_stripe_source_id', 'pm_test_walker' );

$bare = creature_demo_loan();
$no_notice = Creature_Demo_Loan::charge_saved_card( $bare, '2026-04-02' );
creature_demo_expect( 'no_notice' === $no_notice['code'], 'a loan with no charge notice is refused' );

$unconsented = wc_create_order();
$unconsented->update_meta_data( '_stripe_customer_id', 'cus_test_walker' );
$unconsented->update_meta_data( '_stripe_source_id', 'pm_test_walker' );
Creature_Demo_Loan::start_loan( $unconsented, '2026-03-02', '2026-03-04' );
Creature_Demo_Loan::record_return_scan( $unconsented, '2026-03-30', '2026-03-30' );
$consent_calls = count( WC_Stripe_API::$requests );
$refused_consent = Creature_Demo_Loan::charge_saved_card( $unconsented, '2026-04-02' );
creature_demo_expect( 'no_consent' === $refused_consent['code'], 'a charge without recorded consent is refused' );
creature_demo_expect( $consent_calls === count( WC_Stripe_API::$requests ), 'missing consent does not call Stripe' );

WC_Stripe_API::$response = new WP_Error( 'stripe_error', 'authentication_required' );
$auth = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-02' );
creature_demo_expect( 'authentication_required' === $auth['code'], 'authentication required does not take the money' );
creature_demo_expect( 'yes' !== $late_charge->get_meta( '_creature_demo_charge_paid' ), 'an authentication challenge leaves the charge unpaid' );
creature_demo_expect( '' === (string) $late_charge->get_meta( '_creature_demo_charge_lock' ), 'authentication required releases the saved-card lock' );
creature_demo_expect( 1 === creature_demo_mail_count( 'charge_authentication_required' ), 'authentication required emails the payment link' );
$auth_key = $late_charge->get_meta( '_creature_demo_idempotency_key' );
creature_demo_expect( '' !== $auth_key, 'the first attempt stores an idempotency key' );

WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);
$paid = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-02' );
$paid_again = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-03' );
creature_demo_expect( ! empty( $paid['ok'] ) && 'pi_test_demo_1' === $paid['id'], 'a clear gate charges the saved card once' );
creature_demo_expect( 'already_paid' === $paid_again['code'], 'a paid charge is not taken again' );
creature_demo_expect( '500' === (string) $late->get_meta( '_creature_demo_late_fees_paid_pence' ), 'the £5 late fee is recorded once' );
creature_demo_expect( 'processing' === $late_charge->get_status(), 'a successful saved-card charge marks the charge order processing' );
creature_demo_expect( 'pi_test_demo_1' === $late_charge->get_transaction_id(), 'the charge order stores the Stripe payment id' );
creature_demo_expect( false === Creature_Demo_Loan::filter_needs_payment( true, $late_charge ), 'the pay link cannot take a second payment' );
creature_demo_expect( 'yes' === $late_charge->get_meta( '_creature_demo_charge_order' ), 'the charge order is marked as a demo charge' );
creature_demo_expect( '' === (string) $late_charge->get_meta( '_creature_demo_charge_lock' ), 'a successful charge clears the lock' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $late_charge ), 'Woo customer mail for a charge order is redirected' );
creature_demo_expect( 0 === strpos( Creature_Demo_Loan::filter_customer_subject( 'Your order', $late_charge ), '[TEST] ' ), 'Woo customer mail for a charge order is prefixed' );
$success_requests = 0;
$keys = array();
foreach ( WC_Stripe_API::$requests as $request ) {
	if ( 'payment_intents' === $request['endpoint'] && isset( $request['body']['metadata']['charge_order_id'] ) && (string) $late_id === (string) $request['body']['metadata']['charge_order_id'] && 'succeeded' === WC_Stripe_API::$response->status ) {
		$keys[] = isset( $request['headers']['Idempotency-Key'] ) ? $request['headers']['Idempotency-Key'] : '';
	}
}
foreach ( WC_Stripe_API::$requests as $request ) {
	if ( isset( $request['headers']['Idempotency-Key'] ) && $request['headers']['Idempotency-Key'] === $auth_key ) {
		$success_requests++;
		creature_demo_expect( 'true' === $request['body']['off_session'], 'the PaymentIntent is off_session' );
		creature_demo_expect( 'true' === $request['body']['confirm'], 'the PaymentIntent is confirmed' );
		creature_demo_expect( 500 === $request['body']['amount'], 'the PaymentIntent amount is 500 pence' );
		creature_demo_expect( 'cus_test_walker' === $request['body']['customer'], 'the PaymentIntent uses the saved customer' );
		creature_demo_expect( 'pm_test_walker' === $request['body']['payment_method'], 'the PaymentIntent uses the saved payment method' );
	}
}
creature_demo_expect( 2 === $success_requests, 'authentication and the later success share one idempotency key' );
$joined_notes = implode( "\n", $late->notes );
creature_demo_expect( false !== strpos( $joined_notes, 'pi_test_demo_1' ), 'the order note records the PaymentIntent id' );

$retry = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $retry, '2026-03-30', '2026-03-30' );
$retry_charge = wc_get_order( (int) $retry->get_meta( '_creature_demo_late_fee_order_id' ) );
WC_Stripe_API::$response = (object) array(
	'error' => (object) array(
		'code'         => 'card_declined',
		'decline_code' => 'generic_decline',
	),
);
$declined = Creature_Demo_Loan::charge_saved_card( $retry, '2026-04-02' );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_2',
	'status' => 'succeeded',
);
$retried = Creature_Demo_Loan::charge_saved_card( $retry, '2026-04-02' );
creature_demo_expect( 'stripe_error' === $declined['code'], 'a decline is a stripe error and not a paid charge' );
creature_demo_expect( '' === (string) $retry_charge->get_meta( '_creature_demo_charge_lock' ), 'a declined charge releases the lock so the pay link still works' );
creature_demo_expect( ! empty( $retried['ok'] ), 'the same charge can be retried after a decline' );
creature_demo_expect( $retry_charge->get_meta( '_creature_demo_idempotency_key' ) === Creature_Demo_Loan::idempotency_key( $retry_charge ), 'a retry keeps the original idempotency key' );

$disputed = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $disputed, '2026-03-30', '2026-03-30' );
$dispute_charge = wc_get_order( (int) $disputed->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::mark_disputed( $dispute_charge, '2026-03-30' );
Creature_Demo_Loan::mark_disputed( $dispute_charge, '2026-03-31' );
$while_open = Creature_Demo_Loan::charge_saved_card( $disputed, '2026-04-02' );
creature_demo_expect( 'disputed' === $while_open['code'], 'an open dispute blocks the saved card' );
$upheld = Creature_Demo_Loan::record_dispute_decision( $dispute_charge, 'upheld', '2026-04-02', null );
creature_demo_expect( ! empty( $upheld['timely'] ), 'a decision 3 days later is inside 14 days' );
$calls_before_uphold = count( WC_Stripe_API::$requests );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_3',
	'status' => 'succeeded',
);
$after_uphold = Creature_Demo_Loan::charge_saved_card( $disputed, '2026-04-02' );
creature_demo_expect( ! empty( $after_uphold['ok'] ), 'upholding the dispute lets the charge through' );
creature_demo_expect( count( WC_Stripe_API::$requests ) === $calls_before_uphold + 1, 'the upheld charge calls Stripe once' );
$decision_mail = creature_demo_last_mail( 'dispute_decision' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $decision_mail['to'], 'the dispute decision is redirected' );
creature_demo_expect( false !== strpos( $decision_mail['body'], 'upheld' ), 'the decision email names the outcome' );

$cap_loan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cap_loan, '2026-03-30', '2026-03-30' );
$cap_charge = wc_get_order( (int) $cap_loan->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::mark_disputed( $cap_charge, '2026-03-30' );
$cap_key = Creature_Demo_Loan::idempotency_key( $cap_charge );
$over_cap = Creature_Demo_Loan::record_dispute_decision( $cap_charge, 'upheld', '2026-04-02', 9000 );
creature_demo_expect( ! empty( $over_cap['capped'] ) && 500 === $over_cap['amount'], 'an upheld dispute cannot exceed the original notice' );
creature_demo_expect( $cap_key === $cap_charge->get_meta( '_creature_demo_idempotency_key' ), 'capping to the same amount keeps the idempotency key' );
$notice_mails = creature_demo_mail_count( 'charge_notice_late_fee' );
$lowered = Creature_Demo_Loan::record_dispute_decision( $cap_charge, 'upheld', '2026-04-02', 200 );
creature_demo_expect( 200 === $lowered['amount'], 'an upheld amount below the notice is kept' );
creature_demo_expect( '' === $cap_charge->get_meta( '_creature_demo_idempotency_key' ), 'a changed amount clears the stored idempotency key' );
creature_demo_expect( $notice_mails + 1 === creature_demo_mail_count( 'charge_notice_late_fee' ), 'a changed amount logs a fresh notice' );
$rebuilt_key = Creature_Demo_Loan::idempotency_key( $cap_charge );
creature_demo_expect( $rebuilt_key !== $cap_key && false !== strpos( $rebuilt_key, '200' ), 'the next idempotency key uses the new amount' );

$alert_loan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $alert_loan, '2026-03-30', '2026-03-30' );
$alert_charge = wc_get_order( (int) $alert_loan->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::mark_disputed( $alert_charge, '2026-03-30' );
$alerts = creature_demo_mail_count( 'admin_dispute_nearing' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-08' );
creature_demo_expect( $alerts === creature_demo_mail_count( 'admin_dispute_nearing' ), 'an open dispute does not alert before day 10' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-09' );
creature_demo_expect( $alerts + 1 === creature_demo_mail_count( 'admin_dispute_nearing' ), 'an open dispute alerts the shop on day 10' );
$day_ten = creature_demo_last_mail( 'admin_dispute_nearing' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $day_ten['to'] && 0 !== strpos( $day_ten['subject'], '[TEST]' ), 'the dispute alert goes to the shop without a test prefix' );
creature_demo_expect( false !== strpos( $day_ten['body'], '2026-03-30' ), 'the dispute alert names the day it opened' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-10' );
creature_demo_expect( $alerts + 1 === creature_demo_mail_count( 'admin_dispute_nearing' ), 'the day-10 alert is sent once' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-13' );
creature_demo_expect( $alerts + 2 === creature_demo_mail_count( 'admin_dispute_nearing' ), 'an open dispute alerts again on day 14' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-14' );
creature_demo_expect( $alerts + 2 === creature_demo_mail_count( 'admin_dispute_nearing' ), 'the day-14 alert is sent once' );

$plain_actions = Creature_Demo_Loan::filter_order_actions( array( 'send_order_details' => 'Email' ), wc_create_order() );
creature_demo_expect( ! isset( $plain_actions['creature_demo_start_loan'] ) && isset( $plain_actions['send_order_details'] ), 'a normal order does not get demo actions' );
$demo_actions = Creature_Demo_Loan::filter_order_actions( array(), $alert_loan );
creature_demo_expect( isset( $demo_actions['creature_demo_start_loan'] ), 'a demo order gets the demo actions' );
$charge_actions = Creature_Demo_Loan::filter_order_actions( array(), $alert_charge );
creature_demo_expect( isset( $charge_actions['creature_demo_charge_saved_card'] ), 'a charge order gets the demo actions' );

$waived = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $waived, '2026-03-30', '2026-03-30' );
$waive_charge = wc_get_order( (int) $waived->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::mark_disputed( $waive_charge, '2026-03-30' );
Creature_Demo_Loan::record_dispute_decision( $waive_charge, 'waived', '2026-04-20', null );
$late_decision = Creature_Demo_Loan_Calendar::days_between( '2026-03-30', '2026-04-20' );
creature_demo_expect( $late_decision > 14, 'this fixture is outside 14 days' );
creature_demo_expect( 'no' === $waive_charge->get_meta( '_creature_demo_dispute_decision_within_14_days' ), 'a late decision is marked outside 14 days' );
$waive_calls = count( WC_Stripe_API::$requests );
$after_waive = Creature_Demo_Loan::charge_saved_card( $waived, '2026-04-20' );
creature_demo_expect( 'dispute_waived' === $after_waive['code'], 'a waived dispute does not charge the card' );
creature_demo_expect( $waive_calls === count( WC_Stripe_API::$requests ), 'a waived dispute does not call Stripe' );

$handler_day = Creature_Demo_Loan_Calendar::today( null );
$future = creature_demo_loan( $handler_day, $handler_day );
$GLOBALS['creature_demo_user'] = 42;
$_GET = array(
	'creature_demo_choice' => 'buy',
	'creature_demo_order'  => (string) $future->get_id(),
	'_wpnonce'             => 'creature_demo_choice_' . $future->get_id(),
);
Creature_Demo_Loan::maybe_handle_choice_request();
creature_demo_expect( 'buy' === $future->get_meta( '_creature_demo_choice' ), 'a nonce from the account holder records the choice' );
$stranger = creature_demo_loan( $handler_day, $handler_day );
$GLOBALS['creature_demo_user'] = 7;
$_GET = array(
	'creature_demo_choice' => 'return',
	'creature_demo_order'  => (string) $stranger->get_id(),
	'_wpnonce'             => 'creature_demo_choice_' . $stranger->get_id(),
);
Creature_Demo_Loan::maybe_handle_choice_request();
creature_demo_expect( '' === $stranger->get_meta( '_creature_demo_choice' ), 'another customer nonce does not change the loan' );
$GLOBALS['creature_demo_user'] = 42;
$_GET = array(
	'creature_demo_choice' => 'return',
	'creature_demo_order'  => (string) $stranger->get_id(),
	'_wpnonce'             => 'nope',
	'creature_demo_token'  => (string) $stranger->get_meta( '_creature_demo_action_token' ),
);
Creature_Demo_Loan::maybe_handle_choice_request();
creature_demo_expect( 'return' === $stranger->get_meta( '_creature_demo_choice' ), 'the order token plus the account holder records the choice' );
$token_thief = creature_demo_loan( $handler_day, $handler_day );
$GLOBALS['creature_demo_user'] = 7;
$_GET = array(
	'creature_demo_choice' => 'keep',
	'creature_demo_order'  => (string) $token_thief->get_id(),
	'_wpnonce'             => 'nope',
	'creature_demo_token'  => (string) $token_thief->get_meta( '_creature_demo_action_token' ),
);
Creature_Demo_Loan::maybe_handle_choice_request();
creature_demo_expect( '' === $token_thief->get_meta( '_creature_demo_choice' ), 'the token without the account holder does nothing' );
$_GET = array();

$deemed = creature_demo_loan();
$too_early = Creature_Demo_Loan::maybe_raise_deemed( $deemed, '2026-04-06' );
creature_demo_expect( empty( $too_early['ok'] ) && 'too_soon' === $too_early['reason'], 'deemed purchase waits until day 14' );
$raised = Creature_Demo_Loan::maybe_raise_deemed( $deemed, '2026-04-07' );
$raised_again = Creature_Demo_Loan::maybe_raise_deemed( $deemed, '2026-04-08' );
creature_demo_expect( 21200 === $raised['amount'], 'deemed purchase with no late fees paid is £212' );
creature_demo_expect( ! empty( $raised_again['idempotent'] ) && $raised['order_id'] === $raised_again['order_id'], 'raising the deemed purchase twice keeps the same order' );
creature_demo_expect( 'demo-deemed' === $deemed->get_status(), 'the loan status becomes Deemed purchase' );
$no_coupon = Creature_Demo_Loan::issue_coupon( $deemed, '2026-04-07' );
creature_demo_expect( empty( $no_coupon['ok'] ), 'a deemed purchase does not issue the £25 code' );

$net = creature_demo_loan();
$net->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$GLOBALS['creature_demo_keep_included'] = false;
$net_raised = Creature_Demo_Loan::maybe_raise_deemed( $net, '2026-04-07' );
$GLOBALS['creature_demo_keep_included'] = null;
creature_demo_expect( 19200 === $net_raised['amount'], 'deemed purchase ignores the keep flag and still nets off late fees' );
$net_charge = wc_get_order( $net_raised['order_id'] );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_deemed_1',
	'status' => 'succeeded',
);
$net_paid = Creature_Demo_Loan::charge_saved_card( $net, '2026-04-10' );
creature_demo_expect( ! empty( $net_paid['ok'] ), 'the deemed purchase can be charged after 3 working days' );
creature_demo_expect( 'yes' === $net->get_meta( '_creature_demo_unit_sold' ), 'a paid deemed purchase marks the unit sold' );
creature_demo_expect( '' === $net->get_meta( '_creature_demo_coupon_code' ), 'a paid deemed purchase does not issue a coupon' );
$deemed_fleet = false;
foreach ( $GLOBALS['creature_demo_fleet'] as $row ) {
	if ( $row[0] === (int) $net->get_id() && 'deemed' === $row[1] ) {
		$deemed_fleet = true;
	}
}
creature_demo_expect( $deemed_fleet, 'a paid deemed purchase removes the unit from the fleet' );

$back_after = creature_demo_loan();
$back_after->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$back_after->update_meta_data( '_creature_demo_ever_chose_keep', 'yes' );
$back_raised = Creature_Demo_Loan::maybe_raise_deemed( $back_after, '2026-04-07' );
$back_charge = wc_get_order( $back_raised['order_id'] );
$back_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_refund' );
Creature_Demo_Loan::on_payment_complete( $back_raised['order_id'] );
Creature_Demo_Loan::record_return_scan( $back_after, '2026-04-08', '2026-04-08' );
$awaiting = Creature_Demo_Loan::complete_inspection( $back_after, '2026-04-08' );
creature_demo_expect( ! empty( $awaiting['awaiting_refund'] ), 'inspection after a deemed purchase waits for the refund action' );
creature_demo_expect( 'demo-deemed' === $back_after->get_status(), 'inspection does not close a deemed purchase on its own' );
$back_after->update_meta_data( '_creature_demo_damage_pence', 1500 );
$GLOBALS['creature_demo_refund_flag'] = false;
$refused = Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-09' );
creature_demo_expect( empty( $refused['ok'] ) && 'keep_choice' === $refused['reason'], 'with the flag off, a keep chooser is not refunded' );
$GLOBALS['creature_demo_refund_flag'] = true;
WC_Stripe_API::$response = (object) array(
	'id'     => 're_test_demo_1',
	'status' => 'succeeded',
);
$refunded = Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-09' );
$refunded_again = Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-10' );
creature_demo_expect( ! empty( $refunded['ok'] ) && 15700 === $refunded['amount'], 'with the flag on, the keep chooser is refunded £157' );
creature_demo_expect( ! empty( $refunded_again['idempotent'] ), 'the refund is recorded once' );
$refund_notes = implode( "\n", $back_after->notes );
creature_demo_expect( false !== strpos( $refund_notes, 're_test_demo_1' ), 'the Stripe refund id is noted' );
$GLOBALS['creature_demo_refund_flag'] = null;

$never_keep = creature_demo_loan();
$never_raised = Creature_Demo_Loan::maybe_raise_deemed( $never_keep, '2026-04-07' );
$never_charge = wc_get_order( $never_raised['order_id'] );
$never_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_never' );
WC_Stripe_API::$response = (object) array(
	'id'     => 're_test_never_keep',
	'status' => 'succeeded',
);
Creature_Demo_Loan::on_payment_complete( $never_raised['order_id'] );
Creature_Demo_Loan::record_return_scan( $never_keep, '2026-04-08', '2026-04-08' );
Creature_Demo_Loan::complete_inspection( $never_keep, '2026-04-08' );
$GLOBALS['creature_demo_refund_flag'] = false;
$still = Creature_Demo_Loan::refund_deemed( $never_keep, '2026-04-09' );
$GLOBALS['creature_demo_refund_flag'] = null;
creature_demo_expect( ! empty( $still['ok'] ) && 17200 === $still['amount'], 'with the flag off, a rider who never chose keep is still refunded' );

$failed_refund = creature_demo_loan();
$failed_raised = Creature_Demo_Loan::maybe_raise_deemed( $failed_refund, '2026-04-07' );
$failed_charge = wc_get_order( $failed_raised['order_id'] );
$failed_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_fail' );
Creature_Demo_Loan::on_payment_complete( $failed_raised['order_id'] );
Creature_Demo_Loan::record_return_scan( $failed_refund, '2026-04-08', '2026-04-08' );
Creature_Demo_Loan::complete_inspection( $failed_refund, '2026-04-08' );
WC_Stripe_API::$response = (object) array(
	'error' => (object) array( 'code' => 'api_error' ),
);
$not_confirmed = Creature_Demo_Loan::refund_deemed( $failed_refund, '2026-04-09' );
creature_demo_expect( empty( $not_confirmed['ok'] ) && 'not_confirmed' === $not_confirmed['reason'], 'a refund is not marked done when Stripe does not confirm' );
creature_demo_expect( 'yes' !== $failed_refund->get_meta( '_creature_demo_refund_done' ), 'the refund-done flag stays unset after a failed Stripe refund' );
WC_Stripe_API::$response = (object) array(
	'id'     => 're_test_demo_retry',
	'status' => 'succeeded',
);
$confirmed_later = Creature_Demo_Loan::refund_deemed( $failed_refund, '2026-04-10' );
creature_demo_expect( ! empty( $confirmed_later['ok'] ) && 'yes' === $failed_refund->get_meta( '_creature_demo_refund_done' ), 'the refund is marked done only after Stripe confirms' );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'no';
$blocked_refund = creature_demo_loan();
$blocked_raised = Creature_Demo_Loan::maybe_raise_deemed( $blocked_refund, '2026-04-07' );
$blocked_charge = wc_get_order( $blocked_raised['order_id'] );
$blocked_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_blocked' );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'yes';
Creature_Demo_Loan::on_payment_complete( $blocked_raised['order_id'] );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'no';
Creature_Demo_Loan::record_return_scan( $blocked_refund, '2026-04-08', '2026-04-08' );
Creature_Demo_Loan::complete_inspection( $blocked_refund, '2026-04-08' );
$not_sent = Creature_Demo_Loan::refund_deemed( $blocked_refund, '2026-04-09' );
creature_demo_expect( empty( $not_sent['ok'] ) && 'not_sent' === $not_sent['reason'], 'a refund is not marked done when the mode gate blocks Stripe' );
creature_demo_expect( 'yes' !== $blocked_refund->get_meta( '_creature_demo_refund_done' ), 'a blocked refund can be tried again' );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'yes';

$damage = creature_demo_loan();
$mount = Creature_Demo_Loan::raise_damage_charge( $damage, 3500, 'Inverted mount kit', '2026-03-20' );
$mount_again = Creature_Demo_Loan::raise_damage_charge( $damage, 3500, 'Inverted mount kit', '2026-03-20' );
creature_demo_expect( ! empty( $mount['ok'] ) && 3500 === $mount['amount'], 'a missing mount kit is £35' );
creature_demo_expect( $mount['order_id'] === $mount_again['order_id'], 'the damage charge is not duplicated' );
$damage_mail = creature_demo_last_mail( 'charge_notice_damage' );
creature_demo_expect( false !== strpos( $damage_mail['body'], '£35.00' ), 'the damage notice names £35' );
$same_day = Creature_Demo_Loan::charge_saved_card( $damage, '2026-03-20' );
creature_demo_expect( 'notice_too_recent' === $same_day['code'], 'the damage notice still waits 3 working days' );
$capped = creature_demo_loan();
$cap = Creature_Demo_Loan::raise_damage_charge( $capped, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 21200 === $cap['amount'], 'damage is capped at £212' );
$zero = Creature_Demo_Loan::raise_damage_charge( $capped, 0, 'Damage', '2026-03-20' );
creature_demo_expect( empty( $zero['ok'] ) && 'no_amount' === $zero['reason'], 'a zero damage amount does not create a charge' );

$pan = '4242424242424242';
$secret_body = 'sk_test_' . 'placeholder';
Creature_Demo_Loan_Stripe::off_session_intent(
	100,
	'cus_test_walker',
	'pm_test_walker',
	'demo-loan-redaction',
	array(
		'card' => $pan,
		'key'  => $secret_body,
	)
);
$last = WC_Stripe_API::$requests[ count( WC_Stripe_API::$requests ) - 1 ];
$encoded = json_encode( $last['body'] );
creature_demo_absent( $encoded, $pan, 'a card number in metadata is redacted before the Stripe request' );
creature_demo_absent( $encoded, $secret_body, 'a secret key in metadata is redacted before the Stripe request' );
creature_demo_expect( false !== strpos( $encoded, '[redacted-number]' ), 'the request keeps a redacted-number marker' );
creature_demo_expect( false !== strpos( $encoded, '[redacted-key]' ), 'the request keeps a redacted-key marker' );

$catchup = creature_demo_loan();
Creature_Demo_Loan::tick( $catchup, '2026-04-03' );
$catch_slots = array();
foreach ( Creature_Demo_Loan_Mail::$sent as $row ) {
	if ( false !== strpos( $row['body'], (string) $catchup->get_id() ) && 0 === strpos( $row['slot'], 'reminder_' ) ) {
		$catch_slots[] = $row['slot'];
	}
}
creature_demo_expect( array( 'reminder_deemed_warning' ) === $catch_slots, 'a late catch-up sends only the latest reminder' );
$catch_sent = (string) $catchup->get_meta( '_creature_demo_reminders_sent' );
foreach ( array( 'due_minus_5', 'due_day', 'grace_end', 'deemed_warning' ) as $catch_key ) {
	creature_demo_expect( false !== strpos( $catch_sent, $catch_key ), 'the catch-up marks ' . $catch_key . ' so it is not sent later' );
}

$choices = creature_demo_loan( '2026-10-26', '2026-10-27' );
$GLOBALS['creature_demo_user'] = 42;
$GLOBALS['creature_demo_order_received'] = true;
ob_start();
Creature_Demo_Loan::render_my_account_choices( $choices );
$thankyou = ob_get_clean();
creature_demo_expect( '' === $thankyou, 'the thank-you page does not show the choice buttons' );
$GLOBALS['creature_demo_order_received'] = false;
ob_start();
Creature_Demo_Loan::render_my_account_choices( $choices );
$account = ob_get_clean();
creature_demo_expect( false !== strpos( $account, 'creature_demo_choice=keep' ), 'My Account shows the keep choice' );
$_GET = array(
	'creature_demo_choice' => 'keep',
	'creature_demo_order'  => (string) $choices->get_id(),
	'_wpnonce'             => 'creature_demo_choice_' . $choices->get_id(),
);
Creature_Demo_Loan::maybe_handle_choice_request();
creature_demo_expect( '' === $choices->get_meta( '_creature_demo_choice' ), 'keep is not recorded until the rider confirms' );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $choices );
$confirm_html = ob_get_clean();
creature_demo_expect( false !== strpos( $confirm_html, '£212.00' ) && false !== strpos( $confirm_html, 'creature_demo_confirm=1' ), 'keep asks for confirmation and names the price' );
$_GET['creature_demo_confirm'] = '1';
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::maybe_handle_choice_request();
creature_demo_expect( 'keep' === $choices->get_meta( '_creature_demo_choice' ), 'confirming keep records the choice' );
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_notices'] ), 'confirming a choice leaves a notice' );
$_GET = array();
$GLOBALS['creature_demo_user'] = 0;

$mail_hooks = array();
foreach ( $GLOBALS['creature_demo_hooks'] as $hook_row ) {
	$mail_hooks[ $hook_row[0] ] = true;
}
creature_demo_expect( ! empty( $mail_hooks['woocommerce_email_recipient_customer_cancelled_order'] ), 'cancelled-order mail uses the redirect guard' );
creature_demo_expect( ! empty( $mail_hooks['woocommerce_email_subject_customer_cancelled_order'] ), 'cancelled-order subjects use the redirect guard' );
creature_demo_expect( ! empty( $mail_hooks['woocommerce_email_recipient_customer_new_account'] ), 'new-account mail uses the redirect guard' );
creature_demo_expect( ! empty( $mail_hooks['woocommerce_email_subject_customer_new_account'] ), 'new-account subjects use the redirect guard' );

$cancel_loan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cancel_loan, '2026-03-30', '2026-03-30' );
$cancel_charge = wc_get_order( (int) $cancel_loan->get_meta( '_creature_demo_late_fee_order_id' ) );
$cancel_charge->update_status( 'cancelled', 'Unpaid demo charge cancelled so it is not taken as well as a later charge.' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $cancel_charge ), 'a cancelled charge order email is redirected' );
creature_demo_expect( 0 === strpos( Creature_Demo_Loan::filter_customer_subject( 'Cancelled order', $cancel_charge ), '[TEST] ' ), 'a cancelled charge order subject is prefixed' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $cancel_loan ), 'a cancelled demo loan email is redirected' );
$plain_cancel = wc_create_order();
creature_demo_expect( 'rider@example.com' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $plain_cancel ), 'a cancelled normal order is not redirected' );

$saved_cart = $GLOBALS['creature_demo_cart'];
$GLOBALS['creature_demo_cart'] = null;
$new_account = (object) array( 'id' => 'customer_new_account' );
$rider_user  = (object) array(
	'ID'         => 42,
	'user_email' => 'rider@example.com',
);
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $rider_user, $new_account ), 'a new account for a demo rider is redirected' );
creature_demo_expect( 0 === strpos( Creature_Demo_Loan::filter_customer_subject( 'Welcome', $rider_user, $new_account ), '[TEST] ' ), 'a new account for a demo rider is prefixed' );
$stranger = (object) array(
	'ID'         => 4242,
	'user_email' => 'stranger@example.com',
);
creature_demo_expect( 'stranger@example.com' === Creature_Demo_Loan::filter_customer_recipient( 'stranger@example.com', $stranger, $new_account ), 'a new account with no demo order is unchanged' );
$charge_only = wc_create_order( array( 'customer_id' => 88 ) );
$charge_only->update_meta_data( '_creature_demo_charge_order', 'yes' );
$charge_user = (object) array(
	'ID'         => 88,
	'user_email' => 'charge-only@example.com',
);
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan::filter_customer_recipient( 'charge-only@example.com', $charge_user, $new_account ), 'a new account for a charge-order customer is redirected' );
$GLOBALS['creature_demo_cart'] = array(
	array( 'product_id' => 9001 ),
);
$checkout_user = (object) array(
	'ID'         => 777,
	'user_email' => 'new@example.com',
);
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan::filter_customer_recipient( 'new@example.com', $checkout_user, $new_account ), 'a new account during demo checkout is redirected' );
$GLOBALS['creature_demo_cart'] = $saved_cart;

$race = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $race, '2026-03-30', '2026-03-30' );
$race_charge = wc_get_order( (int) $race->get_meta( '_creature_demo_late_fee_order_id' ) );
$pay_first   = Creature_Demo_Loan::begin_pay_link( $race_charge );
creature_demo_expect( ! empty( $pay_first['ok'] ) && 'pay_link' === $race_charge->get_meta( '_creature_demo_charge_lock' ), 'the pay link takes the charge lock' );
$race_calls   = count( WC_Stripe_API::$requests );
$race_refused = Creature_Demo_Loan::charge_saved_card( $race, '2026-04-02' );
creature_demo_expect( 'payment_in_progress' === $race_refused['code'], 'a pay link in progress refuses the saved card' );
creature_demo_expect( $race_calls === count( WC_Stripe_API::$requests ), 'a pay link in progress does not call Stripe' );
creature_demo_expect( 'pay_link' === $race_charge->get_meta( '_creature_demo_charge_lock' ), 'the refusal leaves the pay-link lock in place' );

$upe = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $upe, '2026-03-30', '2026-03-30' );
$upe_charge = wc_get_order( (int) $upe->get_meta( '_creature_demo_late_fee_order_id' ) );
$upe_charge->update_meta_data( '_stripe_intent_id', 'pi_test_upe' );
$upe_charge->update_meta_data( '_stripe_intent_status', 'processing' );
$upe_charge->save();
$upe_calls   = count( WC_Stripe_API::$requests );
$upe_refused = Creature_Demo_Loan::charge_saved_card( $upe, '2026-04-02' );
creature_demo_expect( 'payment_in_progress' === $upe_refused['code'], 'a Stripe pay-link intent in progress refuses the saved card' );
creature_demo_expect( $upe_calls === count( WC_Stripe_API::$requests ), 'an in-progress pay-link intent does not call Stripe' );
creature_demo_expect( 'pending' === $upe_charge->get_status(), 'the in-progress pay link leaves the order pending' );

$done_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $done_link, '2026-03-30', '2026-03-30' );
$done_charge = wc_get_order( (int) $done_link->get_meta( '_creature_demo_late_fee_order_id' ) );
$done_charge->payment_complete( 'pi_test_paylink' );
$done_calls   = count( WC_Stripe_API::$requests );
$done_refused = Creature_Demo_Loan::charge_saved_card( $done_link, '2026-04-02' );
creature_demo_expect( 'already_paid' === $done_refused['code'], 'a completed pay link refuses the saved card' );
creature_demo_expect( $done_calls === count( WC_Stripe_API::$requests ), 'a completed pay link does not call Stripe again' );
creature_demo_expect( '500' === (string) $done_link->get_meta( '_creature_demo_late_fees_paid_pence' ), 'the pay link records the late fee once' );

$held = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $held, '2026-03-30', '2026-03-30' );
$held_charge = wc_get_order( (int) $held->get_meta( '_creature_demo_late_fee_order_id' ) );
$held_charge->update_meta_data( '_creature_demo_charge_lock', 'saved_card' );
$held_charge->save();
$held_pay = Creature_Demo_Loan::begin_pay_link( $held_charge );
creature_demo_expect( empty( $held_pay['ok'] ) && 'payment_in_progress' === $held_pay['code'], 'a saved-card lock refuses the pay link' );
creature_demo_expect( 'saved_card' === $held_charge->get_meta( '_creature_demo_charge_lock' ), 'the pay link does not replace the saved-card lock' );
creature_demo_expect( false === Creature_Demo_Loan::filter_needs_payment( true, $held_charge ), 'the pay link cannot start while the saved card is charging' );
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_before_pay_action( $held_charge );
creature_demo_expect( ! empty( $GLOBALS['creature_demo_notices'] ), 'the pay action refuses while the saved card is charging' );

$flip = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $flip, '2026-03-30', '2026-03-30' );
$flip_charge = wc_get_order( (int) $flip->get_meta( '_creature_demo_late_fee_order_id' ) );
$flip_hook   = static function ( $order ) use ( $flip_charge ) {
	if ( is_object( $order ) && (int) $order->get_id() === (int) $flip_charge->get_id() ) {
		$order->update_meta_data( '_creature_demo_charge_lock', 'pay_link' );
		$order->save();
	}
};
add_action( 'creature_demo_loan_saved_card_lock_acquired', $flip_hook );
$flip_calls = count( WC_Stripe_API::$requests );
$flipped    = Creature_Demo_Loan::charge_saved_card( $flip, '2026-04-02' );
remove_action( 'creature_demo_loan_saved_card_lock_acquired', $flip_hook );
creature_demo_expect( 'payment_in_progress' === $flipped['code'], 'a pay link that wins the lock is seen on the re-check' );
creature_demo_expect( $flip_calls === count( WC_Stripe_API::$requests ), 'the re-check does not call Stripe' );
creature_demo_expect( 'pay_link' === $flip_charge->get_meta( '_creature_demo_charge_lock' ), 'the re-check leaves the pay-link lock' );

$paid_mid = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $paid_mid, '2026-03-30', '2026-03-30' );
$paid_mid_charge = wc_get_order( (int) $paid_mid->get_meta( '_creature_demo_late_fee_order_id' ) );
$paid_hook       = static function ( $order ) {
	$order->update_meta_data( '_creature_demo_charge_paid', 'yes' );
	$order->save();
};
add_action( 'creature_demo_loan_saved_card_lock_acquired', $paid_hook );
$paid_calls      = count( WC_Stripe_API::$requests );
$paid_mid_result = Creature_Demo_Loan::charge_saved_card( $paid_mid, '2026-04-02' );
remove_action( 'creature_demo_loan_saved_card_lock_acquired', $paid_hook );
creature_demo_expect( 'already_paid' === $paid_mid_result['code'], 'a pay link that completes during the lock is seen on the re-check' );
creature_demo_expect( $paid_calls === count( WC_Stripe_API::$requests ), 'a completed pay link seen on the re-check does not call Stripe' );
creature_demo_expect( '' === (string) $paid_mid_charge->get_meta( '_creature_demo_charge_lock' ), 'the saved-card lock is released when the pay link already completed' );

$abandoned = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $abandoned, '2026-03-30', '2026-03-30' );
$abandoned_charge = wc_get_order( (int) $abandoned->get_meta( '_creature_demo_late_fee_order_id' ) );
$abandoned_charge->update_meta_data( '_stripe_intent_id', 'pi_test_abandoned' );
$abandoned_charge->update_meta_data( '_stripe_intent_status', 'requires_payment_method' );
$abandoned_charge->save();
WC_Stripe_API::$response = (object) array(
	'error' => (object) array(
		'code'         => 'card_declined',
		'decline_code' => 'generic_decline',
	),
);
$abandoned_calls  = count( WC_Stripe_API::$requests );
$abandoned_result = Creature_Demo_Loan::charge_saved_card( $abandoned, '2026-04-02' );
creature_demo_expect( 'stripe_error' === $abandoned_result['code'], 'a failed pay-link intent does not block the saved card' );
creature_demo_expect( $abandoned_calls < count( WC_Stripe_API::$requests ), 'a failed pay-link intent still reaches Stripe' );
creature_demo_expect( '' === (string) $abandoned_charge->get_meta( '_creature_demo_charge_lock' ), 'a Stripe error releases the saved-card lock' );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);

creature_demo_expect( 900 === Creature_Demo_Loan::charge_lock_stale_seconds(), 'the pay-link lock goes stale after 15 minutes' );
$GLOBALS['creature_demo_lock_now'] = 1700000000;
$declined_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $declined_link, '2026-03-30', '2026-03-30' );
$declined_charge = wc_get_order( (int) $declined_link->get_meta( '_creature_demo_late_fee_order_id' ) );
creature_demo_expect( ! empty( Creature_Demo_Loan::begin_pay_link( $declined_charge )['ok'] ), 'a declined-link test can open the pay link' );
creature_demo_expect( '1700000000' === (string) $declined_charge->get_meta( '_creature_demo_charge_lock_at' ), 'the pay-link lock stores the time it was taken' );
do_action( 'woocommerce_order_status_failed', $declined_charge->get_id(), $declined_charge );
creature_demo_expect( '' === (string) $declined_charge->get_meta( '_creature_demo_charge_lock' ), 'a failed pay-link payment releases the lock' );
creature_demo_expect( '' === (string) $declined_charge->get_meta( '_creature_demo_charge_lock_at' ), 'a failed pay-link payment clears the lock time' );
$declined_posts = count( WC_Stripe_API::$requests );
$declined_result = Creature_Demo_Loan::charge_saved_card( $declined_link, '2026-04-02' );
creature_demo_expect( ! empty( $declined_result['ok'] ), 'a declined pay link does not block Charge saved card' );
creature_demo_expect( $declined_posts < count( WC_Stripe_API::$requests ), 'a declined pay link still lets the saved card call Stripe' );

$cancelled_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cancelled_link, '2026-03-30', '2026-03-30' );
$cancelled_charge = wc_get_order( (int) $cancelled_link->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::begin_pay_link( $cancelled_charge );
do_action( 'woocommerce_order_status_cancelled', $cancelled_charge->get_id(), $cancelled_charge );
creature_demo_expect( '' === (string) $cancelled_charge->get_meta( '_creature_demo_charge_lock' ), 'a cancelled pay-link payment releases the lock' );

$stripe_fail = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $stripe_fail, '2026-03-30', '2026-03-30' );
$stripe_fail_charge = wc_get_order( (int) $stripe_fail->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::begin_pay_link( $stripe_fail_charge );
do_action( 'wc_gateway_stripe_process_payment_error', new WP_Error( 'card_declined', 'declined' ), $stripe_fail_charge );
creature_demo_expect( '' === (string) $stripe_fail_charge->get_meta( '_creature_demo_charge_lock' ), 'a Stripe pay-link failure releases the lock' );

$left_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $left_link, '2026-03-30', '2026-03-30' );
$left_charge = wc_get_order( (int) $left_link->get_meta( '_creature_demo_late_fee_order_id' ) );
Creature_Demo_Loan::begin_pay_link( $left_charge );
$left_fresh = count( WC_Stripe_API::$requests );
$left_now = Creature_Demo_Loan::charge_saved_card( $left_link, '2026-04-02' );
creature_demo_expect( 'payment_in_progress' === $left_now['code'], 'an abandoned pay link still blocks inside 15 minutes' );
creature_demo_expect( $left_fresh === count( WC_Stripe_API::$requests ), 'an abandoned pay link does not call Stripe inside 15 minutes' );
$GLOBALS['creature_demo_lock_now'] = 1700000000 + 899;
$left_almost = Creature_Demo_Loan::charge_saved_card( $left_link, '2026-04-02' );
creature_demo_expect( 'payment_in_progress' === $left_almost['code'], 'an abandoned pay link still blocks at 14 minutes 59 seconds' );
$GLOBALS['creature_demo_lock_now'] = 1700000000 + 900;
$left_later = Creature_Demo_Loan::charge_saved_card( $left_link, '2026-04-02' );
creature_demo_expect( ! empty( $left_later['ok'] ), 'an abandoned pay link goes stale after 15 minutes and the saved card is charged' );

$intent_statuses = array( 'succeeded', 'processing', 'requires_capture', 'requires_payment_method', 'canceled', 'requires_action' );
foreach ( $intent_statuses as $intent_status ) {
	foreach ( array( 0, 900 ) as $intent_age ) {
		$GLOBALS['creature_demo_lock_now'] = 1700000000;
		$intent_loan = creature_demo_loan();
		Creature_Demo_Loan::record_return_scan( $intent_loan, '2026-03-30', '2026-03-30' );
		$intent_charge = wc_get_order( (int) $intent_loan->get_meta( '_creature_demo_late_fee_order_id' ) );
		$intent_charge->update_meta_data( '_stripe_intent_id', 'pi_test_lookup' );
		$intent_charge->update_meta_data( '_creature_demo_charge_lock', 'pay_link' );
		$intent_charge->update_meta_data( '_creature_demo_charge_lock_at', 1700000000 - $intent_age );
		$intent_charge->save();
		WC_Stripe_API::$response = (object) array(
			'id'     => 'pi_test_lookup',
			'status' => $intent_status,
		);
		$intent_from = count( WC_Stripe_API::$requests );
		$intent_result = Creature_Demo_Loan::charge_saved_card( $intent_loan, '2026-04-02' );
		$intent_posts = 0;
		foreach ( array_slice( WC_Stripe_API::$requests, $intent_from ) as $intent_request ) {
			if ( 'POST' === $intent_request['method'] && 'payment_intents' === $intent_request['endpoint'] ) {
				$intent_posts++;
			}
		}
		$hard = in_array( $intent_status, array( 'succeeded', 'processing', 'requires_capture' ), true );
		if ( $hard || 0 === $intent_age ) {
			creature_demo_expect( empty( $intent_result['ok'] ), 'intent ' . $intent_status . ' at age ' . $intent_age . ' refuses the saved card' );
			creature_demo_expect( 0 === $intent_posts, 'intent ' . $intent_status . ' at age ' . $intent_age . ' does not create a PaymentIntent' );
			if ( 'succeeded' === $intent_status ) {
				creature_demo_expect( 'already_paid' === $intent_result['code'], 'a succeeded pay-link intent always refuses, including after the stale window' );
			}
		} else {
			creature_demo_expect( 'payment_in_progress' !== $intent_result['code'] && 'already_paid' !== $intent_result['code'], 'intent ' . $intent_status . ' older than 15 minutes is not in progress' );
			creature_demo_expect( $intent_posts > 0, 'intent ' . $intent_status . ' older than 15 minutes lets the saved card call Stripe' );
		}
	}
}

foreach ( array( 0, 900 ) as $fail_age ) {
	$GLOBALS['creature_demo_lock_now'] = 1700000000;
	$fail_loan = creature_demo_loan();
	Creature_Demo_Loan::record_return_scan( $fail_loan, '2026-03-30', '2026-03-30' );
	$fail_charge = wc_get_order( (int) $fail_loan->get_meta( '_creature_demo_late_fee_order_id' ) );
	$fail_charge->update_meta_data( '_stripe_intent_id', 'pi_test_lookup_fail' );
	$fail_charge->update_meta_data( '_creature_demo_charge_lock', 'pay_link' );
	$fail_charge->update_meta_data( '_creature_demo_charge_lock_at', 1700000000 - $fail_age );
	$fail_charge->save();
	WC_Stripe_API::$response = new WP_Error( 'stripe_error', 'timeout' );
	$fail_from = count( WC_Stripe_API::$requests );
	$fail_result = Creature_Demo_Loan::charge_saved_card( $fail_loan, '2026-04-02' );
	$fail_posts = 0;
	$fail_gets = 0;
	foreach ( array_slice( WC_Stripe_API::$requests, $fail_from ) as $fail_request ) {
		if ( 'POST' === $fail_request['method'] && 'payment_intents' === $fail_request['endpoint'] ) {
			$fail_posts++;
		}
		if ( 'GET' === $fail_request['method'] && 0 === strpos( $fail_request['endpoint'], 'payment_intents/' ) ) {
			$fail_gets++;
		}
	}
	$fail_notes = implode( "\n", $fail_charge->notes );
	creature_demo_expect( $fail_gets > 0, 'a missing intent status is looked up at age ' . $fail_age );
	if ( 0 === $fail_age ) {
		creature_demo_expect( 'payment_in_progress' === $fail_result['code'], 'a failed intent lookup refuses while the lock is younger than 15 minutes' );
		creature_demo_expect( 0 === $fail_posts, 'a failed intent lookup does not charge while the lock is fresh' );
		creature_demo_expect( false === strpos( $fail_notes, 'did not return the pay-link PaymentIntent status' ), 'a fresh failed lookup does not allow the charge' );
	} else {
		creature_demo_expect( 'payment_in_progress' !== $fail_result['code'], 'a failed intent lookup allows the charge after 15 minutes' );
		creature_demo_expect( $fail_posts > 0, 'a failed intent lookup after 15 minutes calls Stripe' );
		creature_demo_expect( false !== strpos( $fail_notes, 'did not return the pay-link PaymentIntent status' ), 'a failed intent lookup after 15 minutes is noted on the order' );
	}
}
$GLOBALS['creature_demo_lock_now'] = null;
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);

creature_demo_expect( 'creature_demo_loan_deactivate' === $GLOBALS['creature_demo_deactivation'][1], 'deactivation is registered' );
creature_demo_loan_deactivate();
creature_demo_expect( empty( $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] ), 'deactivation unschedules the daily job' );

$blob = creature_demo_sensitive_blob();
creature_demo_absent( $blob, 'sk_live_', 'notes, mail, and Stripe bodies do not contain a live secret key' );
creature_demo_absent( $blob, 'sk_test_', 'notes, mail, and Stripe bodies do not contain a test secret key' );
creature_demo_absent( $blob, $pan, 'notes, mail, and Stripe bodies do not contain the card number' );
creature_demo_absent( $blob, 'placeholder', 'notes, mail, and Stripe bodies do not contain the placeholder key body' );

$passed = $checks - $failures;
echo "{$passed} passed, {$failures} failed\n";
if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failed, {$checks} checks\n" );
	exit( 1 );
}

echo "{$checks} checks passed\n";
exit( 0 );
