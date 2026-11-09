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
$GLOBALS['creature_demo_option_adds']   = array();
$GLOBALS['creature_demo_add_option_reject'] = 0;
$GLOBALS['creature_demo_add_option_overwrite'] = false;
$GLOBALS['creature_demo_option_raw']    = array();
$GLOBALS['creature_demo_cache']         = array();
$GLOBALS['creature_demo_cache_deletes'] = array();
$GLOBALS['creature_demo_insert_ignore_reject'] = 0;
$GLOBALS['creature_demo_before_lock_delete'] = null;
$GLOBALS['creature_demo_postmeta']      = array();
$GLOBALS['creature_demo_post_types']    = array();
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
$GLOBALS['creature_demo_wp_mail']       = array();
$GLOBALS['creature_demo_can']           = false;
$GLOBALS['creature_demo_attached']      = array();
$GLOBALS['creature_demo_uploads']       = '';
$GLOBALS['creature_demo_conf_files']    = array();
$GLOBALS['creature_demo_hook_args']     = array();

/**
 * Older tests were written while three slots were empty. Cadence v3 fills them, so the baseline
 * override puts them back to empty; the v3 wording has its own tests (search "v3 copy").
 */
function creature_demo_empty_copy_baseline() {
	$empty = array(
		'subject' => '',
		'body'    => '',
	);
	return array(
		'express_request_checkbox' => $empty,
		'crash_hold_notice'        => $empty,
		'deduction_notice_deemed'  => $empty,
		// v3 has no wording for this one. Tests that decide a deduction dispute need a body, so the
		// baseline gives a test body; the E3 tests set it empty to test the block.
		'dispute_decision_deemed'  => array(
			'subject' => 'Test deduction decision for {order_id}',
			'body'    => 'Test deduction decision: {decision}. Amount: {amount}.',
		),
	);
}
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();

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
		return ! empty( $GLOBALS['creature_demo_can'] );
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) {
		return isset( $GLOBALS['creature_demo_attached'][ (int) $id ] ) ? $GLOBALS['creature_demo_attached'][ (int) $id ] : false;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create = true ) {
		unset( $time, $create );
		return array(
			'basedir' => isset( $GLOBALS['creature_demo_uploads'] ) ? $GLOBALS['creature_demo_uploads'] : '',
			'error'   => false,
		);
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

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['creature_demo_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		if ( is_string( $data ) ) {
			return $data;
		}
		return (string) $data;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}
		$unserialized = @unserialize( $data );
		if ( false === $unserialized && 'b:0;' !== $data ) {
			return $data;
		}
		return $unserialized;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		$group = (string) $group;
		$key   = (string) $key;
		if ( ! isset( $GLOBALS['creature_demo_cache'][ $group ] ) || ! array_key_exists( $key, $GLOBALS['creature_demo_cache'][ $group ] ) ) {
			return false;
		}
		return $GLOBALS['creature_demo_cache'][ $group ][ $key ];
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '' ) {
		$GLOBALS['creature_demo_cache'][ (string) $group ][ (string) $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['creature_demo_cache_deletes'][] = array( (string) $key, (string) $group );
		unset( $GLOBALS['creature_demo_cache'][ (string) $group ][ (string) $key ] );
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		$GLOBALS['creature_demo_option_adds'][] = array(
			'option'     => (string) $option,
			'deprecated' => (string) $deprecated,
			'autoload'   => (string) $autoload,
		);
		// Core add_option() does this when the name is cached in notoptions:
		// INSERT ... ON DUPLICATE KEY UPDATE, which returns true and overwrites.
		if ( ! empty( $GLOBALS['creature_demo_add_option_overwrite'] ) ) {
			$GLOBALS['creature_demo_options'][ $option ]    = $value;
			$GLOBALS['creature_demo_option_raw'][ $option ] = maybe_serialize( $value );
			return true;
		}
		if ( array_key_exists( $option, $GLOBALS['creature_demo_options'] ) ) {
			return false;
		}
		if ( ! empty( $GLOBALS['creature_demo_add_option_reject'] ) ) {
			$GLOBALS['creature_demo_add_option_reject']--;
			return false;
		}
		$GLOBALS['creature_demo_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		if ( ! array_key_exists( $option, $GLOBALS['creature_demo_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['creature_demo_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) $post;
		return isset( $GLOBALS['creature_demo_post_types'][ $id ] ) ? $GLOBALS['creature_demo_post_types'][ $id ] : '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value, $prev = '' ) {
		unset( $value, $prev );
		$GLOBALS['creature_demo_postmeta'][] = array( 'update', (int) $id, (string) $key );
		return true;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $id, $key, $value, $unique = false ) {
		unset( $value, $unique );
		$GLOBALS['creature_demo_postmeta'][] = array( 'add', (int) $id, (string) $key );
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $id, $key, $value = '' ) {
		unset( $value );
		$GLOBALS['creature_demo_postmeta'][] = array( 'delete', (int) $id, (string) $key );
		return true;
	}
}

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '' ) {
		$GLOBALS['creature_demo_mail_calls']++;
		$GLOBALS['creature_demo_wp_mail'][] = array( $to, $subject, $message, $headers );
		return true;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ) {
		return (int) $id > 0 ? 'https://example.com/wp-content/uploads/' . (int) $id . '.jpg' : false;
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
		return empty( $GLOBALS['creature_demo_scheduled'][ $hook ] ) ? false : $GLOBALS['creature_demo_scheduled'][ $hook ][0];
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( isset( $GLOBALS['creature_demo_tz'] ) ? $GLOBALS['creature_demo_tz'] : 'Europe/London' );
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

if ( ! function_exists( 'register_uninstall_hook' ) ) {
	function register_uninstall_hook( $file, $callback ) {
		$GLOBALS['creature_demo_uninstall'] = array( (string) $file, $callback );
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
				'via'      => 'request',
			);
			return self::$response;
		}

		public static function retrieve( $endpoint ) {
			self::$requests[] = array(
				'body'     => array(),
				'endpoint' => (string) $endpoint,
				'method'   => 'GET',
				'headers'  => array(),
				'via'      => 'retrieve',
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
	public $refunded = 0.0;

	public function __construct( $id ) {
		$this->id = (int) $id;
	}
	public function get_total_refunded() {
		return (float) $this->refunded;
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
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
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

class Creature_Demo_Test_Priced_Product extends Creature_Demo_Test_Product {
	public $price;

	public function __construct( $id, $price ) {
		parent::__construct( $id );
		$this->price = $price;
	}
	public function get_price() {
		return $this->price;
	}
	public function get_regular_price() {
		throw new Exception( 'the keep price must use get_price(), not the regular price' );
	}
}

/**
 * Product prices by id, as the string get_price() returns. Missing id means no
 * product.
 */
$GLOBALS['creature_demo_product_prices'] = array();

function wc_get_product( $id ) {
	$id = (int) $id;
	if ( ! isset( $GLOBALS['creature_demo_product_prices'][ $id ] ) ) {
		return false;
	}
	return new Creature_Demo_Test_Priced_Product( $id, $GLOBALS['creature_demo_product_prices'][ $id ] );
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

function creature_demo_filter_consent_version( $version ) {
	if ( isset( $GLOBALS['creature_demo_consent_version'] ) && is_string( $GLOBALS['creature_demo_consent_version'] ) && '' !== $GLOBALS['creature_demo_consent_version'] ) {
		return $GLOBALS['creature_demo_consent_version'];
	}
	return $version;
}

function creature_demo_filter_lapse( $days ) {
	if ( null !== $GLOBALS['creature_demo_lapse_days'] ) {
		return $GLOBALS['creature_demo_lapse_days'];
	}
	return $days;
}

function creature_demo_lock_row( $order ) {
	$id   = is_object( $order ) ? (int) $order->get_id() : (int) $order;
	$name = 'creature_demo_charge_lock_' . $id;
	return array_key_exists( $name, $GLOBALS['creature_demo_options'] ) ? $GLOBALS['creature_demo_options'][ $name ] : false;
}

function creature_demo_lock_owner( $order ) {
	$row = creature_demo_lock_row( $order );
	return is_array( $row ) && isset( $row['owner'] ) ? (string) $row['owner'] : '';
}

function creature_demo_plant_lock( $order, $owner, $at, $token = 'planted-token' ) {
	$id      = is_object( $order ) ? (int) $order->get_id() : (int) $order;
	$payload = array(
		'owner' => (string) $owner,
		'token' => (string) $token,
	);
	if ( null !== $at ) {
		$payload['at'] = (int) $at;
	}
	$name = 'creature_demo_charge_lock_' . $id;
	$GLOBALS['creature_demo_options'][ $name ]    = $payload;
	$GLOBALS['creature_demo_option_raw'][ $name ] = maybe_serialize( $payload );
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

class Creature_Demo_Test_Wpdb {
	public $options       = 'wp_options';
	public $rows_affected = 0;
	public $inserts       = array();

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		return array(
			'sql'  => (string) $query,
			'args' => $args,
		);
	}

	public function get_var( $prepared ) {
		$args = is_array( $prepared ) && isset( $prepared['args'] ) ? $prepared['args'] : array();
		$name = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $name || ! array_key_exists( $name, $GLOBALS['creature_demo_options'] ) ) {
			return null;
		}
		if ( ! isset( $GLOBALS['creature_demo_option_raw'][ $name ] ) ) {
			$GLOBALS['creature_demo_option_raw'][ $name ] = maybe_serialize( $GLOBALS['creature_demo_options'][ $name ] );
		}
		return $GLOBALS['creature_demo_option_raw'][ $name ];
	}

	public function query( $prepared ) {
		$sql  = is_array( $prepared ) && isset( $prepared['sql'] ) ? $prepared['sql'] : (string) $prepared;
		$args = is_array( $prepared ) && isset( $prepared['args'] ) ? $prepared['args'] : array();
		if ( false !== strpos( $sql, 'INSERT IGNORE' ) ) {
			$name     = isset( $args[0] ) ? (string) $args[0] : '';
			$raw      = isset( $args[1] ) ? (string) $args[1] : '';
			$autoload = isset( $args[2] ) ? (string) $args[2] : '';
			$this->inserts[] = array(
				'name'     => $name,
				'autoload' => $autoload,
				'sql'      => $sql,
				'inserted' => false,
			);
			if ( array_key_exists( $name, $GLOBALS['creature_demo_options'] ) ) {
				$this->rows_affected = 0;
				return 0;
			}
			if ( ! empty( $GLOBALS['creature_demo_insert_ignore_reject'] ) ) {
				$GLOBALS['creature_demo_insert_ignore_reject']--;
				$this->rows_affected = 0;
				return 0;
			}
			$GLOBALS['creature_demo_options'][ $name ]    = maybe_unserialize( $raw );
			$GLOBALS['creature_demo_option_raw'][ $name ] = $raw;
			$this->rows_affected = 1;
			$this->inserts[ count( $this->inserts ) - 1 ]['inserted'] = true;
			return 1;
		}
		if ( false !== strpos( $sql, 'DELETE' ) && false !== strpos( $sql, 'option_value' ) ) {
			if ( isset( $GLOBALS['creature_demo_before_lock_delete'] ) && is_callable( $GLOBALS['creature_demo_before_lock_delete'] ) ) {
				$hook = $GLOBALS['creature_demo_before_lock_delete'];
				$GLOBALS['creature_demo_before_lock_delete'] = null;
				$hook();
			}
			$name = isset( $args[0] ) ? (string) $args[0] : '';
			$raw  = isset( $args[1] ) ? (string) $args[1] : '';
			$current = isset( $GLOBALS['creature_demo_option_raw'][ $name ] ) ? (string) $GLOBALS['creature_demo_option_raw'][ $name ] : '';
			if ( '' !== $current && '' !== $raw && hash_equals( $current, $raw ) ) {
				unset( $GLOBALS['creature_demo_options'][ $name ], $GLOBALS['creature_demo_option_raw'][ $name ] );
				$this->rows_affected = 1;
				return 1;
			}
			$this->rows_affected = 0;
			return 0;
		}
		if ( false !== strpos( $sql, 'DELETE' ) && false !== strpos( $sql, 'LIKE' ) ) {
			$removed = 0;
			foreach ( array_keys( $GLOBALS['creature_demo_options'] ) as $key ) {
				if ( 0 === strpos( (string) $key, 'creature_demo_charge_lock_' ) ) {
					unset( $GLOBALS['creature_demo_options'][ $key ], $GLOBALS['creature_demo_option_raw'][ $key ] );
					$removed++;
				}
			}
			$this->rows_affected = $removed;
			return $removed;
		}
		if ( false !== strpos( $sql, 'DELETE' ) ) {
			$name = isset( $args[0] ) ? (string) $args[0] : '';
			if ( '' !== $name && array_key_exists( $name, $GLOBALS['creature_demo_options'] ) ) {
				unset( $GLOBALS['creature_demo_options'][ $name ], $GLOBALS['creature_demo_option_raw'][ $name ] );
				$this->rows_affected = 1;
				return 1;
			}
			$this->rows_affected = 0;
			return 0;
		}
		$this->rows_affected = 0;
		return 0;
	}
}

global $wpdb;
$wpdb = new Creature_Demo_Test_Wpdb();

require dirname( __DIR__, 2 ) . '/creature-demo-loan/creature-demo-loan.php';
// A test can replace a copy slot through $GLOBALS['creature_demo_copy_override'].
add_filter(
	'creature_demo_loan_copy_catalogue',
	static function ( $catalogue ) {
		foreach ( $GLOBALS['creature_demo_copy_override'] as $slot => $row ) {
			$catalogue[ $slot ] = $row;
		}
		return $catalogue;
	}
);

add_filter( 'creature_demo_loan_product_ids', 'creature_demo_filter_products' );
add_filter( 'creature_demo_loan_cart_items', 'creature_demo_filter_cart' );
add_filter( 'creature_demo_loan_refund_includes_keep_choosers', 'creature_demo_filter_refund_flag' );
add_filter( 'creature_demo_loan_buy_return_switch_until', 'creature_demo_filter_switch_until' );
add_filter( 'creature_demo_loan_keep_lapse_days', 'creature_demo_filter_lapse' );
add_filter( 'creature_demo_loan_consent_version', 'creature_demo_filter_consent_version' );
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

/** A paid deemed purchase (£212 less £20 of late fees paid), with the kit scanned and inspected after it. */
function creature_demo_paid_deemed_returned( $scan = '2026-04-08' ) {
	$loan = creature_demo_loan();
	$loan->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
	$raised = Creature_Demo_Loan::maybe_raise_deemed( $loan, '2026-04-07' );
	wc_get_order( $raised['order_id'] )->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_item4_' . $loan->get_id() );
	Creature_Demo_Loan::on_payment_complete( $raised['order_id'] );
	$loan->update_meta_data( '_creature_demo_deemed_paid_at', '2026-04-07' );
	Creature_Demo_Loan::record_return_scan( $loan, $scan, $scan );
	// The shop enters the Kit received date (terms 6.4(b)): the scan never starts the clock.
	Creature_Demo_Loan::record_kit_received( $loan, $scan );
	creature_demo_inspect( $loan, $scan );
	return $loan;
}

/** Slot of the most recent charge_notice_* email. */
function creature_demo_last_charge_slot() {
	$found = '';
	foreach ( Creature_Demo_Loan_Mail::$sent as $row ) {
		if ( 0 === strpos( $row['slot'], 'charge_notice_' ) || 'deduction_notice_deemed' === $row['slot'] ) {
			$found = $row['slot'];
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
	// A damage or missing-parts line needs findings and a photo (terms 6.4(b)).
	// Tests of the refusal clear these on purpose.
	$order->update_meta_data( '_creature_demo_findings', 'Test findings: dented clamp face.' );
	$order->update_meta_data( '_creature_demo_photos', array( 'https://example.com/uploads/demo-clamp-1.jpg' ) );
	return $order;
}

/**
 * The late fee is no longer charged at the scan. It goes on the one bill when
 * inspection is recorded. Complete the inspection (once) and return the bill's
 * charge order.
 */
function creature_demo_bill_charge( $loan, $as_of = '2026-03-30' ) {
	creature_demo_inspect( $loan, $as_of );
	return wc_get_order( (int) $loan->get_meta( '_creature_demo_bill_order_id' ) );
}

/**
 * complete_inspection() as the shop would use it. When a damage or missing-parts charge is entered,
 * the Kit received date is entered first (terms 6.4(b)): the bill is refused without it. A clean
 * inspection needs no date and is passed straight through.
 */
function creature_demo_inspect( $loan, $as_of = null ) {
	$asked = (int) $loan->get_meta( '_creature_demo_damage_pence' ) + (int) $loan->get_meta( '_creature_demo_missing_pence' );
	if ( $asked > 0 && '' === (string) $loan->get_meta( '_creature_demo_received_date' ) ) {
		Creature_Demo_Loan::record_kit_received( $loan, null === $as_of ? '2026-03-30' : $as_of );
	}
	return Creature_Demo_Loan::complete_inspection( $loan, $as_of );
}

/**
 * raise_damage_charge() as the shop would use it: the Kit received date is entered first
 * (terms 6.4(b)), because a damage or missing-parts line is refused without it.
 */
function creature_demo_damage( $loan, $pence, $label, $as_of = null ) {
	if ( '' === (string) $loan->get_meta( '_creature_demo_received_date' ) ) {
		Creature_Demo_Loan::record_kit_received( $loan, null === $as_of ? '2026-03-20' : $as_of );
	}
	return Creature_Demo_Loan::raise_damage_charge( $loan, $pence, $label, $as_of );
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
creature_demo_expect( 300 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 6 ), 'day 6 is £3' );
creature_demo_expect( 2400 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 13 ), 'day 13 reaches the £24 cap' );
creature_demo_expect( 2400 === Creature_Demo_Loan_Money::late_fee_pence_for_offset( 20 ), 'later days stay at the cap' );
creature_demo_expect(
	0 === Creature_Demo_Loan_Money::late_fee_pence_for_dates( '2026-03-24', '2026-03-29' ),
	'grace-end scan is £0'
);
creature_demo_expect(
	300 === Creature_Demo_Loan_Money::late_fee_pence_for_dates( '2026-03-24', '2026-03-30' ),
	'day-6 scan is £3'
);
creature_demo_expect(
	2400 === Creature_Demo_Loan_Money::late_fee_pence_for_dates( '2026-03-24', '2026-04-06' ),
	'day-13 scan is £24'
);
creature_demo_expect( 0 === Creature_Demo_Loan_Calendar::working_days_since( '2026-03-06', '2026-03-08', $holidays ), 'a weekend adds no working days' );
creature_demo_expect( 1 === Creature_Demo_Loan_Calendar::working_days_since( '2026-03-06', '2026-03-09', $holidays ), 'Monday after a Friday notice is 1 working day' );
creature_demo_expect( 3 === Creature_Demo_Loan_Calendar::working_days_since( '2026-03-06', '2026-03-11', $holidays ), 'Wednesday after a Friday notice is 3 working days' );
creature_demo_expect( 0 === Creature_Demo_Loan_Calendar::working_days_since( '2026-05-01', '2026-05-04', $holidays ), 'the May bank holiday adds nothing' );
creature_demo_expect( 1 === Creature_Demo_Loan_Calendar::working_days_since( '2026-05-01', '2026-05-05', $holidays ), 'Tuesday after the May bank holiday is 1 working day' );
creature_demo_expect( 3 === Creature_Demo_Loan_Calendar::working_days_since( '2026-05-01', '2026-05-07', $holidays ), 'Thursday after the May bank holiday is 3 working days' );
creature_demo_expect( 1 === Creature_Demo_Loan_Calendar::working_days_since( '2026-04-02', '2026-04-07', $holidays ), 'Easter weekend and Easter Monday add nothing' );
creature_demo_expect( 3 === Creature_Demo_Loan_Calendar::working_days_since( '2026-04-02', '2026-04-09', $holidays ), 'three working days after Maundy Thursday land on the next Thursday' );

creature_demo_expect( 19200 === Creature_Demo_Loan_Money::keep_amount_pence( 21200, 2000 ), 'paid late fees come off £212 and unpaid fees are not added' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::keep_amount_pence( 21200, 0 ), 'unpaid late fees are not added to the keep price' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::keep_amount_pence( 21200, 0 ), 'a keep with no fees is £212' );
creature_demo_expect( 19200 === Creature_Demo_Loan_Money::deemed_amount_pence( 21200, 2000 ), 'deemed purchase nets off late fees already paid' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::deemed_amount_pence( 21200, 0 ), 'deemed purchase with no late fees is £212' );
creature_demo_expect( 18800 === Creature_Demo_Loan_Money::deemed_amount_pence( 21200, 2400 ), 'a full late-fee cap comes off the deemed price' );
creature_demo_expect( 17300 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 21200, 19200, 2000, 1500 ), 'post-deemed refund keeps the rest of the £24 cap and the damage' );
creature_demo_expect( 18800 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 21200, 21200, 0, 0 ), 'refund of £212 with no fees paid retains £24' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 21200, 21200, 2400, 0 ), 'late fees already at the cap are not retained twice' );
creature_demo_expect( 0 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 21200, 21200, 0, 20000 ), 'late fees plus damage above £212 floor the refund at 0' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::keep_price_pence( 24900 ), 'a £249 bundle gives a £212 keep price' );
creature_demo_expect( 19200 === Creature_Demo_Loan_Money::keep_price_pence( 22900 ), 'a £229 bundle gives a £192 keep price' );
creature_demo_expect( 16200 === Creature_Demo_Loan_Money::keep_price_pence( 19900 ), 'a £199 bundle gives a £162 keep price' );
creature_demo_expect( 21200 === Creature_Demo_Loan_Money::keep_price_pence( 29900 ), 'a bundle above £249 is held at the £212 ceiling' );
creature_demo_expect( 0 === Creature_Demo_Loan_Money::keep_price_pence( 3000 ), 'a bundle below the fee and used-stock deduction floors at 0' );
creature_demo_expect( 0 === Creature_Demo_Loan_Money::keep_price_pence( 0 ), 'a zero bundle price floors at 0' );
creature_demo_expect( 14200 === Creature_Demo_Loan_Money::deemed_amount_pence( 16200, 2000 ), 'deemed purchase on a £162 loan nets off late fees' );
creature_demo_expect( 16200 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 16200, 16200, 2400, 0 ), 'a £162 loan with late fees at the cap retains nothing more' );
creature_demo_expect( 0 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 16200, 16200, 0, 20000 ), 'damage above the £162 keep price retains at most £162' );
creature_demo_expect( 4000 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 16200, 16200, 0, 9800 ), 'retention on a £162 loan is the rest of the late-fee cap plus damage, up to £162' );
creature_demo_expect( 100 === Creature_Demo_Loan_Money::post_deemed_refund_pence( 16200, 16200, 0, 13700 ), 'retention just under the £162 ceiling leaves the remainder' );
creature_demo_expect( true === Creature_Demo_Loan_Money::refund_applies( true, true ), 'refund flag on includes a rider who chose keep' );
creature_demo_expect( false === Creature_Demo_Loan_Money::refund_applies( true, false ), 'refund flag off skips a rider who chose keep' );
creature_demo_expect( true === Creature_Demo_Loan_Money::refund_applies( false, false ), 'refund flag off still covers a rider who never chose keep' );
creature_demo_expect( true === Creature_Demo_Loan::refund_includes_keep_choosers(), 'refund flag defaults on' );
creature_demo_expect( false === method_exists( 'Creature_Demo_Loan', 'late_fees_included_in_keep' ), 'the keep-fee flag is closed' );
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
	'service_area_error',
	'my_account_heading',
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
	'choice_return_recorded',
	'coupon_issued',
	'keep_confirm',
	'choice_recorded',
	'keep_choice_lapsed',
	'dispute_decision',
	'admin_dispute_nearing',
	'admin_return_booked',
	'admin_bill_due',
	'admin_received_date_missing',
	'express_request_checkbox',
	'admin_attachments_missing',
	'crash_hold_notice',
	'deduction_notice_deemed',
	'admin_deduction_notice_missing',
	'admin_deduction_settle_due',
	'dispute_decision_deemed',
	'admin_dispute_decision_missing',
	'admin_daily_job_late',
	'my_account_label_due',
	'my_account_label_grace_end',
	'my_account_label_deemed_on',
	'my_account_label_keep_price',
	'my_account_no_loans',
);
// The three emails Cadence has not written yet are slots with no wording.
$expected_slots = array_merge( $expected_slots, array() );
$new_slots      = array( 'charge_declined', 'dates_on_delivery', 'booking_confirmation' );
creature_demo_expect( array() === array_diff( $new_slots, $slots ), 'the three missing emails are slots' );
creature_demo_expect( array() === array_diff( $slots, array_merge( $expected_slots, $new_slots ) ) && array() === array_diff( $expected_slots, $slots ), 'copy slots match the catalogue' );
// v3 copy: look at the real catalogue, without the test baseline that empties three slots.
$baseline_override                      = $GLOBALS['creature_demo_copy_override'];
$GLOBALS['creature_demo_copy_override'] = array();
$empty_slots                            = array();
foreach ( $slots as $slot ) {
	$subject = Creature_Demo_Loan_Copy::subject( $slot );
	$body    = Creature_Demo_Loan_Copy::text( $slot );
	$joined  = $subject . $body;
	creature_demo_absent( $joined, '[COPY: Cadence]', 'slot ' . $slot . ' has no Cadence marker' );
	creature_demo_absent( $joined, '[NEEDS', 'slot ' . $slot . ' has no needs-input marker' );
	if ( '' === trim( $joined ) ) {
		$empty_slots[] = $slot;
	}
}
sort( $empty_slots );
creature_demo_expect( array( 'dispute_decision_deemed', 'my_account_label_deemed_on', 'my_account_label_due', 'my_account_label_grace_end', 'my_account_label_keep_price', 'my_account_no_loans' ) === $empty_slots, 'v3 copy: every slot has wording except dispute_decision_deemed and the five Demo loan tab labels, which v3 does not give (v3 fills the checkbox label, the crash notice and the deemed deduction notice)' . ( $empty_slots ? ': ' . implode( ', ', $empty_slots ) : '' ) );
$v3_express = Creature_Demo_Loan_Copy::text( 'express_request_checkbox' );
creature_demo_expect( 'Send my kit now. I understand that if I cancel within the 14-day cancellation period after the kit has been sent, I’ll need to send it back, and you’ll keep part of the £25 for the days I’ve had it.' === $v3_express, 'v3 copy: the express checkbox label is the Appendix B text, curly apostrophes' );
creature_demo_absent( $v3_express, "'", 'v3 copy: no straight apostrophe in the checkbox label' );
$v3_crash = Creature_Demo_Loan_Copy::text( 'crash_hold_notice' );
creature_demo_expect( 0 === strpos( $v3_crash, 'You’ve told us about a crash, impact or failure' ) && false !== strpos( $v3_crash, 'under Demo loan' ) && false === strpos( $v3_crash, '→' ), 'v3 copy: crash notice, no arrow path' );
$v3_ded = Creature_Demo_Loan_Copy::text( 'deduction_notice_deemed' );
$v3_cat = Creature_Demo_Loan_Copy::catalogue();
$v3_ded = $v3_cat['deduction_notice_deemed']['body'];
creature_demo_expect( 'De-Reacher demo: {amount} to come off your refund (order {order_id})' === $v3_cat['deduction_notice_deemed']['subject'], 'v3 copy: deduction notice subject' );
creature_demo_expect( false === strpos( $v3_ded, '{link}' ) && false === stripos( $v3_ded, 'payment link' ) && false !== strpos( $v3_ded, 'we won’t charge your saved card' ) && false !== strpos( $v3_ded, "\n\nWhat we found:\n{findings}\nOur photos:\n{photos}\n\n" ), 'v3 copy: deduction notice has no {link}, keeps its line breaks, and says nothing is charged to the saved card' );
creature_demo_expect( false !== strpos( Creature_Demo_Loan_Copy::text( 'admin_received_date_missing' ), 'Use the day the parcel arrived, not the scan date.' ) && false !== strpos( Creature_Demo_Loan_Copy::text( 'admin_received_date_missing' ), 'The 5 working days in 6.4(b) also apply to any deduction from a deemed purchase refund.' ), 'v3 copy: admin_received_date_missing has the v3 sentences' );
$v3_bill = $v3_cat['admin_bill_due']['body'];
creature_demo_expect( false !== strpos( $v3_bill, 'before 10pm on {deadline}' ) && false !== strpos( $v3_bill, 'At 10pm that day the plugin sends the late-fee-only bill on its own (if a late fee is due)' ) && false !== strpos( $v3_bill, 'paid deemed purchase' ), 'v3 copy: admin_bill_due is the v3 body with {deadline}' );
foreach ( array( 'reminder_grace_end', 'reminder_deemed_warning', 'keep_choice_lapsed', 'booking_confirmation' ) as $v3_slot ) {
	$v3_text = Creature_Demo_Loan_Copy::text( $v3_slot );
	creature_demo_expect( false !== strpos( $v3_text, 'on this order’s page in My account, under Demo loan' ) && false === strpos( $v3_text, 'My account →' ), 'v3 copy: ' . $v3_slot . ' uses the order-page wording' );
}
creature_demo_absent( Creature_Demo_Loan_Copy::text( 'dates_on_delivery' ), 'My account', 'v3 copy: dates_on_delivery no longer says the dates are in My account' );
$GLOBALS['creature_demo_copy_override'] = $baseline_override;
$v2_cat = Creature_Demo_Loan_Copy::catalogue();
$v2_has = static function ( $slot, $needle ) use ( $v2_cat ) {
	return false !== strpos( $v2_cat[ $slot ]['body'], $needle );
};
creature_demo_expect( 0 === strpos( $v2_cat['dates_on_delivery']['body'], 'Your De-Reacher demo kit (order {order_id}) has been delivered.' ) && 'Your De-Reacher demo dates: due back {due}' === $v2_cat['dates_on_delivery']['subject'], 'dates_on_delivery is Cadence v2' );
creature_demo_expect( 'Your card was declined: De-Reacher demo charge ({amount})' === $v2_cat['charge_declined']['subject'] && $v2_has( 'charge_declined', 'The amount is still owed. Please pay it using the link.' ), 'charge_declined is Cadence v2' );
creature_demo_expect( 'De-Reacher demo bill: {amount} to pay (order {order_id})' === $v2_cat['charge_notice_damage']['subject'] && $v2_has( 'charge_notice_damage', 'This is the only bill for this loan.' ) && $v2_has( 'charge_notice_damage', 'A missing inverted mount kit is £35.00 rather than' ), 'charge_notice_damage is the Cadence v2 combined bill, with the mount-kit price filled in' );
creature_demo_expect( 'Demo kit back: inspect and bill by {deadline} (order {order_id})' === $v2_cat['admin_bill_due']['subject'] && $v2_has( 'admin_bill_due', 'Bank holidays aren’t counted' ), 'admin_bill_due is Cadence v2' );
creature_demo_expect( '' === $v2_cat['booking_confirmation']['subject'] && $v2_has( 'booking_confirmation', 'Your keep price: {price}' ) && ! $v2_has( 'booking_confirmation', '[NEEDS' ), 'booking_confirmation is body only, with {price} where v2 had the note' );
creature_demo_expect( '' === Creature_Demo_Loan_Copy::text( 'no_such_slot' ) && '' === Creature_Demo_Loan_Copy::subject( 'no_such_slot' ), 'an unknown slot returns an empty string, never a marker' );
$marker_hits = array();
$tree        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/creature-demo-loan', FilesystemIterator::SKIP_DOTS ) );
foreach ( $tree as $file ) {
	if ( $file->isFile() && false !== strpos( (string) file_get_contents( $file->getPathname() ), '[COPY: Cadence]' ) ) {
		$marker_hits[] = $file->getFilename();
	}
}
creature_demo_expect( array() === $marker_hits, 'no [COPY: Cadence] marker is left anywhere in the plugin folder' . ( $marker_hits ? ': ' . implode( ', ', $marker_hits ) : '' ) );
$fixture = file_get_contents( __DIR__ . '/fixtures/appendix-a-consent.txt' );
creature_demo_expect( is_string( $fixture ) && '' !== $fixture, 'the Appendix A fixture is there' );
creature_demo_expect( $fixture === Creature_Demo_Loan_Copy::consent_wording( 'version-5' ), 'the version-5 consent wording is the Appendix A fixture, byte for byte' );
creature_demo_expect( $fixture === Creature_Demo_Loan_Copy::text( 'consent_checkbox' ), 'the checkout checkbox shows the Appendix A wording, byte for byte' );
creature_demo_expect( 3 === substr_count( $fixture, '’' ), 'the Appendix A wording has its three curly apostrophes' );
creature_demo_absent( $fixture, "'", 'the Appendix A wording has no straight apostrophe' );
$consent = Creature_Demo_Loan_Copy::text( 'consent_checkbox' );
creature_demo_expect( false !== strpos( $consent, '£24' ), 'consent names the £24 late-fee maximum' );
creature_demo_expect( false !== strpos( $consent, '£212' ), 'consent names the £212 deemed-purchase maximum' );
creature_demo_expect( false !== strpos( $consent, '£35' ), 'consent names the £35 inverted mount kit' );
creature_demo_expect( false !== strpos( $consent, 'never be more than £212' ), 'consent caps late fees, the deemed price and damage together at £212' );
creature_demo_expect( false !== strpos( $consent, '7 days to pay by link or dispute' ), 'consent gives 7 days to pay by link or dispute' );
creature_demo_expect( false !== strpos( $consent, 'damage or missing parts' ), 'consent covers damage as well as missing parts' );
creature_demo_expect( false !== strpos( $consent, 'damage or missing parts at published spare-parts prices' ), 'consent prices damage and missing parts from the spare-parts list' );

creature_demo_expect( array( 8946 ) === Creature_Demo_Loan::demo_product_ids(), 'with no constant, filter or setting the demo product is 8946' );
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

$_POST = array(
	'creature_demo_loan_consent' => '1',
	'billing_country'            => 'FR',
	'billing_postcode'           => '75001',
	'shipping_country'           => '',
	'shipping_postcode'          => '',
);
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_checkout_process();
$area_notes = array();
foreach ( $GLOBALS['creature_demo_notices'] as $area_note ) {
	$area_notes[] = $area_note[0];
}
creature_demo_expect( false !== strpos( implode( "\n", $area_notes ), 'Northern Ireland' ), 'a demo checkout refuses a billing country other than GB' );
$_POST['billing_country'] = 'GB';
$_POST['billing_postcode'] = 'SW1A 1AA';
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( array() === $GLOBALS['creature_demo_notices'], 'a GB billing address with a mainland postcode is accepted' );
foreach ( array( 'GY1 1AA', 'JE2 3AB', 'IM1 1AG', 'gy1 2bb' ) as $island_postcode ) {
	$_POST['billing_postcode'] = $island_postcode;
	$GLOBALS['creature_demo_notices'] = array();
	Creature_Demo_Loan::on_checkout_process();
	creature_demo_expect( 1 === count( $GLOBALS['creature_demo_notices'] ), 'a demo checkout refuses postcode ' . $island_postcode );
}
$_POST['billing_postcode'] = 'IP1 1AA';
$_POST['shipping_country'] = 'IE';
$_POST['shipping_postcode'] = 'D02 AF30';
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_notices'] ), 'a demo checkout refuses a shipping country other than GB' );
$_POST['shipping_country'] = 'GB';
$_POST['shipping_postcode'] = 'IV1 1AA';
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( array() === $GLOBALS['creature_demo_notices'], 'Inverness and Ipswich postcodes stay inside the service area' );
$saved_area_cart = $GLOBALS['creature_demo_cart'];
$GLOBALS['creature_demo_cart'] = array(
	array( 'product_id' => 50 ),
);
$_POST['billing_country'] = 'FR';
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_checkout_process();
creature_demo_expect( array() === $GLOBALS['creature_demo_notices'], 'a non-demo checkout is not limited to the UK' );
$GLOBALS['creature_demo_cart'] = $saved_area_cart;
$_POST = array(
	'creature_demo_loan_consent' => '1',
	'billing_country'            => 'GB',
	'billing_postcode'           => 'GY2 4AA',
);
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_store_api_service_area(
	null,
	array(
		'billing_address'  => array(
			'country'  => 'GB',
			'postcode' => 'GY2 4AA',
		),
		'shipping_address' => array(
			'country'  => 'GB',
			'postcode' => 'SW1A 1AA',
		),
	)
);
creature_demo_expect( 1 === count( $GLOBALS['creature_demo_notices'] ), 'block checkout refuses a Channel Islands billing postcode' );
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_store_api_service_area(
	null,
	array(
		'billing_address'  => array(
			'country'  => 'GB',
			'postcode' => 'EH1 1YZ',
		),
		'shipping_address' => array(),
	)
);
creature_demo_expect( array() === $GLOBALS['creature_demo_notices'], 'block checkout accepts a Scottish postcode' );
$_POST = array();

$GLOBALS['creature_demo_notices'] = array();
$_POST = array(
	'billing_country'  => 'GB',
	'billing_postcode' => 'SW1A 1AA',
);
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
creature_demo_expect( false !== strpos( $checkbox, '£24' ) && false !== strpos( $checkbox, '£212' ) && false !== strpos( $checkbox, '£35' ), 'the checkbox names the three maxima' );
creature_demo_expect( false !== strpos( $checkbox, 'never be more than £212' ), 'the checkbox states the overall £212 cap' );
creature_demo_expect( false !== strpos( $checkbox, '7 days to pay by link or dispute' ), 'the checkbox states the 7 day pay-or-dispute window' );

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
creature_demo_expect( 'version-5' === $consent_order->get_meta( '_creature_demo_consent_version' ), 'the consent version stored for a new order is version-5' );
creature_demo_expect( false !== strpos( (string) $consent_order->get_meta( '_creature_demo_consent_wording' ), 'never be more than £212' ), 'the stored version-5 wording includes the overall cap' );
$v4_order = wc_create_order();
$GLOBALS['creature_demo_consent_version'] = 'v4';
creature_demo_expect( true === Creature_Demo_Loan::store_consent( $v4_order, true, '2026-03-02T12:00:00+00:00' ), 'a v4 consent can still be stored' );
creature_demo_expect( 'v4' === $v4_order->get_meta( '_creature_demo_consent_version' ), 'the stored consent version follows the filter' );
creature_demo_expect( Creature_Demo_Loan_Copy::consent_wording( 'v4' ) === $v4_order->get_meta( '_creature_demo_consent_wording' ), 'a v4 order stores the v4 wording' );
creature_demo_expect( false === strpos( (string) $v4_order->get_meta( '_creature_demo_consent_wording' ), 'never be more than £212' ), 'v4 wording is not rewritten as v5' );
$GLOBALS['creature_demo_consent_version'] = null;
creature_demo_expect( '2026-03-02T12:00:00+00:00' === $consent_order->get_meta( '_creature_demo_consent_at' ), 'the consent timestamp is stored' );

$_POST['creature_demo_loan_consent'] = '1';
$checkout_order = wc_create_order();
Creature_Demo_Loan::on_checkout_create_order( $checkout_order );
creature_demo_expect( 'version-5' === $checkout_order->get_meta( '_creature_demo_consent_version' ), 'classic checkout stores version-5' );
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
creature_demo_expect( true === Creature_Demo_Loan::filter_force_save( false, $loan_for_save ), 'a demo order object still forces a saved card' );
creature_demo_expect( true === Creature_Demo_Loan::filter_force_save( false, (int) $loan_for_save->get_id() ), 'a demo order id still forces a saved card' );
$plain_for_save = wc_create_order();
creature_demo_expect( false === Creature_Demo_Loan::filter_force_save( false, $plain_for_save ), 'a normal order object does not force a saved card' );
creature_demo_expect( false === Creature_Demo_Loan::filter_force_save( false, (int) $plain_for_save->get_id() ), 'a normal order id does not force a saved card' );
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
creature_demo_expect( false === strpos( $five['body'], 'creature_demo_choice=buy' ), 'the reminder no longer links to a third buy choice' );
creature_demo_expect( false !== strpos( $five['body'], 'creature_demo_choice=return' ), 'the reminder links to return' );
creature_demo_expect( false !== strpos( $five['body'], 'Return it (£25 code once it’s back)' ), 'the return link carries the v5 label' );
creature_demo_expect( false !== strpos( $five['body'], 'Keep it for your keep price' ), 'the keep link carries the v5 label' );
creature_demo_expect( 2 === substr_count( $five['body'], 'creature_demo_choice=' ), 'the reminder offers exactly two choices' );
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
$buy_mails = creature_demo_mail_count( 'choice_return_recorded' );
$admin_mails = creature_demo_mail_count( 'admin_return_booked' );
// `buy` is the retired third choice. A link or form that still sends it is a return.
$first_buy = Creature_Demo_Loan::choose( $buy, 'buy', '2026-03-10' );
$second_buy = Creature_Demo_Loan::choose( $buy, 'buy', '2026-03-11' );
$third_buy = Creature_Demo_Loan::choose( $buy, 'return', '2026-03-12' );
creature_demo_expect( ! empty( $first_buy['ok'] ) && 'return' === $first_buy['choice'], 'the old buy choice is recorded as return' );
creature_demo_expect( ! empty( $second_buy['idempotent'] ), 'choosing buy twice is idempotent' );
creature_demo_expect( ! empty( $third_buy['idempotent'] ), 'buy then return is the same choice' );
creature_demo_expect( $buy_mails + 1 === creature_demo_mail_count( 'choice_return_recorded' ), 'the return acknowledgement is sent once' );
creature_demo_expect( 0 === creature_demo_mail_count( 'choice_buy_recorded' ), 'the retired buy acknowledgement is never sent' );
creature_demo_expect( ! in_array( 'choice_buy_recorded', Creature_Demo_Loan_Copy::slots(), true ), 'the choice_buy_recorded slot is retired' );
creature_demo_expect( ! in_array( 'button_buy', Creature_Demo_Loan_Copy::slots(), true ), 'the button_buy slot is retired' );
creature_demo_expect( $admin_mails + 1 === creature_demo_mail_count( 'admin_return_booked' ), 'a return still alerts the shop to send the QR' );
creature_demo_expect( 'demo-booked' === $buy->get_status(), 'a return books the return' );
creature_demo_expect( 'return' === $buy->get_meta( '_creature_demo_choice' ), 'the stored choice is return' );
$admin = creature_demo_last_mail( 'admin_return_booked' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $admin['to'], 'the shop alert goes to the shop' );
creature_demo_expect( 0 !== strpos( $admin['subject'], '[TEST]' ), 'the shop alert is not prefixed [TEST]' );
$buy_mail = creature_demo_last_mail( 'choice_return_recorded' );
creature_demo_expect( 0 === strpos( $buy_mail['subject'], '[TEST] ' ), 'the return acknowledgement is prefixed [TEST]' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $buy_mail['to'], 'the return acknowledgement is redirected' );
$rider_filter = Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $buy );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $rider_filter, 'Woo customer mail for a demo order is redirected' );
$subject_filter = Creature_Demo_Loan::filter_customer_subject( 'Your order', $buy );
creature_demo_expect( 0 === strpos( $subject_filter, '[TEST] ' ), 'Woo customer mail for a demo order is prefixed' );
$other = wc_create_order();
creature_demo_expect( 'rider@example.com' === Creature_Demo_Loan::filter_customer_recipient( 'rider@example.com', $other ), 'Woo mail for a normal order is unchanged' );

$on_time = Creature_Demo_Loan::record_return_scan( $buy, '2026-03-24', '2026-03-24' );
creature_demo_expect( 0 === $on_time['late_fee'], 'an on-time scan has no late fee' );
$inspected = creature_demo_inspect( $buy, '2026-03-24' );
$again_inspected = creature_demo_inspect( $buy, '2026-03-25' );
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

// Terms 3A.1 and 3A.5: two choices, and the £25 code on every inspected return.
$legacy = creature_demo_loan();
$legacy->update_meta_data( '_creature_demo_choice', 'buy' );
creature_demo_expect( 'return' === Creature_Demo_Loan::effective_choice( $legacy ), 'an order with a stored buy reads as return' );
$legacy_mails = creature_demo_mail_count( 'choice_return_recorded' );
$legacy_again = Creature_Demo_Loan::choose( $legacy, 'return', '2026-03-10' );
creature_demo_expect( ! empty( $legacy_again['idempotent'] ), 'choosing return on a stored buy changes nothing' );
creature_demo_expect( $legacy_mails === creature_demo_mail_count( 'choice_return_recorded' ), 'a stored buy gets no second acknowledgement' );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $legacy );
$legacy_html = ob_get_clean();
creature_demo_expect( false !== strpos( $legacy_html, 'creature_demo_choice=return' ), 'My account shows the return choice for a stored buy' );
creature_demo_expect( false === strpos( $legacy_html, 'creature_demo_choice=buy' ), 'My account has no buy choice' );
creature_demo_expect( false !== strpos( $legacy_html, Creature_Demo_Loan_Copy::text( 'button_return' ) ), 'My account labels the return button from the copy slot' );
Creature_Demo_Loan::record_return_scan( $legacy, '2026-03-20', '2026-03-20' );
$legacy_done = creature_demo_inspect( $legacy, '2026-03-20' );
creature_demo_expect( ! empty( $legacy_done['coupon']['code'] ), 'a stored buy still gets the £25 code at inspection' );

$nochoice = creature_demo_loan();
creature_demo_expect( '' === $nochoice->get_meta( '_creature_demo_choice' ), 'this loan has no stored choice' );
$coupons_before = count( $GLOBALS['creature_demo_coupons'] );
Creature_Demo_Loan::record_return_scan( $nochoice, '2026-03-20', '2026-03-20' );
$nochoice_done = creature_demo_inspect( $nochoice, '2026-03-20' );
creature_demo_expect( ! empty( $nochoice_done['coupon']['code'] ), 'an inspected return with no choice gets the £25 code' );
creature_demo_expect( $coupons_before + 1 === count( $GLOBALS['creature_demo_coupons'] ), 'the no-choice return saves one coupon' );
creature_demo_expect( 'demo-closed' === $nochoice->get_status(), 'a no-choice on-time return closes the loan' );

$return_chosen = creature_demo_loan();
Creature_Demo_Loan::choose( $return_chosen, 'return', '2026-03-10' );
Creature_Demo_Loan::record_return_scan( $return_chosen, '2026-03-20', '2026-03-20' );
$return_done = creature_demo_inspect( $return_chosen, '2026-03-20' );
creature_demo_expect( ! empty( $return_done['coupon']['code'] ), 'a chosen return gets the £25 code at inspection' );

$paid_keep = creature_demo_loan();
Creature_Demo_Loan::choose( $paid_keep, 'return', '2026-03-10' );
$paid_keep->update_meta_data( '_creature_demo_keep_paid', 'yes' );
$paid_keep_coupon = Creature_Demo_Loan::issue_coupon( $paid_keep, '2026-03-10' );
creature_demo_expect( empty( $paid_keep_coupon['ok'] ) && 'no_coupon' === $paid_keep_coupon['reason'], 'no code after a keep payment' );

$deemed_back = creature_demo_loan();
Creature_Demo_Loan::maybe_raise_deemed( $deemed_back, '2026-04-07' );
$coupons_before = count( $GLOBALS['creature_demo_coupons'] );
Creature_Demo_Loan::record_return_scan( $deemed_back, '2026-04-09', '2026-04-09' );
$deemed_back_done = creature_demo_inspect( $deemed_back, '2026-04-09' );
creature_demo_expect( empty( $deemed_back_done['coupon']['ok'] ), 'no code after clause 8 applies, even when the kit comes back and the unpaid deemed purchase is cancelled' );
creature_demo_expect( $coupons_before === count( $GLOBALS['creature_demo_coupons'] ), 'a deemed purchase saves no coupon' );

// Terms 3A.4: no code once any of the £25 was refunded under clause 10.4 (lost or damaged on the way out) or 11 (cancelling).
$coupons_before = count( $GLOBALS['creature_demo_coupons'] );
// Blocked: a refund marked as under 10.4, 11.2 or 11.3 (programmatically, or by the admin checkbox for a manual refund in Woo admin).
foreach ( array( '10.4' => 'full refund, kit lost on the way out', '11.2' => 'cancelled before dispatch, full refund', '11.3' => 'pro-rata refund after dispatch' ) as $r_clause => $r_what ) {
	$r_loan = creature_demo_loan();
	if ( '11.3' === $r_clause ) {
		$r_loan->refunded = 5.0;
	}
	Creature_Demo_Loan::mark_refunded_under_10_4_or_11( $r_loan, $r_clause );
	$r_c = Creature_Demo_Loan::issue_coupon( $r_loan, '2026-03-20' );
	creature_demo_expect( empty( $r_c['ok'] ) && 'fee_refunded' === $r_c['reason'], "clause {$r_clause} ({$r_what}): no £25 code" );
}
// The mark survives the status change when the return is recorded later.
$r_back = creature_demo_loan();
$r_back->update_status( 'cancelled' );
Creature_Demo_Loan::mark_refunded_under_10_4_or_11( $r_back, '11.3' );
Creature_Demo_Loan::record_return_scan( $r_back, '2026-03-20', '2026-03-20' );
$r_back_done = creature_demo_inspect( $r_back, '2026-03-20' );
creature_demo_expect( empty( $r_back_done['coupon']['ok'] ) && 'fee_refunded' === $r_back_done['coupon']['reason'], 'a marked order that comes back and is inspected still gets no code' );
// A manual refund made in Woo admin: the admin ticks the checkbox on the order screen.
$r_man = creature_demo_loan();
$r_man->refunded = 25.0;
$r_man->update_status( 'refunded' );
creature_demo_expect( ! empty( Creature_Demo_Loan::issue_coupon( creature_demo_loan(), '2026-03-20' )['ok'] ) && false === Creature_Demo_Loan::fee_was_refunded( $r_man ), 'Woo alone cannot say why it was refunded, so nothing is guessed from the refund or the status' );
$_POST = array( 'creature_demo_loan_admin_nonce' => 'creature_demo_loan_admin', 'creature_demo_flags_present' => '1', 'creature_demo_refunded_10411' => '1' );
Creature_Demo_Loan::save_meta_box( $r_man->get_id(), $r_man );
$_POST = array();
$r_man_c = Creature_Demo_Loan::issue_coupon( $r_man, '2026-03-20' );
creature_demo_expect( empty( $r_man_c['ok'] ) && 'fee_refunded' === $r_man_c['reason'], 'ticking "Refunded under 10.4/11 (no £25 code)" blocks the code' );
ob_start();
Creature_Demo_Loan::render_meta_box( $r_man );
$r_man_html = ob_get_clean();
creature_demo_expect( false !== strpos( $r_man_html, 'name="creature_demo_refunded_10411"' ) && false !== strpos( $r_man_html, 'Refunded under 10.4/11 (no £25 code)' ) && false !== strpos( $r_man_html, 'checked="checked" /> Refunded under 10.4/11' ), 'the order screen has the labelled checkbox, ticked once set' );
$_POST = array( 'creature_demo_loan_admin_nonce' => 'creature_demo_loan_admin', 'creature_demo_flags_present' => '1' );
Creature_Demo_Loan::save_meta_box( $r_man->get_id(), $r_man );
$_POST = array();
creature_demo_expect( false === Creature_Demo_Loan::fee_was_refunded( $r_man ), 'unticking it removes the block' );
// Not blocked on their own: goodwill partial refund, a damage refund after an upheld dispute, the deemed refund.
$r_good = creature_demo_loan();
$r_good->refunded = 5.0;
creature_demo_expect( ! empty( Creature_Demo_Loan::issue_coupon( $r_good, '2026-03-20' )['ok'] ), 'a goodwill partial refund does not block the code' );
$r_dmg = creature_demo_loan();
$r_dmg->update_meta_data( '_creature_demo_damage_pence', 2000 );
Creature_Demo_Loan::record_return_scan( $r_dmg, '2026-03-30', '2026-03-30' );
$r_dmg_charge = creature_demo_bill_charge( $r_dmg, '2026-03-30' );
Creature_Demo_Loan::mark_disputed( $r_dmg_charge, '2026-03-31' );
Creature_Demo_Loan::record_dispute_decision( $r_dmg_charge, 'upheld', '2026-04-05', 500 );
$r_dmg->refunded = 15.0;
creature_demo_expect( false === Creature_Demo_Loan::fee_was_refunded( $r_dmg ) && '' !== $r_dmg->get_meta( '_creature_demo_coupon_code' ), 'a damage refund after an upheld dispute does not block the code (the return still got it)' );
creature_demo_stripe_test();
$r_deemed = creature_demo_paid_deemed_returned();
$r_deemed->update_meta_data( '_creature_demo_ever_chose_keep', '' );
$GLOBALS['creature_demo_refund_flag'] = true;
WC_Stripe_API::$response = (object) array( 'id' => 're_test_item6', 'status' => 'succeeded' );
Creature_Demo_Loan::refund_deemed( $r_deemed, '2026-04-09' );
$GLOBALS['creature_demo_refund_flag'] = null;
creature_demo_expect( 'yes' === $r_deemed->get_meta( '_creature_demo_refund_done' ) && false === Creature_Demo_Loan::fee_was_refunded( $r_deemed ), 'the deemed refund (8.5) does not set the 10.4/11 block' );
creature_demo_expect( ! empty( Creature_Demo_Loan::issue_coupon( creature_demo_loan(), '2026-03-20' )['ok'] ), 'a loan with no refund still gets the code' );
creature_demo_expect( $coupons_before + 4 <= count( $GLOBALS['creature_demo_coupons'] ), 'the unblocked cases saved their coupons' );

$switch = creature_demo_loan();
creature_demo_expect( 'return' === Creature_Demo_Loan::effective_choice( $switch ), 'no choice means return' );
$after_due = Creature_Demo_Loan::choose( $switch, 'return', '2026-03-25' );
creature_demo_expect( ! empty( $after_due['ok'] ), 'return can still be chosen after the due date before a scan' );
$return_mail_count = creature_demo_mail_count( 'choice_return_recorded' );
$admin_before = creature_demo_mail_count( 'admin_return_booked' );
$back_to_return = Creature_Demo_Loan::choose( $switch, 'buy', '2026-03-27' );
creature_demo_expect( ! empty( $back_to_return['idempotent'] ) && 'return' === $switch->get_meta( '_creature_demo_choice' ), 'the old buy link on a return order changes nothing' );
creature_demo_expect( $return_mail_count === creature_demo_mail_count( 'choice_return_recorded' ), 'a repeat does not send the return email again' );
creature_demo_expect( $admin_before === creature_demo_mail_count( 'admin_return_booked' ), 'the shop QR alert is not repeated' );
Creature_Demo_Loan::record_return_scan( $switch, '2026-03-28', '2026-03-28' );
$after_scan = Creature_Demo_Loan::choose( $switch, 'keep', '2026-03-28' );
creature_demo_expect( empty( $after_scan['ok'] ) && 'window' === $after_scan['reason'], 'a scan closes the keep choice' );

$tight = creature_demo_loan();
$GLOBALS['creature_demo_switch_until'] = 'due_date';
$late_switch = Creature_Demo_Loan::choose( $tight, 'return', '2026-03-25' );
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
$too_soon = Creature_Demo_Loan::maybe_lapse_keep( $lapse, '2026-05-07' );
creature_demo_expect( empty( $too_soon['ok'] ) && 'too_soon' === $too_soon['reason'], 'six calendar days is too soon to lapse a keep link' );
$lapsed = Creature_Demo_Loan::maybe_lapse_keep( $lapse, '2026-05-08' );
creature_demo_expect( ! empty( $lapsed['lapsed'] ), 'seven calendar days lapses an unpaid keep link' );
creature_demo_expect( 'return' === $lapse->get_meta( '_creature_demo_choice' ), 'a lapsed keep restores the earlier return choice' );
creature_demo_expect( 1 === creature_demo_mail_count( 'keep_choice_lapsed' ), 'the lapse email is sent' );
$lapsed_again = Creature_Demo_Loan::maybe_lapse_keep( $lapse, '2026-05-08' );
creature_demo_expect( ! empty( $lapsed_again['idempotent'] ), 'lapsing twice is idempotent' );

$short = creature_demo_loan( '2026-04-29', '2026-05-01' );
$GLOBALS['creature_demo_lapse_days'] = 1;
Creature_Demo_Loan::choose( $short, 'keep', '2026-05-01' );
$short_same = Creature_Demo_Loan::maybe_lapse_keep( $short, '2026-05-01' );
creature_demo_expect( empty( $short_same['ok'] ) && 'too_soon' === $short_same['reason'], 'the day the keep link is sent does not lapse it' );
$short_lapse = Creature_Demo_Loan::maybe_lapse_keep( $short, '2026-05-02' );
creature_demo_expect( ! empty( $short_lapse['lapsed'] ), 'a one-day lapse setting lapses on the next calendar day' );
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
$not_return = Creature_Demo_Loan::issue_coupon( $keep, '2026-03-10' );
creature_demo_expect( empty( $not_return['ok'] ) && 'not_return' === $not_return['reason'], 'a kept loan does not get the £25 code' );

$inside = creature_demo_loan();
$inside->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$inside_keep = Creature_Demo_Loan::choose( $inside, 'keep', '2026-04-06' );
creature_demo_expect( 19200 === $inside_keep['amount'], 'paid late fees come off £212 and accrued fees are not added' );

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
$late_mails_before_scan = creature_demo_mail_count( 'charge_notice_late_fee' );
$scanned = Creature_Demo_Loan::record_return_scan( $late, '2026-03-30', '2026-03-30' );
creature_demo_expect( 300 === $scanned['late_fee'], 'the day-6 scan works out a £3 late fee' );
creature_demo_expect( '' === $late->get_meta( '_creature_demo_bill_order_id' ) && '' === $late->get_meta( '_creature_demo_late_fee_order_id' ), 'the scan does not charge the late fee on its own (terms 4.4: one bill)' );
creature_demo_expect( $late_mails_before_scan === creature_demo_mail_count( 'charge_notice_late_fee' ), 'no late-fee email goes out at the scan' );
$repeat_scan = Creature_Demo_Loan::record_return_scan( $late, '2026-04-06', '2026-04-06' );
creature_demo_expect( ! empty( $repeat_scan['idempotent'] ) && '2026-03-30' === $repeat_scan['scan'], 'a second scan keeps the first date' );
$late_charge = creature_demo_bill_charge( $late );
$late_id = (int) $late_charge->get_id();
creature_demo_expect( 300 === (int) $late_charge->get_meta( '_creature_demo_charge_amount_pence' ) && 'bill' === $late_charge->get_meta( '_creature_demo_charge_kind' ), 'inspection raises the bill with the £3 late fee' );
creature_demo_expect( 'yes' === $late_charge->get_meta( '_creature_demo_charge_notice_logged' ), 'the late-fee notice is logged before any charge' );
$late_mail = creature_demo_last_mail( 'charge_notice_late_fee' );
creature_demo_expect( false !== strpos( $late_mail['body'], '£3.00' ), 'the late-fee email names £3' );
creature_demo_expect( false !== strpos( $late_mail['body'], '7 days' ), 'the late-fee notice gives 7 days' );
$stripe_calls = count( WC_Stripe_API::$requests );
$too_soon_charge = Creature_Demo_Loan::charge_saved_card( $late, '2026-03-30' );
creature_demo_expect( 'notice_too_recent' === $too_soon_charge['code'], 'the notice day itself cannot charge' );
$next_day = Creature_Demo_Loan::charge_saved_card( $late, '2026-03-31' );
creature_demo_expect( 'notice_too_recent' === $next_day['code'], 'one calendar day is not enough' );
$six_days = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-05' );
creature_demo_expect( 'notice_too_recent' === $six_days['code'], 'six calendar days is not enough' );
creature_demo_expect( $stripe_calls === count( WC_Stripe_API::$requests ), 'a refused charge does not call Stripe' );

$weekend = creature_demo_loan();
Creature_Demo_Loan::choose( $weekend, 'keep', '2026-03-06' );
$monday = Creature_Demo_Loan::charge_saved_card( $weekend, '2026-03-12' );
creature_demo_expect( 'notice_too_recent' === $monday['code'], 'six calendar days is too soon even when they include a weekend' );
$weekend_charge = wc_get_order( (int) $weekend->get_meta( '_creature_demo_keep_order_id' ) );
$weekend_clear = Creature_Demo_Loan::evaluate_charge( $weekend, $weekend_charge, '2026-03-13' );
creature_demo_expect( '' === $weekend_clear['code'], 'seven calendar days clears the notice, and the weekend counts' );

$holiday = creature_demo_loan( '2026-04-29', '2026-05-01' );
Creature_Demo_Loan::choose( $holiday, 'keep', '2026-05-01' );
$after_holiday = Creature_Demo_Loan::charge_saved_card( $holiday, '2026-05-07' );
creature_demo_expect( 'notice_too_recent' === $after_holiday['code'], 'six calendar days is too soon even when they include the May bank holiday' );
$holiday_charge = wc_get_order( (int) $holiday->get_meta( '_creature_demo_keep_order_id' ) );
$holiday_clear = Creature_Demo_Loan::evaluate_charge( $holiday, $holiday_charge, '2026-05-08' );
creature_demo_expect( '' === $holiday_clear['code'], 'seven calendar days clears the notice, and the bank holiday counts' );
creature_demo_expect( $stripe_calls === count( WC_Stripe_API::$requests ), 'bank-holiday and weekend refusals do not call Stripe' );

$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'no';
$live_mode = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-06' );
creature_demo_expect( 'live_mode' === $live_mode['code'], 'live mode is refused' );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'yes';
WC_Stripe_API::$secret = 'sk_live_' . 'placeholder';
$live_key = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-06' );
creature_demo_expect( 'live_key' === $live_key['code'], 'a live secret key is refused in test mode' );
WC_Stripe_API::$secret = 'sk_test_' . 'placeholder';
creature_demo_expect( $stripe_calls === count( WC_Stripe_API::$requests ), 'live-mode refusals do not call Stripe' );

$late->update_meta_data( '_stripe_customer_id', '' );
$late->update_meta_data( '_stripe_source_id', '' );
$no_card = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-06' );
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
creature_demo_inspect( $unconsented, '2026-03-30' );
$consent_calls = count( WC_Stripe_API::$requests );
$refused_consent = Creature_Demo_Loan::charge_saved_card( $unconsented, '2026-04-06' );
creature_demo_expect( 'no_consent' === $refused_consent['code'], 'a charge without recorded consent is refused' );
creature_demo_expect( $consent_calls === count( WC_Stripe_API::$requests ), 'missing consent does not call Stripe' );

WC_Stripe_API::$response = new WP_Error( 'stripe_error', 'authentication_required' );
$auth = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-06' );
creature_demo_expect( 'authentication_required' === $auth['code'], 'authentication required does not take the money' );
creature_demo_expect( 'yes' !== $late_charge->get_meta( '_creature_demo_charge_paid' ), 'an authentication challenge leaves the charge unpaid' );
creature_demo_expect( false === creature_demo_lock_row( $late_charge ), 'authentication required releases the saved-card lock' );
creature_demo_expect( 1 === creature_demo_mail_count( 'charge_authentication_required' ), 'authentication required emails the payment link' );
$auth_key = $late_charge->get_meta( '_creature_demo_idempotency_key' );
creature_demo_expect( '' !== $auth_key, 'the first attempt stores an idempotency key' );

WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);
$paid = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-06' );
$paid_again = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-07' );
creature_demo_expect( ! empty( $paid['ok'] ) && 'pi_test_demo_1' === $paid['id'], 'a clear gate charges the saved card once' );
creature_demo_expect( 'already_paid' === $paid_again['code'], 'a paid charge is not taken again' );
creature_demo_expect( '300' === (string) $late->get_meta( '_creature_demo_late_fees_paid_pence' ), 'the £3 late fee is recorded once' );
creature_demo_expect( 'processing' === $late_charge->get_status(), 'a successful saved-card charge marks the charge order processing' );
creature_demo_expect( 'pi_test_demo_1' === $late_charge->get_transaction_id(), 'the charge order stores the Stripe payment id' );
creature_demo_expect( false === Creature_Demo_Loan::filter_needs_payment( true, $late_charge ), 'the pay link cannot take a second payment' );
creature_demo_expect( 'yes' === $late_charge->get_meta( '_creature_demo_charge_order' ), 'the charge order is marked as a demo charge' );
creature_demo_expect( false === creature_demo_lock_row( $late_charge ), 'a successful charge clears the lock' );
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
		creature_demo_expect( 300 === $request['body']['amount'], 'the PaymentIntent amount is 300 pence' );
		creature_demo_expect( 'cus_test_walker' === $request['body']['customer'], 'the PaymentIntent uses the saved customer' );
		creature_demo_expect( 'pm_test_walker' === $request['body']['payment_method'], 'the PaymentIntent uses the saved payment method' );
	}
}
creature_demo_expect( 2 === $success_requests, 'authentication and the later success share one idempotency key' );
$joined_notes = implode( "\n", $late->notes );
creature_demo_expect( false !== strpos( $joined_notes, 'pi_test_demo_1' ), 'the order note records the PaymentIntent id' );

$retry = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $retry, '2026-03-30', '2026-03-30' );
$retry_charge = creature_demo_bill_charge( $retry );
WC_Stripe_API::$response = (object) array(
	'error' => (object) array(
		'code'         => 'card_declined',
		'decline_code' => 'generic_decline',
	),
);
$declined = Creature_Demo_Loan::charge_saved_card( $retry, '2026-04-06' );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_2',
	'status' => 'succeeded',
);
$retried = Creature_Demo_Loan::charge_saved_card( $retry, '2026-04-06' );
creature_demo_expect( 'stripe_error' === $declined['code'], 'a decline is a stripe error and not a paid charge' );
creature_demo_expect( false === creature_demo_lock_row( $retry_charge ), 'a declined charge releases the lock so the pay link still works' );
creature_demo_expect( ! empty( $retried['ok'] ), 'the same charge can be retried after a decline' );
creature_demo_expect( $retry_charge->get_meta( '_creature_demo_idempotency_key' ) === Creature_Demo_Loan::idempotency_key( $retry_charge ), 'a retry keeps the original idempotency key' );

$disputed = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $disputed, '2026-03-30', '2026-03-30' );
$dispute_charge = creature_demo_bill_charge( $disputed );
Creature_Demo_Loan::mark_disputed( $dispute_charge, '2026-03-30' );
Creature_Demo_Loan::mark_disputed( $dispute_charge, '2026-03-31' );
$while_open = Creature_Demo_Loan::charge_saved_card( $disputed, '2026-04-06' );
creature_demo_expect( 'disputed' === $while_open['code'], 'an open dispute blocks the saved card' );
$upheld = Creature_Demo_Loan::record_dispute_decision( $dispute_charge, 'upheld', '2026-04-02', null );
creature_demo_expect( ! empty( $upheld['timely'] ), 'a decision 3 days later is inside 14 days' );
$calls_before_uphold = count( WC_Stripe_API::$requests );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_3',
	'status' => 'succeeded',
);
$too_soon_after_uphold = Creature_Demo_Loan::charge_saved_card( $disputed, '2026-04-06' );
creature_demo_expect( 'notice_too_recent' === $too_soon_after_uphold['code'], 'an upheld charge waits 7 days from the decision email, not from the first notice' );
creature_demo_expect( $calls_before_uphold === count( WC_Stripe_API::$requests ), 'the early upheld charge does not call Stripe' );
creature_demo_expect( '2026-04-02' === $dispute_charge->get_meta( '_creature_demo_decision_sent_at' ), 'the decision-sent time is stored on the charge' );
$after_uphold = Creature_Demo_Loan::charge_saved_card( $disputed, '2026-04-09' );
creature_demo_expect( ! empty( $after_uphold['ok'] ), 'upholding the dispute lets the charge through 7 days after the decision email' );
creature_demo_expect( count( WC_Stripe_API::$requests ) === $calls_before_uphold + 1, 'the upheld charge calls Stripe once' );
$decision_mail = creature_demo_last_mail( 'dispute_decision' );
creature_demo_expect( 'demo-loan-test@creaturecycles.co.uk' === $decision_mail['to'], 'the dispute decision is redirected' );
creature_demo_expect( false !== strpos( $decision_mail['body'], 'upheld' ), 'the decision email names the outcome' );

$cap_loan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cap_loan, '2026-03-30', '2026-03-30' );
$cap_charge = creature_demo_bill_charge( $cap_loan );
Creature_Demo_Loan::mark_disputed( $cap_charge, '2026-03-30' );
$cap_key = Creature_Demo_Loan::idempotency_key( $cap_charge );
$over_cap = Creature_Demo_Loan::record_dispute_decision( $cap_charge, 'upheld', '2026-04-02', 9000 );
creature_demo_expect( ! empty( $over_cap['capped'] ) && 300 === $over_cap['amount'], 'an upheld dispute cannot exceed the original notice' );
creature_demo_expect( $cap_key === $cap_charge->get_meta( '_creature_demo_idempotency_key' ), 'capping to the same amount keeps the idempotency key' );
$notice_mails = creature_demo_mail_count( 'charge_notice_late_fee' );
$lowered = Creature_Demo_Loan::record_dispute_decision( $cap_charge, 'upheld', '2026-04-02', 200 );
creature_demo_expect( 200 === $lowered['amount'], 'an upheld amount below the notice is kept' );
creature_demo_expect( '' === $cap_charge->get_meta( '_creature_demo_idempotency_key' ), 'a changed amount clears the stored idempotency key' );
creature_demo_expect( $notice_mails + 1 === creature_demo_mail_count( 'charge_notice_late_fee' ), 'a changed amount logs a fresh notice' );
$rebuilt_key = Creature_Demo_Loan::idempotency_key( $cap_charge );
creature_demo_expect( $rebuilt_key !== $cap_key && false !== strpos( $rebuilt_key, '200' ), 'the next idempotency key uses the new amount' );

// Item 3: the late fee recorded as paid is what was actually charged, not the original line.
// Scan 2026-04-02: late fee 4 days at £3 = £12 (1200p). Damage £20. Bill £32.
$pu = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $pu, '2026-04-02', '2026-04-02' );
$pu->update_meta_data( '_creature_demo_damage_pence', 2000 );
$pu_charge = creature_demo_bill_charge( $pu, '2026-04-02' );
creature_demo_expect( 3200 === (int) $pu_charge->get_meta( '_creature_demo_charge_amount_pence' ) && 1200 === (int) $pu_charge->get_meta( '_creature_demo_charge_late_pence' ), 'the bill is £12 late fee and £20 damage' );
Creature_Demo_Loan::mark_disputed( $pu_charge, '2026-04-03' );
Creature_Demo_Loan::record_dispute_decision( $pu_charge, 'upheld', '2026-04-10', 500 );
creature_demo_expect( 500 === (int) $pu_charge->get_meta( '_creature_demo_charge_amount_pence' ) && 500 === (int) $pu_charge->get_meta( '_creature_demo_charge_late_pence' ), 'a decision that cuts the bill below its late-fee line cuts the late-fee line too' );
Creature_Demo_Loan::on_charge_paid( $pu, $pu_charge );
creature_demo_expect( '500' === (string) $pu->get_meta( '_creature_demo_late_fees_paid_pence' ), 'late fees paid is the £5 actually charged, not the £12 line' );
// The deemed amount and the aggregate cap use that figure.
creature_demo_expect( Creature_Demo_Loan_Money::deemed_amount_pence( 21200, 500 ) === 20700, 'the deemed amount is the keep price less the £5 actually paid' );
// A partly upheld bill that still covers the late-fee line records the whole line.
$pu2 = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $pu2, '2026-04-02', '2026-04-02' );
$pu2->update_meta_data( '_creature_demo_damage_pence', 2000 );
$pu2_charge = creature_demo_bill_charge( $pu2, '2026-04-02' );
Creature_Demo_Loan::mark_disputed( $pu2_charge, '2026-04-03' );
Creature_Demo_Loan::record_dispute_decision( $pu2_charge, 'upheld', '2026-04-10', 2000 );
Creature_Demo_Loan::on_charge_paid( $pu2, $pu2_charge );
creature_demo_expect( '1200' === (string) $pu2->get_meta( '_creature_demo_late_fees_paid_pence' ), 'a cut that leaves the late-fee line whole records the whole £12' );
// An unchanged bill is unchanged.
$pu3 = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $pu3, '2026-04-02', '2026-04-02' );
$pu3->update_meta_data( '_creature_demo_damage_pence', 2000 );
$pu3_charge = creature_demo_bill_charge( $pu3, '2026-04-02' );
Creature_Demo_Loan::on_charge_paid( $pu3, $pu3_charge );
creature_demo_expect( '1200' === (string) $pu3->get_meta( '_creature_demo_late_fees_paid_pence' ), 'a bill paid in full records its late-fee line' );
// Defence in depth: if only the amount was lowered, payment still caps the late fee at what was charged.
$pu4 = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $pu4, '2026-04-02', '2026-04-02' );
$pu4_charge = creature_demo_bill_charge( $pu4, '2026-04-02' );
$pu4_charge->update_meta_data( '_creature_demo_charge_amount_pence', 700 );
Creature_Demo_Loan::on_charge_paid( $pu4, $pu4_charge );
creature_demo_expect( '700' === (string) $pu4->get_meta( '_creature_demo_late_fees_paid_pence' ), 'payment records no more late fee than the amount charged' );

$alert_loan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $alert_loan, '2026-03-30', '2026-03-30' );
$alert_charge = creature_demo_bill_charge( $alert_loan );
Creature_Demo_Loan::mark_disputed( $alert_charge, '2026-03-30' );
$alerts = creature_demo_mail_count( 'admin_dispute_nearing' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-08' );
creature_demo_expect( $alerts === creature_demo_mail_count( 'admin_dispute_nearing' ), 'an open dispute does not alert before day 10' );
Creature_Demo_Loan::tick( $alert_loan, '2026-04-09' );
creature_demo_expect( $alerts + 1 === creature_demo_mail_count( 'admin_dispute_nearing' ), 'an open dispute alerts the shop on day 10' );
$day_ten = creature_demo_last_mail( 'admin_dispute_nearing' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $day_ten['to'] && 0 !== strpos( $day_ten['subject'], '[TEST]' ), 'the dispute alert goes to the shop without a test prefix' );
creature_demo_expect( false !== strpos( $day_ten['body'], '30 March 2026' ) && false === strpos( $day_ten['body'], '2026-03-30' ), 'the dispute alert names the day it opened as 30 March 2026' );
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
$waive_charge = creature_demo_bill_charge( $waived );
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
creature_demo_expect( 'return' === $future->get_meta( '_creature_demo_choice' ), 'a nonce from the account holder records the choice, and an old buy link is a return' );
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
$net_raised = Creature_Demo_Loan::maybe_raise_deemed( $net, '2026-04-07' );
creature_demo_expect( 19200 === $net_raised['amount'], 'deemed purchase nets off late fees already paid' );
$net_charge = wc_get_order( $net_raised['order_id'] );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_deemed_1',
	'status' => 'succeeded',
);
$net_paid = Creature_Demo_Loan::charge_saved_card( $net, '2026-04-14' );
creature_demo_expect( ! empty( $net_paid['ok'] ), 'the deemed purchase can be charged after 7 days' );
creature_demo_expect( 'yes' === $net->get_meta( '_creature_demo_unit_sold' ), 'a paid deemed purchase marks the unit sold' );
creature_demo_expect( '' === $net->get_meta( '_creature_demo_coupon_code' ), 'a paid deemed purchase does not issue a coupon' );
$deemed_fleet = false;
foreach ( $GLOBALS['creature_demo_fleet'] as $row ) {
	if ( $row[0] === (int) $net->get_id() && 'deemed' === $row[1] ) {
		$deemed_fleet = true;
	}
}
creature_demo_expect( $deemed_fleet, 'a paid deemed purchase removes the unit from the fleet' );

// Item 5, terms 8.5(a): the kit comes back after the deemed purchase date and the deemed price is not paid.
creature_demo_stripe_test();
$ua = creature_demo_loan();
$ua_raised = Creature_Demo_Loan::maybe_raise_deemed( $ua, '2026-04-07' );
$ua_charge = wc_get_order( $ua_raised['order_id'] );
creature_demo_expect( 'demo-deemed' === $ua->get_status() && 'yes' === $ua->get_meta( '_creature_demo_deemed_raised' ) && 'pending' === $ua_charge->get_status(), 'the unpaid deemed purchase is open with a payment link' );
$ua_requests = count( WC_Stripe_API::$requests );
$ua_coupons  = count( $GLOBALS['creature_demo_coupons'] );
$ua_scan     = Creature_Demo_Loan::record_return_scan( $ua, '2026-04-09', '2026-04-09' );
creature_demo_expect( ! empty( $ua_scan['deemed_cancelled'] ) && 'cancelled' === $ua_charge->get_status(), '8.5(a): the unpaid deemed charge is cancelled' );
creature_demo_expect( false === Creature_Demo_Loan::filter_needs_payment( true, $ua_charge ), 'no payment link is open on the cancelled deemed charge' );
$ua_card_direct = Creature_Demo_Loan::charge_saved_card( $ua_charge, '2026-04-20' );
$ua_card_loan   = Creature_Demo_Loan::charge_saved_card( $ua, '2026-04-20' );
creature_demo_expect( 'charge_cancelled' === $ua_card_direct['code'] && empty( $ua_card_loan['ok'] ) && $ua_requests === count( WC_Stripe_API::$requests ), 'the saved card is not charged for the cancelled deemed purchase' );
creature_demo_expect( 'yes' !== $ua->get_meta( '_creature_demo_deemed_raised' ) && 'demo-inspect' === $ua->get_status(), 'the kit is treated as a late return, in inspection' );
$ua_again = Creature_Demo_Loan::maybe_raise_deemed( $ua, '2026-04-20' );
creature_demo_expect( empty( $ua_again['ok'] ) && 'cancelled' === $ua_again['reason'], 'a cancelled deemed purchase is not raised again' );
Creature_Demo_Loan::tick( $ua, '2026-04-21' );
creature_demo_expect( 'cancelled' === $ua_charge->get_status() && 'yes' !== $ua->get_meta( '_creature_demo_deemed_raised' ), 'the daily check does not bring it back' );
$ua->update_meta_data( '_creature_demo_damage_pence', 2000 );
Creature_Demo_Loan::record_kit_received( $ua, '2026-04-09' );
$ua_done   = creature_demo_inspect( $ua, '2026-04-09' );
$ua_bill   = wc_get_order( (int) $ua->get_meta( '_creature_demo_bill_order_id' ) );
$ua_lines  = $ua_bill ? $ua_bill->get_meta( '_creature_demo_charge_lines' ) : array();
creature_demo_expect( ! empty( $ua_done['ok'] ) && 4400 === (int) $ua_bill->get_meta( '_creature_demo_charge_amount_pence' ), 'the late return goes through the one bill: £24 late fee plus £20 damage' );
creature_demo_expect( array( 'late_fee', 'damage' ) === array_column( $ua_lines, 'kind' ) && array( 2400, 2000 ) === array_column( $ua_lines, 'pence' ), 'the bill has a £24 late-fee line and a damage line' );
creature_demo_expect( 'bill' === $ua_bill->get_meta( '_creature_demo_charge_kind' ) && 'charge_notice_damage' === creature_demo_last_charge_slot(), 'it is a normal bill, with the combined bill email' );
creature_demo_expect( empty( $ua_done['coupon']['ok'] ) && $ua_coupons === count( $GLOBALS['creature_demo_coupons'] ) && '' === $ua->get_meta( '_creature_demo_coupon_code' ), 'no £25 code after clause 8 applies (3A.4)' );
creature_demo_expect( 'cancelled' === $ua_charge->get_status() && 2 === creature_demo_charge_orders_for( $ua ), 'the only charge orders are the cancelled deemed one and the bill' );
// Not raised yet when the scan is recorded.
$ub = creature_demo_loan();
$ub_coupons = count( $GLOBALS['creature_demo_coupons'] );
$ub_scan = Creature_Demo_Loan::record_return_scan( $ub, '2026-04-20', '2026-04-20' );
creature_demo_expect( 0 === creature_demo_charge_orders_for( $ub ) && 'demo-inspect' === $ub->get_status() && ! empty( $ub_scan['after_deemed'] ), 'a scan after day 14 with no deemed charge yet raises none' );
$ub_done = creature_demo_inspect( $ub, '2026-04-20' );
creature_demo_expect( 2400 === (int) wc_get_order( (int) $ub->get_meta( '_creature_demo_bill_order_id' ) )->get_meta( '_creature_demo_charge_amount_pence' ) && $ub_coupons === count( $GLOBALS['creature_demo_coupons'] ), 'that late return is billed £24 and gets no code' );
// Paid: 8.5(b), nothing is cancelled and nothing is billed. The refund is a separate action.
$pa = creature_demo_loan();
$pa_raised = Creature_Demo_Loan::maybe_raise_deemed( $pa, '2026-04-07' );
$pa_charge = wc_get_order( $pa_raised['order_id'] );
$pa_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_paid_85' );
Creature_Demo_Loan::on_payment_complete( $pa_raised['order_id'] );
creature_demo_expect( 'yes' === $pa->get_meta( '_creature_demo_deemed_paid' ) && '' !== $pa->get_meta( '_creature_demo_deemed_paid_at' ), 'a paid deemed purchase records the date it was paid' );
$pa_scan = Creature_Demo_Loan::record_return_scan( $pa, '2026-04-10', '2026-04-10' );
creature_demo_expect( ! empty( $pa_scan['after_deemed'] ) && empty( $pa_scan['deemed_cancelled'] ) && 'cancelled' !== $pa_charge->get_status() && 'demo-deemed' === $pa->get_status(), 'a paid deemed purchase is not cancelled when the kit comes back' );
$pa_done = creature_demo_inspect( $pa, '2026-04-10' );
creature_demo_expect( ! empty( $pa_done['awaiting_refund'] ) && '' === $pa->get_meta( '_creature_demo_bill_state' ) && '' === $pa->get_meta( '_creature_demo_bill_order_id' ) && '' === $pa->get_meta( '_creature_demo_coupon_code' ), 'paid: no late-return bill and no code, the refund waits for the refund action' );
// Posted by the end of day 13 but the deemed charge was raised before anyone knew: a normal return, with the code.
$pd = creature_demo_loan();
$pd_raised = Creature_Demo_Loan::maybe_raise_deemed( $pd, '2026-04-07' );
$pd_charge = wc_get_order( $pd_raised['order_id'] );
Creature_Demo_Loan::record_return_posted( $pd, '2026-04-06' );
Creature_Demo_Loan::record_return_scan( $pd, '2026-04-09', '2026-04-09' );
$pd_done = creature_demo_inspect( $pd, '2026-04-09' );
creature_demo_expect( 'cancelled' === $pd_charge->get_status() && ! empty( $pd_done['coupon']['code'] ), 'posted in time: the early deemed charge is cancelled and the return gets the £25 code' );

// The deemed deduction notice has no v2 wording (the slot is empty). These tests of the refund flow
// give it a test body, with a {link} on purpose to show that the link is never filled in.
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array(
	'subject' => 'Test deduction notice for order {order_id}',
	'body'    => 'Test deduction notice. Amount: {amount}. Reason: {reason}. Link: [{link}]',
);
$back_after = creature_demo_loan();
$back_after->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$back_after->update_meta_data( '_creature_demo_ever_chose_keep', 'yes' );
$back_raised = Creature_Demo_Loan::maybe_raise_deemed( $back_after, '2026-04-07' );
$back_charge = wc_get_order( $back_raised['order_id'] );
$back_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_refund' );
Creature_Demo_Loan::on_payment_complete( $back_raised['order_id'] );
Creature_Demo_Loan::record_return_scan( $back_after, '2026-04-08', '2026-04-08' );
Creature_Demo_Loan::record_kit_received( $back_after, '2026-04-08' );
$awaiting = creature_demo_inspect( $back_after, '2026-04-08' );
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
$ba_requests = count( WC_Stripe_API::$requests );
$refunded = Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-09' );
creature_demo_expect( ! empty( $refunded['ok'] ) && ! empty( $refunded['pending'] ) && 17300 === $refunded['amount'] && 1500 === $refunded['held'], 'with the flag on, the keep chooser is refunded the undisputed £173 and £15 of damage is held' );
$ba_charge = wc_get_order( (int) $back_after->get_meta( '_creature_demo_damage_order_id' ) );
creature_demo_expect( is_object( $ba_charge ) && 1500 === (int) $ba_charge->get_meta( '_creature_demo_charge_amount_pence' ) && 'deduction_notice_deemed' === creature_demo_last_charge_slot(), 'D3: the deduction goes out as its own notice (deduction_notice_deemed), not silently and not as the charge_notice_damage bill' );
$ba_notice = creature_demo_last_mail( 'deduction_notice_deemed' );
creature_demo_expect( false !== strpos( $ba_notice['body'], 'Amount: £15.00.' ) && false !== strpos( $ba_notice['body'], 'Link: []' ) && false === stripos( $ba_notice['body'], 'http' ) && false === stripos( $ba_notice['body'], 'saved card' ), 'D3: a filled deduction notice has no pay link and no saved-card wording' );
creature_demo_expect( $ba_requests + 1 === count( WC_Stripe_API::$requests ) && 17300 === (int) WC_Stripe_API::$requests[ $ba_requests ]['body']['amount'], 'only the undisputed amount goes to Stripe now' );
$refunded_again = Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-10' );
creature_demo_expect( empty( $refunded_again['ok'] ) && 'deduction_pending' === $refunded_again['reason'] && 'yes' !== $back_after->get_meta( '_creature_demo_refund_done' ), 'the deduction waits for the 7 days to pass' );
creature_demo_expect( $ba_requests + 1 === count( WC_Stripe_API::$requests ), 'waiting sends nothing to Stripe' );
creature_demo_expect( 'charge_netted' === Creature_Demo_Loan::charge_saved_card( $ba_charge, '2026-04-20' )['code'], 'the saved card is never charged for a deduction' );
$ba_settled = Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-16' );
creature_demo_expect( ! empty( $ba_settled['ok'] ) && 17300 === $ba_settled['amount'] && 1500 === $ba_settled['deducted'] && 'yes' === $back_after->get_meta( '_creature_demo_refund_done' ), 'after 7 days with no dispute the £15 is deducted and the refund is done' );
creature_demo_expect( 'cancelled' === $ba_charge->get_status() && $ba_requests + 1 === count( WC_Stripe_API::$requests ), 'the notice link is closed and nothing more goes to Stripe' );
creature_demo_expect( ! empty( Creature_Demo_Loan::refund_deemed( $back_after, '2026-04-17' )['idempotent'] ), 'the refund is recorded once' );
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
creature_demo_inspect( $never_keep, '2026-04-08' );
$GLOBALS['creature_demo_refund_flag'] = false;
$still = Creature_Demo_Loan::refund_deemed( $never_keep, '2026-04-09' );
$GLOBALS['creature_demo_refund_flag'] = null;
creature_demo_expect( ! empty( $still['ok'] ) && 18800 === $still['amount'], 'with the flag off, a rider who never chose keep is still refunded' );

$failed_refund = creature_demo_loan();
$failed_raised = Creature_Demo_Loan::maybe_raise_deemed( $failed_refund, '2026-04-07' );
$failed_charge = wc_get_order( $failed_raised['order_id'] );
$failed_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_fail' );
Creature_Demo_Loan::on_payment_complete( $failed_raised['order_id'] );
Creature_Demo_Loan::record_return_scan( $failed_refund, '2026-04-08', '2026-04-08' );
creature_demo_inspect( $failed_refund, '2026-04-08' );
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
creature_demo_inspect( $blocked_refund, '2026-04-08' );
$not_sent = Creature_Demo_Loan::refund_deemed( $blocked_refund, '2026-04-09' );
creature_demo_expect( empty( $not_sent['ok'] ) && 'not_sent' === $not_sent['reason'], 'a refund is not marked done when the mode gate blocks Stripe' );
creature_demo_expect( 'yes' !== $blocked_refund->get_meta( '_creature_demo_refund_done' ), 'a blocked refund can be tried again' );
$GLOBALS['creature_demo_options']['woocommerce_stripe_settings']['testmode'] = 'yes';

// Item 4, terms 8.5(b) and (c): a damage or missing-parts deduction from the deemed refund goes through the bill path.
$GLOBALS['creature_demo_refund_flag'] = true;
WC_Stripe_API::$response = (object) array( 'id' => 're_test_item4', 'status' => 'succeeded' );
$d1 = creature_demo_paid_deemed_returned();
$d1->update_meta_data( '_creature_demo_damage_pence', 1500 );
$d1->update_meta_data( '_creature_demo_findings', '' );
$d1_req   = count( WC_Stripe_API::$requests );
$d1_mails = creature_demo_charge_mail_count();
$d1_r     = Creature_Demo_Loan::refund_deemed( $d1, '2026-04-09' );
creature_demo_expect( empty( $d1_r['ok'] ) && 'no_findings' === $d1_r['reason'], '8.5(b): a deduction with no findings is refused' );
creature_demo_expect( $d1_req === count( WC_Stripe_API::$requests ) && $d1_mails === creature_demo_charge_mail_count() && '' === $d1->get_meta( '_creature_demo_deduction_state' ) && '' === $d1->get_meta( '_creature_demo_damage_order_id' ) && 'yes' !== $d1->get_meta( '_creature_demo_refund_done' ), 'a refused deduction sends no refund and no bill, and changes nothing' );
$d1->update_meta_data( '_creature_demo_findings', 'Cracked bar clamp.' );
$d1->update_meta_data( '_creature_demo_photos', array() );
$d1_r2 = Creature_Demo_Loan::refund_deemed( $d1, '2026-04-09' );
creature_demo_expect( empty( $d1_r2['ok'] ) && 'no_photos' === $d1_r2['reason'] && $d1_req === count( WC_Stripe_API::$requests ) && $d1_mails === creature_demo_charge_mail_count() && '' === $d1->get_meta( '_creature_demo_deduction_state' ), 'a deduction with findings but no photo is refused too' );
$d1->update_meta_data( '_creature_demo_damage_pence', 0 );
$d1_r3 = Creature_Demo_Loan::refund_deemed( $d1, '2026-04-09' );
creature_demo_expect( ! empty( $d1_r3['ok'] ) && 18800 === $d1_r3['amount'] && 'yes' === $d1->get_meta( '_creature_demo_refund_done' ), 'clearing the deduction lets the refund through in full (£188 after the rest of the £24)' );
// Disputed, then upheld in part.
$d2 = creature_demo_paid_deemed_returned();
$d2->update_meta_data( '_creature_demo_damage_pence', 1500 );
$d2_r = Creature_Demo_Loan::refund_deemed( $d2, '2026-04-09' );
$d2_charge = wc_get_order( (int) $d2->get_meta( '_creature_demo_damage_order_id' ) );
creature_demo_expect( ! empty( $d2_r['pending'] ) && 17300 === $d2_r['amount'], 'the undisputed £173 is refunded and £15 held' );
Creature_Demo_Loan::mark_disputed( $d2_charge, '2026-04-11' );
$d2_wait = Creature_Demo_Loan::refund_deemed( $d2, '2026-04-30' );
creature_demo_expect( empty( $d2_wait['ok'] ) && 'deduction_disputed' === $d2_wait['reason'] && 'yes' !== $d2->get_meta( '_creature_demo_refund_done' ), 'while a dispute is open the held amount stays held, however long it takes' );
Creature_Demo_Loan::record_dispute_decision( $d2_charge, 'upheld', '2026-04-20', 1000 );
$d2_wait2 = Creature_Demo_Loan::refund_deemed( $d2, '2026-04-22' );
creature_demo_expect( empty( $d2_wait2['ok'] ) && 'deduction_decision_wait' === $d2_wait2['reason'], 'an upheld decision waits 7 days from the decision email before it is settled' );
$d2_req  = count( WC_Stripe_API::$requests );
$d2_done = Creature_Demo_Loan::refund_deemed( $d2, '2026-04-27' );
creature_demo_expect( ! empty( $d2_done['ok'] ) && 1000 === $d2_done['deducted'] && 500 === $d2_done['released'] && 17800 === $d2_done['amount'], 'a partly upheld dispute deducts £10 and releases the other £5' );
creature_demo_expect( $d2_req + 1 === count( WC_Stripe_API::$requests ) && 500 === (int) WC_Stripe_API::$requests[ $d2_req ]['body']['amount'] && 'yes' === $d2->get_meta( '_creature_demo_refund_done' ), 'the released £5 goes to Stripe once and the refund is done' );
// Disputed, then waived: all of it comes back.
$d3 = creature_demo_paid_deemed_returned();
$d3->update_meta_data( '_creature_demo_damage_pence', 1500 );
Creature_Demo_Loan::refund_deemed( $d3, '2026-04-09' );
$d3_charge = wc_get_order( (int) $d3->get_meta( '_creature_demo_damage_order_id' ) );
Creature_Demo_Loan::mark_disputed( $d3_charge, '2026-04-10' );
Creature_Demo_Loan::record_dispute_decision( $d3_charge, 'waived', '2026-04-12', null );
$d3_done = Creature_Demo_Loan::refund_deemed( $d3, '2026-04-12' );
creature_demo_expect( ! empty( $d3_done['ok'] ) && 0 === $d3_done['deducted'] && 18800 === $d3_done['amount'], 'a waived dispute releases the whole £15' );
// The rider pays the notice link instead: the held amount comes back.
$d4 = creature_demo_paid_deemed_returned();
$d4->update_meta_data( '_creature_demo_damage_pence', 1500 );
Creature_Demo_Loan::refund_deemed( $d4, '2026-04-09' );
$d4_charge = wc_get_order( (int) $d4->get_meta( '_creature_demo_damage_order_id' ) );
Creature_Demo_Loan::on_charge_paid( $d4, $d4_charge );
$d4_done = Creature_Demo_Loan::refund_deemed( $d4, '2026-04-10' );
creature_demo_expect( ! empty( $d4_done['ok'] ) && 0 === $d4_done['deducted'] && 18800 === $d4_done['amount'], 'a rider who pays the deduction notice is not deducted twice: the held £15 is released' );
// D2, terms 6.4(b): the deduction has to be raised within 5 working days of the entered Kit received date.
// Received Wed 2026-04-08: day 4 is Tue 04-14, day 5 is Wed 04-15.
$d5 = creature_demo_paid_deemed_returned( '2026-04-08' );
$d5->update_meta_data( '_creature_demo_damage_pence', 1500 );
$d5_mails = creature_demo_charge_mail_count();
$d5_done  = Creature_Demo_Loan::refund_deemed( $d5, '2026-04-16' );
creature_demo_expect( ! empty( $d5_done['ok'] ) && ! empty( $d5_done['deduction_blocked'] ) && 18800 === $d5_done['amount'], 'D2: a deduction raised on working day 6 after the Kit received date is refused: the refund goes ahead without it' );
creature_demo_expect( '' === $d5->get_meta( '_creature_demo_damage_order_id' ) && $d5_mails === creature_demo_charge_mail_count() && false !== strpos( implode( "\n", $d5->notes ), 'refused' ), 'D2: no bill is sent for a refused deduction, and the order says why' );
$d6 = creature_demo_paid_deemed_returned( '2026-04-08' );
$d6->update_meta_data( '_creature_demo_damage_pence', 1500 );
$d6_r = Creature_Demo_Loan::refund_deemed( $d6, '2026-04-15' );
creature_demo_expect( ! empty( $d6_r['pending'] ) && empty( $d6_r['deduction_blocked'] ), 'D2: working day 5 itself is still in time' );
// The old 30-days-from-posting window is gone: a kit posted weeks ago but received on 04-08 is judged on the received date.
$d6b = creature_demo_paid_deemed_returned( '2026-04-08' );
$d6b->update_meta_data( '_creature_demo_damage_pence', 1500 );
$d6b->update_meta_data( '_creature_demo_received_date', '2026-05-04' );
$d6b_r = Creature_Demo_Loan::refund_deemed( $d6b, '2026-05-08' );
creature_demo_expect( ! empty( $d6b_r['pending'] ) && empty( $d6b_r['deduction_blocked'] ), 'D2: the window runs from the received date, not from the posting date (no 30-day window)' );
// No Kit received date: no deduction at all.
$d8 = creature_demo_paid_deemed_returned( '2026-04-08' );
$d8->update_meta_data( '_creature_demo_received_date', '' );
$d8->update_meta_data( '_creature_demo_damage_pence', 1500 );
$d8_req   = count( WC_Stripe_API::$requests );
$d8_mails = creature_demo_charge_mail_count();
$d8_r     = Creature_Demo_Loan::refund_deemed( $d8, '2026-04-09' );
creature_demo_expect( empty( $d8_r['ok'] ) && 'no_received_date' === $d8_r['reason'], 'D2: a deduction with no entered Kit received date is refused' );
creature_demo_expect( $d8_req === count( WC_Stripe_API::$requests ) && $d8_mails === creature_demo_charge_mail_count() && '' === $d8->get_meta( '_creature_demo_deduction_state' ) && 'yes' !== $d8->get_meta( '_creature_demo_refund_done' ), 'D2: nothing is refunded, billed or held without the received date' );
$d8->update_meta_data( '_creature_demo_damage_pence', 0 );
creature_demo_expect( ! empty( Creature_Demo_Loan::refund_deemed( $d8, '2026-04-09' )['ok'] ), 'D2: with no deduction the refund needs no received date' );
$d8b = creature_demo_paid_deemed_returned( '2026-04-08' );
$d8b->update_meta_data( '_creature_demo_received_date', '' );
$d8b->update_meta_data( '_creature_demo_damage_pence', 1500 );
Creature_Demo_Loan::refund_deemed( $d8b, '2026-04-09' );
Creature_Demo_Loan::record_kit_received( $d8b, '2026-04-08' );
creature_demo_expect( ! empty( Creature_Demo_Loan::refund_deemed( $d8b, '2026-04-09' )['pending'] ), 'D2: once the date is entered the deduction goes ahead' );
// Day-4 alert, now sent for a paid deemed kit too (it used to be skipped).
$d9 = creature_demo_paid_deemed_returned( '2026-04-08' );
$d9_warns = creature_demo_mail_count( 'admin_bill_due' );
$d9_mails = creature_demo_charge_mail_count();
Creature_Demo_Loan::tick( $d9, '2026-04-13' );
creature_demo_expect( $d9_warns === creature_demo_mail_count( 'admin_bill_due' ), 'D2: no alert before working day 4 on a paid deemed kit' );
Creature_Demo_Loan::tick( $d9, '2026-04-14' );
Creature_Demo_Loan::tick( $d9, '2026-04-14' );
creature_demo_expect( $d9_warns + 1 === creature_demo_mail_count( 'admin_bill_due' ), 'D2: a paid deemed kit gets the day-4 deadline alert, once' );
Creature_Demo_Loan::tick( $d9, '2026-04-15' );
Creature_Demo_Loan::tick( $d9, '2026-04-20' );
creature_demo_expect( $d9_warns + 1 === creature_demo_mail_count( 'admin_bill_due' ) && $d9_mails === creature_demo_charge_mail_count() && '' === $d9->get_meta( '_creature_demo_bill_state' ), 'D2: the alert is not repeated and no fallback bill is sent for a deemed kit' );
// An unpaid deemed purchase that was cancelled when the kit came back (8.5(a)) is warned too.
$d10 = creature_demo_loan();
Creature_Demo_Loan::maybe_raise_deemed( $d10, '2026-04-07' );
Creature_Demo_Loan::record_return_scan( $d10, '2026-04-08', '2026-04-08' );
Creature_Demo_Loan::record_kit_received( $d10, '2026-04-08' );
creature_demo_expect( 'demo-inspect' === $d10->get_status(), 'setup: an unpaid deemed kit that came back is awaiting inspection' );
$d10_warns = creature_demo_mail_count( 'admin_bill_due' );
Creature_Demo_Loan::tick( $d10, '2026-04-14' );
creature_demo_expect( $d10_warns + 1 === creature_demo_mail_count( 'admin_bill_due' ), 'D2: an unpaid deemed kit that came back is in the day-4 alert too' );
Creature_Demo_Loan::tick( $d10, '2026-04-16' );
creature_demo_expect( 'late_only' === $d10->get_meta( '_creature_demo_bill_state' ), 'D2: its late-return bill fallback on day 5 is unchanged' );
// The settle alert: decision time plus 7 days, once.
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array( 'subject' => 'Test deduction for {order_id}', 'body' => 'Deduction {amount}.' );
$s1 = creature_demo_paid_deemed_returned( '2026-04-08' );
$s1->update_meta_data( '_creature_demo_damage_pence', 1500 );
Creature_Demo_Loan::refund_deemed( $s1, '2026-04-09' );
$s1_set = creature_demo_mail_count( 'admin_deduction_settle_due' );
Creature_Demo_Loan::tick( $s1, '2026-04-15' );
creature_demo_expect( $s1_set === creature_demo_mail_count( 'admin_deduction_settle_due' ), 'D2: no settle alert before 7 days after the notice' );
Creature_Demo_Loan::tick( $s1, '2026-04-16' );
Creature_Demo_Loan::tick( $s1, '2026-04-17' );
$s1_mail = creature_demo_last_mail( 'admin_deduction_settle_due' );
creature_demo_expect( $s1_set + 1 === creature_demo_mail_count( 'admin_deduction_settle_due' ) && 'info@creaturecycles.co.uk' === $s1_mail['to'] && false !== strpos( $s1_mail['body'], 're-run Refund deemed purchase to settle the deduction' ), 'D2: one shop alert 7 days after the notice says to re-run Refund deemed purchase' );
$s2 = creature_demo_paid_deemed_returned( '2026-04-08' );
$s2->update_meta_data( '_creature_demo_damage_pence', 1500 );
Creature_Demo_Loan::refund_deemed( $s2, '2026-04-09' );
$s2_charge = wc_get_order( (int) $s2->get_meta( '_creature_demo_damage_order_id' ) );
Creature_Demo_Loan::mark_disputed( $s2_charge, '2026-04-11' );
$s2_set = creature_demo_mail_count( 'admin_deduction_settle_due' );
Creature_Demo_Loan::tick( $s2, '2026-04-30' );
creature_demo_expect( $s2_set === creature_demo_mail_count( 'admin_deduction_settle_due' ), 'D2: no settle alert while the dispute is open' );
Creature_Demo_Loan::record_dispute_decision( $s2_charge, 'upheld', '2026-04-20', 1000 );
Creature_Demo_Loan::tick( $s2, '2026-04-26' );
creature_demo_expect( $s2_set === creature_demo_mail_count( 'admin_deduction_settle_due' ), 'D2: no settle alert 6 days after the decision' );
Creature_Demo_Loan::tick( $s2, '2026-04-27' );
Creature_Demo_Loan::tick( $s2, '2026-04-28' );
creature_demo_expect( $s2_set + 1 === creature_demo_mail_count( 'admin_deduction_settle_due' ), 'D2: one settle alert 7 days after the decision' );
Creature_Demo_Loan::refund_deemed( $s2, '2026-04-27' );
Creature_Demo_Loan::tick( $s2, '2026-05-30' );
creature_demo_expect( $s2_set + 1 === creature_demo_mail_count( 'admin_deduction_settle_due' ), 'D2: nothing more once the deduction is settled' );
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array(
	'subject' => '',
	'body'    => '',
);
// 8.5(c): posted more than 30 days after the payment, the kit is the rider's.
$d7 = creature_demo_paid_deemed_returned( '2026-04-08' );
$d7->update_meta_data( '_creature_demo_deemed_paid_at', '2026-03-01' );
$d7_r = Creature_Demo_Loan::refund_deemed( $d7, '2026-04-09' );
creature_demo_expect( empty( $d7_r['ok'] ) && 'after_30_days' === $d7_r['reason'] && 'yes' !== $d7->get_meta( '_creature_demo_refund_done' ), '8.5(c): a kit posted more than 30 days after payment is not refunded' );
// D3: while deduction_notice_deemed is empty the deduction notice is blocked, and the shop is told.
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array(
	'subject' => '',
	'body'    => '',
);
$e1 = creature_demo_paid_deemed_returned();
$e1->update_meta_data( '_creature_demo_damage_pence', 1500 );
$e1_req    = count( WC_Stripe_API::$requests );
$e1_mails  = creature_demo_charge_mail_count();
$e1_admin  = creature_demo_mail_count( 'admin_deduction_notice_missing' );
$e1_r      = Creature_Demo_Loan::refund_deemed( $e1, '2026-04-09' );
creature_demo_expect( empty( $e1_r['ok'] ) && 'no_deduction_wording' === $e1_r['reason'], 'D3: with deduction_notice_deemed empty the deduction is refused' );
creature_demo_expect( $e1_mails === creature_demo_charge_mail_count() && $e1_req === count( WC_Stripe_API::$requests ) && '' === $e1->get_meta( '_creature_demo_damage_order_id' ) && '' === $e1->get_meta( '_creature_demo_deduction_state' ) && 'yes' !== $e1->get_meta( '_creature_demo_refund_done' ), 'D3: nothing is sent, created, refunded or held while it is empty' );
creature_demo_expect( $e1_admin + 1 === creature_demo_mail_count( 'admin_deduction_notice_missing' ) && false !== strpos( implode( "\n", $e1->notes ), 'deduction_notice_deemed' ), 'D3: the shop is emailed and the order note says why' );
Creature_Demo_Loan::refund_deemed( $e1, '2026-04-10' );
creature_demo_expect( $e1_admin + 1 === creature_demo_mail_count( 'admin_deduction_notice_missing' ), 'D3: the shop email is sent once, not on every retry' );
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array( 'subject' => 'Test deduction for {order_id}', 'body' => 'Deduction {amount}. Link: [{link}]' );
$e1_dmg = creature_demo_mail_count( 'charge_notice_damage' );
$e1_ok  = Creature_Demo_Loan::refund_deemed( $e1, '2026-04-11' );
creature_demo_expect( ! empty( $e1_ok['pending'] ) && 1500 === $e1_ok['held'] && 'deduction_notice_deemed' === creature_demo_last_charge_slot(), 'D3: once the wording is filled the notice sends and the refund goes ahead' );
$e1_mail = creature_demo_last_mail( 'deduction_notice_deemed' );
creature_demo_expect( $e1_dmg === creature_demo_mail_count( 'charge_notice_damage' ) && 'Deduction £15.00. Link: []' === $e1_mail['body'], 'D3: the deduction does not reuse charge_notice_damage, and its notice has no pay link' );
// E3: the decision on a disputed deemed-refund deduction is its own slot, dispute_decision_deemed, with no pay link.
$GLOBALS['creature_demo_copy_override']['dispute_decision_deemed'] = array(
	'subject' => '',
	'body'    => '',
);
$e3_charge = wc_get_order( (int) $e1->get_meta( '_creature_demo_damage_order_id' ) );
Creature_Demo_Loan::mark_disputed( $e3_charge, '2026-04-12' );
$e3_plain   = creature_demo_mail_count( 'dispute_decision' );
$e3_deemed  = creature_demo_mail_count( 'dispute_decision_deemed' );
$e3_admin   = creature_demo_mail_count( 'admin_dispute_decision_missing' );
$e3_blocked = Creature_Demo_Loan::record_dispute_decision( $e3_charge, 'upheld', '2026-04-14', null );
creature_demo_expect( empty( $e3_blocked['ok'] ) && 'no_decision_wording' === $e3_blocked['reason'], 'E3: with dispute_decision_deemed empty the decision is blocked' );
creature_demo_expect( $e3_plain === creature_demo_mail_count( 'dispute_decision' ) && $e3_deemed === creature_demo_mail_count( 'dispute_decision_deemed' ), 'E3: blocked: no decision email of either kind goes out (never the plain one with a pay link)' );
creature_demo_expect( 'open' === $e3_charge->get_meta( '_creature_demo_dispute_status' ) && '' === $e3_charge->get_meta( '_creature_demo_decision_sent_at' ), 'E3: blocked: the dispute stays open and no 7-day clock starts' );
creature_demo_expect( $e3_admin + 1 === creature_demo_mail_count( 'admin_dispute_decision_missing' ) && false !== strpos( implode( "\n", $e3_charge->notes ), 'dispute_decision_deemed' ), 'E3: blocked: the shop is emailed and the order note says why' );
Creature_Demo_Loan::record_dispute_decision( $e3_charge, 'upheld', '2026-04-14', null );
creature_demo_expect( $e3_admin + 1 === creature_demo_mail_count( 'admin_dispute_decision_missing' ), 'E3: blocked: the shop email is sent once, not on every retry' );
$GLOBALS['creature_demo_copy_override']['dispute_decision_deemed'] = array(
	'subject' => 'Test decision for {order_id}',
	'body'    => 'Decision {decision}. Amount {amount}. Link: [{link}] Price {price}.',
);
$e3_ok = Creature_Demo_Loan::record_dispute_decision( $e3_charge, 'upheld', '2026-04-14', null );
creature_demo_expect( ! empty( $e3_ok['ok'] ) && 'upheld' === $e3_charge->get_meta( '_creature_demo_dispute_status' ), 'E3: once the wording is filled the decision is recorded' );
$e3_mail = creature_demo_last_mail( 'dispute_decision_deemed' );
creature_demo_expect( $e3_deemed + 1 === creature_demo_mail_count( 'dispute_decision_deemed' ) && $e3_plain === creature_demo_mail_count( 'dispute_decision' ), 'E3: filled: the deduction decision goes out as dispute_decision_deemed, not dispute_decision' );
creature_demo_expect( 'Decision upheld. Amount £15.00. Link: [] Price £212.00.' === $e3_mail['body'] && false === stripos( $e3_mail['body'], 'http' ), 'E3: filled: the decision has no pay link, even though the test body has a {link} tag' );
$GLOBALS['creature_demo_copy_override']['dispute_decision_deemed'] = creature_demo_empty_copy_baseline()['dispute_decision_deemed'];
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array(
	'subject' => '',
	'body'    => '',
);
$GLOBALS['creature_demo_refund_flag'] = null;

$damage = creature_demo_loan();
$mount = creature_demo_damage( $damage, 3500, 'Inverted mount kit', '2026-03-20' );
$mount_again = creature_demo_damage( $damage, 3500, 'Inverted mount kit', '2026-03-20' );
creature_demo_expect( ! empty( $mount['ok'] ) && 3500 === $mount['amount'], 'a missing mount kit is £35' );
creature_demo_expect( $mount['order_id'] === $mount_again['order_id'], 'the damage charge is not duplicated' );
$damage_mail = creature_demo_last_mail( 'charge_notice_damage' );
creature_demo_expect( false !== strpos( $damage_mail['body'], '£35.00' ), 'the damage notice names £35' );
$same_day = Creature_Demo_Loan::charge_saved_card( $damage, '2026-03-20' );
creature_demo_expect( 'notice_too_recent' === $same_day['code'], 'the damage notice still waits 7 days' );
creature_demo_expect( false !== strpos( $damage_mail['body'], '£35.00' ) && false !== strpos( $damage_mail['body'], 'inverted mount kit' ), 'the damage notice names the mount kit from the constant' );
$capped = creature_demo_loan();
$cap = creature_demo_damage( $capped, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 21200 === $cap['amount'], 'damage with no late fees is capped at £212' );
$fee24 = wc_create_order();
$fee24->update_meta_data( '_creature_demo_charge_amount_pence', 2400 );
$fee24->save();
$with24 = creature_demo_loan();
$with24->update_meta_data( '_creature_demo_late_fee_order_id', $fee24->get_id() );
$with24->save();
$room24 = creature_demo_damage( $with24, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( ! empty( $room24['ok'] ) && 18800 === $room24['amount'], '£24 of late fees leaves £188 of damage' );
creature_demo_expect( ! empty( $room24['clamped'] ) && 11200 === $room24['reduced_pence'], 'a damage request above that headroom is clamped' );
creature_demo_expect( '2400' === (string) $fee24->get_meta( '_creature_demo_charge_amount_pence' ), 'clamping damage leaves the late fee as billed' );
$fee9 = wc_create_order();
$fee9->update_meta_data( '_creature_demo_charge_amount_pence', 900 );
$fee9->save();
$with9 = creature_demo_loan();
$with9->update_meta_data( '_creature_demo_late_fee_order_id', $fee9->get_id() );
$with9->save();
$room9 = creature_demo_damage( $with9, 3500, 'Inverted mount kit', '2026-03-20' );
creature_demo_expect( ! empty( $room9['ok'] ) && 3500 === $room9['amount'] && empty( $room9['clamped'] ), '£9 of late fees still leaves room for the £35 mount kit' );
$with9_over = creature_demo_loan();
$with9_over->update_meta_data( '_creature_demo_late_fee_order_id', $fee9->get_id() );
$with9_over->save();
$room9_over = creature_demo_damage( $with9_over, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 20300 === $room9_over['amount'] && ! empty( $room9_over['clamped'] ), '£9 of late fees leaves £203 of damage' );
creature_demo_expect( '900' === (string) $fee9->get_meta( '_creature_demo_charge_amount_pence' ), 'the £9 late fee stays as billed' );
$zero_loan = creature_demo_loan();
$zero = creature_demo_damage( $zero_loan, 0, 'Damage', '2026-03-20' );
creature_demo_expect( empty( $zero['ok'] ) && 'no_amount' === $zero['reason'], 'a zero damage amount does not create a charge' );
creature_demo_expect( '' === $zero_loan->get_meta( '_creature_demo_bill_state' ), 'a zero amount does not close the bill' );
$capped_again = creature_demo_damage( $capped, 100, 'Damage', '2026-03-21' );
creature_demo_expect( ! empty( $capped_again['idempotent'] ) && $cap['order_id'] === $capped_again['order_id'], 'a second damage call returns the same bill and does not raise another' );

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

// Item D, terms 3A.3: a reported crash hides the keep choice until the inspection is recorded.
$cr = creature_demo_loan( '2026-10-26', '2026-10-27' );
$GLOBALS['creature_demo_user'] = 42;
creature_demo_expect( true === Creature_Demo_Loan::choice_allowed( $cr, 'keep', '2026-11-01' ) && false === Creature_Demo_Loan::crash_hold( $cr ), 'before a crash is flagged, keep is open' );
$_POST = array(
	'creature_demo_loan_admin_nonce' => 'creature_demo_loan_admin',
	'creature_demo_flags_present'    => '1',
	'creature_demo_crash_reported'   => '1',
);
Creature_Demo_Loan::save_meta_box( $cr->get_id(), $cr );
$_POST = array();
creature_demo_expect( 'yes' === $cr->get_meta( '_creature_demo_crash_reported' ) && true === Creature_Demo_Loan::crash_hold( $cr ) && false !== strpos( implode( "\n", $cr->notes ), '3A.3' ), 'the admin checkbox sets the crash flag and notes it' );
creature_demo_expect( false === Creature_Demo_Loan::choice_allowed( $cr, 'keep', '2026-11-01' ) && true === Creature_Demo_Loan::choice_allowed( $cr, 'return', '2026-11-01' ), 'the keep choice is closed, return is not' );
$cr_try = Creature_Demo_Loan::choose( $cr, 'keep', '2026-11-01' );
creature_demo_expect( empty( $cr_try['ok'] ) && 'crash_hold' === $cr_try['reason'] && '' === $cr->get_meta( '_creature_demo_choice' ), 'choosing keep is refused' );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $cr );
$cr_account = ob_get_clean();
creature_demo_expect( false === strpos( $cr_account, 'creature_demo_choice=keep' ) && false !== strpos( $cr_account, 'creature_demo_choice=return' ), 'My Account hides the keep link and still shows return' );
$cr_reminder_before = creature_demo_mail_count( 'reminder_choices_due_minus_5' );
Creature_Demo_Loan::tick( $cr, '2026-11-11' );
$cr_mail = creature_demo_last_mail( 'reminder_choices_due_minus_5' );
creature_demo_expect( creature_demo_mail_count( 'reminder_choices_due_minus_5' ) > $cr_reminder_before && false === strpos( $cr_mail['body'], 'Keep it for your keep price:' ) && false !== strpos( $cr_mail['body'], 'Return it' ), 'the emailed choice links leave out keep' );
// Keep already chosen, crash reported afterwards: the keep payment is refused.
$cr2 = creature_demo_loan( '2026-10-26', '2026-10-27' );
Creature_Demo_Loan::choose( $cr2, 'keep', '2026-11-01' );
$cr2_charge = wc_get_order( (int) $cr2->get_meta( '_creature_demo_keep_order_id' ) );
creature_demo_expect( true === Creature_Demo_Loan::filter_needs_payment( true, $cr2_charge ) && true === Creature_Demo_Loan::begin_pay_link( $cr2_charge )['ok'], 'with no crash the keep link can be paid' );
Creature_Demo_Loan::on_pay_link_failed( $cr2_charge->get_id(), $cr2_charge );
$cr2->update_meta_data( '_creature_demo_crash_reported', 'yes' );
$cr2_pay = Creature_Demo_Loan::begin_pay_link( $cr2_charge );
creature_demo_expect( empty( $cr2_pay['ok'] ) && 'crash_hold' === $cr2_pay['code'] && false === Creature_Demo_Loan::filter_needs_payment( true, $cr2_charge ), 'a flagged loan: the keep payment is refused and the link no longer needs payment' );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $cr2 );
creature_demo_expect( false === strpos( ob_get_clean(), 'creature_demo_choice=keep' ), 'a keep that was chosen is hidden too while the hold lasts' );
creature_demo_expect( 'Charge refused.' === Creature_Demo_Loan::refusal_message( 'crash_hold' ), 'the rider-facing refusal has no invented wording (slot is empty)' );
// Inspection recorded: the hold ends.
Creature_Demo_Loan::record_return_scan( $cr2, '2026-11-02', '2026-11-02' );
creature_demo_inspect( $cr2, '2026-11-02' );
creature_demo_expect( false === Creature_Demo_Loan::crash_hold( $cr2 ) && 'yes' === $cr2->get_meta( '_creature_demo_crash_reported' ), 'once the inspection is recorded the hold is lifted (the report stays on file)' );
// Unticking clears it.
$_POST = array( 'creature_demo_loan_admin_nonce' => 'creature_demo_loan_admin', 'creature_demo_flags_present' => '1' );
Creature_Demo_Loan::save_meta_box( $cr->get_id(), $cr );
$_POST = array();
creature_demo_expect( false === Creature_Demo_Loan::crash_hold( $cr ) && true === Creature_Demo_Loan::choice_allowed( $cr, 'keep', '2026-11-01' ), 'unticking the box reopens keep' );
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
$cancel_charge = creature_demo_bill_charge( $cancel_loan );
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
$race_charge = creature_demo_bill_charge( $race );
$pay_first   = Creature_Demo_Loan::begin_pay_link( $race_charge );
creature_demo_expect( ! empty( $pay_first['ok'] ) && 'pay_link' === creature_demo_lock_owner( $race_charge ), 'the pay link takes the charge lock' );
$race_calls   = count( WC_Stripe_API::$requests );
$race_refused = Creature_Demo_Loan::charge_saved_card( $race, '2026-04-06' );
creature_demo_expect( 'payment_in_progress' === $race_refused['code'], 'a pay link in progress refuses the saved card' );
creature_demo_expect( $race_calls === count( WC_Stripe_API::$requests ), 'a pay link in progress does not call Stripe' );
creature_demo_expect( 'pay_link' === creature_demo_lock_owner( $race_charge ), 'the refusal leaves the pay-link lock in place' );

$upe = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $upe, '2026-03-30', '2026-03-30' );
$upe_charge = creature_demo_bill_charge( $upe );
$upe_charge->update_meta_data( '_stripe_intent_id', 'pi_test_upe' );
$upe_charge->update_meta_data( '_stripe_intent_status', 'requires_payment_method' );
$upe_charge->save();
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_upe',
	'status' => 'processing',
);
$upe_calls   = count( WC_Stripe_API::$requests );
$upe_refused = Creature_Demo_Loan::charge_saved_card( $upe, '2026-04-06' );
$upe_posts   = 0;
$upe_retrieve = 0;
foreach ( array_slice( WC_Stripe_API::$requests, $upe_calls ) as $upe_request ) {
	if ( 'POST' === $upe_request['method'] && 'payment_intents' === $upe_request['endpoint'] ) {
		$upe_posts++;
	}
	if ( isset( $upe_request['via'] ) && 'retrieve' === $upe_request['via'] ) {
		$upe_retrieve++;
	}
}
creature_demo_expect( 'payment_in_progress' === $upe_refused['code'], 'a processing PaymentIntent refuses the saved card' );
creature_demo_expect( 0 === $upe_posts, 'a processing PaymentIntent does not create a charge' );
creature_demo_expect( $upe_retrieve > 0, 'the PaymentIntent status is read with WC_Stripe_API::retrieve' );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);
creature_demo_expect( 'pending' === $upe_charge->get_status(), 'the in-progress pay link leaves the order pending' );

$done_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $done_link, '2026-03-30', '2026-03-30' );
$done_charge = creature_demo_bill_charge( $done_link );
$done_charge->payment_complete( 'pi_test_paylink' );
$done_calls   = count( WC_Stripe_API::$requests );
$done_refused = Creature_Demo_Loan::charge_saved_card( $done_link, '2026-04-06' );
creature_demo_expect( 'already_paid' === $done_refused['code'], 'a completed pay link refuses the saved card' );
creature_demo_expect( $done_calls === count( WC_Stripe_API::$requests ), 'a completed pay link does not call Stripe again' );
creature_demo_expect( '300' === (string) $done_link->get_meta( '_creature_demo_late_fees_paid_pence' ), 'the pay link records the late fee once' );

$held = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $held, '2026-03-30', '2026-03-30' );
$held_charge = creature_demo_bill_charge( $held );
$held_charge->update_meta_data( '_creature_demo_charge_lock', 'saved_card' );
$held_charge->update_meta_data( '_creature_demo_charge_lock_at', (string) time() );
$held_charge->save();
creature_demo_plant_lock( $held_charge, 'saved_card', Creature_Demo_Loan::charge_lock_now(), 'held-token' );
$held_pay = Creature_Demo_Loan::begin_pay_link( $held_charge );
creature_demo_expect( empty( $held_pay['ok'] ) && 'payment_in_progress' === $held_pay['code'], 'a saved-card lock refuses the pay link' );
creature_demo_expect( 'saved_card' === creature_demo_lock_owner( $held_charge ), 'the pay link does not replace the saved-card lock' );
creature_demo_expect( '' === (string) $held_charge->get_meta( '_creature_demo_charge_lock' ), 'old charge-lock meta is cleared on read' );
creature_demo_expect( '' === (string) $held_charge->get_meta( '_creature_demo_charge_lock_at' ), 'old charge-lock time meta is cleared on read' );
creature_demo_expect( false === Creature_Demo_Loan::filter_needs_payment( true, $held_charge ), 'the pay link cannot start while the saved card is charging' );
$GLOBALS['creature_demo_notices'] = array();
Creature_Demo_Loan::on_before_pay_action( $held_charge );
creature_demo_expect( ! empty( $GLOBALS['creature_demo_notices'] ), 'the pay action refuses while the saved card is charging' );

$flip = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $flip, '2026-03-30', '2026-03-30' );
$flip_charge = creature_demo_bill_charge( $flip );
$flip_second = true;
$flip_hook   = static function ( $order ) use ( $flip_charge, &$flip_second ) {
	if ( is_object( $order ) && (int) $order->get_id() === (int) $flip_charge->get_id() ) {
		$order->update_meta_data( '_creature_demo_charge_lock', 'pay_link' );
		$order->save();
		$flip_second = Creature_Demo_Loan::acquire_charge_lock( $order, 'pay_link' );
	}
};
add_action( 'creature_demo_loan_saved_card_lock_acquired', $flip_hook );
$flip_calls = count( WC_Stripe_API::$requests );
$flipped    = Creature_Demo_Loan::charge_saved_card( $flip, '2026-04-06' );
remove_action( 'creature_demo_loan_saved_card_lock_acquired', $flip_hook );
creature_demo_expect( false === $flip_second, 'a second acquire loses while the saved card holds the lock' );
creature_demo_expect( ! empty( $flipped['ok'] ), 'legacy lock meta does not steal the saved-card lock' );
creature_demo_expect( $flip_calls < count( WC_Stripe_API::$requests ), 'the saved card still calls Stripe when it holds the lock' );

$paid_mid = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $paid_mid, '2026-03-30', '2026-03-30' );
$paid_mid_charge = creature_demo_bill_charge( $paid_mid );
$paid_hook       = static function ( $order ) {
	$order->update_meta_data( '_creature_demo_charge_paid', 'yes' );
	$order->save();
};
add_action( 'creature_demo_loan_saved_card_lock_acquired', $paid_hook );
$paid_calls      = count( WC_Stripe_API::$requests );
$paid_mid_result = Creature_Demo_Loan::charge_saved_card( $paid_mid, '2026-04-06' );
remove_action( 'creature_demo_loan_saved_card_lock_acquired', $paid_hook );
creature_demo_expect( 'already_paid' === $paid_mid_result['code'], 'a pay link that completes during the lock is seen on the re-check' );
creature_demo_expect( $paid_calls === count( WC_Stripe_API::$requests ), 'a completed pay link seen on the re-check does not call Stripe' );
creature_demo_expect( false === creature_demo_lock_row( $paid_mid_charge ), 'the saved-card lock is released when the pay link already completed' );

$abandoned = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $abandoned, '2026-03-30', '2026-03-30' );
$abandoned_charge = creature_demo_bill_charge( $abandoned );
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
$abandoned_result = Creature_Demo_Loan::charge_saved_card( $abandoned, '2026-04-06' );
creature_demo_expect( 'stripe_error' === $abandoned_result['code'], 'a failed pay-link intent does not block the saved card' );
creature_demo_expect( $abandoned_calls < count( WC_Stripe_API::$requests ), 'a failed pay-link intent still reaches Stripe' );
creature_demo_expect( false === creature_demo_lock_row( $abandoned_charge ), 'a Stripe error releases the saved-card lock' );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);

creature_demo_expect( 900 === Creature_Demo_Loan::charge_lock_stale_seconds(), 'the pay-link lock goes stale after 15 minutes' );
$GLOBALS['creature_demo_lock_now'] = 1700000000;
$declined_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $declined_link, '2026-03-30', '2026-03-30' );
$declined_charge = creature_demo_bill_charge( $declined_link );
creature_demo_expect( ! empty( Creature_Demo_Loan::begin_pay_link( $declined_charge )['ok'] ), 'a declined-link test can open the pay link' );
$declined_lock = creature_demo_lock_row( $declined_charge );
creature_demo_expect( is_array( $declined_lock ) && 1700000000 === (int) $declined_lock['at'] && 'pay_link' === $declined_lock['owner'], 'the pay-link lock stores the owner and the time it was taken' );
do_action( 'woocommerce_order_status_failed', $declined_charge->get_id(), $declined_charge );
creature_demo_expect( false === creature_demo_lock_row( $declined_charge ), 'a failed pay-link payment releases the lock' );
$declined_posts = count( WC_Stripe_API::$requests );
$declined_result = Creature_Demo_Loan::charge_saved_card( $declined_link, '2026-04-06' );
creature_demo_expect( ! empty( $declined_result['ok'] ), 'a declined pay link does not block Charge saved card' );
creature_demo_expect( $declined_posts < count( WC_Stripe_API::$requests ), 'a declined pay link still lets the saved card call Stripe' );

$cancelled_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cancelled_link, '2026-03-30', '2026-03-30' );
$cancelled_charge = creature_demo_bill_charge( $cancelled_link );
Creature_Demo_Loan::begin_pay_link( $cancelled_charge );
do_action( 'woocommerce_order_status_cancelled', $cancelled_charge->get_id(), $cancelled_charge );
creature_demo_expect( false === creature_demo_lock_row( $cancelled_charge ), 'a cancelled pay-link payment releases the lock' );

$stripe_fail = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $stripe_fail, '2026-03-30', '2026-03-30' );
$stripe_fail_charge = creature_demo_bill_charge( $stripe_fail );
Creature_Demo_Loan::begin_pay_link( $stripe_fail_charge );
do_action( 'wc_gateway_stripe_process_payment_error', new WP_Error( 'card_declined', 'declined' ), $stripe_fail_charge );
creature_demo_expect( false === creature_demo_lock_row( $stripe_fail_charge ), 'a Stripe pay-link failure releases the lock' );

$left_link = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $left_link, '2026-03-30', '2026-03-30' );
$left_charge = creature_demo_bill_charge( $left_link );
Creature_Demo_Loan::begin_pay_link( $left_charge );
$left_fresh = count( WC_Stripe_API::$requests );
$left_now = Creature_Demo_Loan::charge_saved_card( $left_link, '2026-04-06' );
creature_demo_expect( 'payment_in_progress' === $left_now['code'], 'an abandoned pay link still blocks inside 15 minutes' );
creature_demo_expect( $left_fresh === count( WC_Stripe_API::$requests ), 'an abandoned pay link does not call Stripe inside 15 minutes' );
$GLOBALS['creature_demo_lock_now'] = 1700000000 + 899;
$left_almost = Creature_Demo_Loan::charge_saved_card( $left_link, '2026-04-06' );
creature_demo_expect( 'payment_in_progress' === $left_almost['code'], 'an abandoned pay link still blocks at 14 minutes 59 seconds' );
$GLOBALS['creature_demo_lock_now'] = 1700000000 + 900;
$left_later = Creature_Demo_Loan::charge_saved_card( $left_link, '2026-04-06' );
creature_demo_expect( ! empty( $left_later['ok'] ), 'an abandoned pay link goes stale after 15 minutes and the saved card is charged' );

$intent_statuses = array( 'succeeded', 'processing', 'requires_capture', 'requires_payment_method', 'canceled', 'requires_action', 'requires_confirmation' );
foreach ( $intent_statuses as $intent_status ) {
	foreach ( array( 0, 900 ) as $intent_age ) {
		$GLOBALS['creature_demo_lock_now'] = 1700000000;
		$intent_loan = creature_demo_loan();
		Creature_Demo_Loan::record_return_scan( $intent_loan, '2026-03-30', '2026-03-30' );
		$intent_charge = creature_demo_bill_charge( $intent_loan );
		$intent_charge->update_meta_data( '_stripe_intent_id', 'pi_test_lookup' );
		$intent_charge->save();
		creature_demo_plant_lock( $intent_charge, 'pay_link', 1700000000 - $intent_age, 'intent-' . $intent_status . '-' . $intent_age );
		WC_Stripe_API::$response = (object) array(
			'id'     => 'pi_test_lookup',
			'status' => $intent_status,
		);
		$intent_from = count( WC_Stripe_API::$requests );
		$intent_result = Creature_Demo_Loan::charge_saved_card( $intent_loan, '2026-04-06' );
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
	$fail_charge = creature_demo_bill_charge( $fail_loan );
	$fail_charge->update_meta_data( '_stripe_intent_id', 'pi_test_lookup_fail' );
	$fail_charge->update_meta_data( '_stripe_intent_status', 'processing' );
	$fail_charge->save();
	creature_demo_plant_lock( $fail_charge, 'pay_link', 1700000000 - $fail_age, 'fail-' . $fail_age );
	WC_Stripe_API::$response = new WP_Error( 'stripe_error', 'timeout' );
	$fail_from = count( WC_Stripe_API::$requests );
	$fail_result = Creature_Demo_Loan::charge_saved_card( $fail_loan, '2026-04-06' );
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

$meta_ignored = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $meta_ignored, '2026-03-30', '2026-03-30' );
$meta_ignored_charge = creature_demo_bill_charge( $meta_ignored );
$meta_ignored_charge->update_meta_data( '_stripe_intent_id', 'pi_test_meta_ignored' );
$meta_ignored_charge->update_meta_data( '_stripe_intent_status', 'processing' );
$meta_ignored_charge->save();
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_meta_ignored',
	'status' => 'requires_confirmation',
);
$meta_ignored_result = Creature_Demo_Loan::charge_saved_card( $meta_ignored, '2026-04-06' );
creature_demo_expect( 'payment_in_progress' !== $meta_ignored_result['code'] && 'already_paid' !== $meta_ignored_result['code'], 'intent status meta is ignored and requires_confirmation does not block without a fresh lock' );
WC_Stripe_API::$response = (object) array(
	'id'     => 'pi_test_demo_1',
	'status' => 'succeeded',
);

$overwrite_name = 'creature_demo_charge_lock_overwrite';
$GLOBALS['creature_demo_add_option_overwrite'] = true;
$overwrite_first  = add_option( $overwrite_name, array( 'owner' => 'saved_card', 'at' => Creature_Demo_Loan::charge_lock_now(), 'token' => 'first' ), '', 'no' );
$overwrite_second = add_option( $overwrite_name, array( 'owner' => 'pay_link', 'at' => Creature_Demo_Loan::charge_lock_now(), 'token' => 'second' ), '', 'no' );
$GLOBALS['creature_demo_add_option_overwrite'] = false;
creature_demo_expect( true === $overwrite_first && true === $overwrite_second, 'add_option with ON DUPLICATE KEY UPDATE returns true for both takers' );
creature_demo_expect( 'pay_link' === $GLOBALS['creature_demo_options'][ $overwrite_name ]['owner'], 'that add_option overwrites the first lock' );
unset( $GLOBALS['creature_demo_options'][ $overwrite_name ], $GLOBALS['creature_demo_option_raw'][ $overwrite_name ] );

$contender = wc_create_order();
$contender->update_meta_data( '_creature_demo_charge_order', 'yes' );
$contender->save();
$contender_name = Creature_Demo_Loan::charge_lock_option_name( $contender->get_id() );
wp_cache_set( 'notoptions', array( $contender_name => true, 'keep-me' => true ), 'options' );
wp_cache_set( 'alloptions', array( $contender_name => 'cached', 'siteurl' => 'https://example.test' ), 'options' );
wp_cache_set( $contender_name, 'cached-row', 'options' );
$cache_deletes_before = count( $GLOBALS['creature_demo_cache_deletes'] );
$first_token  = Creature_Demo_Loan::acquire_charge_lock( $contender, 'saved_card' );
$second_token = Creature_Demo_Loan::acquire_charge_lock( $contender, 'pay_link' );
creature_demo_expect( is_string( $first_token ) && '' !== $first_token, 'the first acquire wins the charge lock' );
creature_demo_expect( false === $second_token, 'a second acquire loses the charge lock' );
creature_demo_expect( 'saved_card' === creature_demo_lock_owner( $contender ), 'exactly one contender holds the lock when add_option would overwrite' );
$contender_inserted = false;
foreach ( $wpdb->inserts as $insert ) {
	if ( $contender_name === $insert['name'] ) {
		$contender_inserted = true;
		creature_demo_expect( false !== strpos( $insert['sql'], 'INSERT IGNORE' ), 'the charge lock is inserted with INSERT IGNORE' );
		creature_demo_expect( 'off' === $insert['autoload'], 'the charge lock option is not autoloaded' );
	}
}
creature_demo_expect( $contender_inserted, 'the charge lock is stored by INSERT IGNORE' );
$notoptions_after = wp_cache_get( 'notoptions', 'options' );
creature_demo_expect( is_array( $notoptions_after ) && ! array_key_exists( $contender_name, $notoptions_after ) && ! empty( $notoptions_after['keep-me'] ), 'acquire removes the lock name from the notoptions cache' );
creature_demo_expect( array( $contender_name => 'cached', 'siteurl' => 'https://example.test' ) === wp_cache_get( 'alloptions', 'options' ), 'an autoload-off lock does not delete the alloptions cache' );
creature_demo_expect( false === wp_cache_get( $contender_name, 'options' ), 'acquire deletes the per-option cache' );
$lock_cache_deleted = false;
foreach ( array_slice( $GLOBALS['creature_demo_cache_deletes'], $cache_deletes_before ) as $cache_delete ) {
	if ( $contender_name === $cache_delete[0] && 'options' === $cache_delete[1] ) {
		$lock_cache_deleted = true;
	}
}
creature_demo_expect( $lock_cache_deleted, 'acquire calls wp_cache_delete for the lock option' );

$stale_order = wc_create_order();
creature_demo_plant_lock( $stale_order, 'pay_link', Creature_Demo_Loan::charge_lock_now() - 900, 'stale-token' );
$stale_token = Creature_Demo_Loan::acquire_charge_lock( $stale_order, 'saved_card' );
creature_demo_expect( is_string( $stale_token ) && 'stale-token' !== $stale_token, 'a stale lock can be taken over' );
creature_demo_expect( 'saved_card' === creature_demo_lock_owner( $stale_order ) && $stale_token === creature_demo_lock_row( $stale_order )['token'], 'the takeover writes a new token' );

$timeless = wc_create_order();
creature_demo_plant_lock( $timeless, 'pay_link', null, 'no-time' );
$timeless_token = Creature_Demo_Loan::acquire_charge_lock( $timeless, 'pay_link' );
creature_demo_expect( is_string( $timeless_token ) && 'no-time' !== $timeless_token, 'a lock with no timestamp is stale and can be taken over' );

$lost_order = wc_create_order();
creature_demo_plant_lock( $lost_order, 'pay_link', Creature_Demo_Loan::charge_lock_now() - 900, 'lost-token' );
$GLOBALS['creature_demo_insert_ignore_reject'] = 1;
$lost_token = Creature_Demo_Loan::acquire_charge_lock( $lost_order, 'saved_card' );
$GLOBALS['creature_demo_insert_ignore_reject'] = 0;
creature_demo_expect( false === $lost_token, 'a takeover that loses the insert does not hold the lock' );
creature_demo_expect( false === creature_demo_lock_row( $lost_order ), 'a lost takeover does not leave the stale row' );

$raced = wc_create_order();
creature_demo_plant_lock( $raced, 'pay_link', Creature_Demo_Loan::charge_lock_now() - 900, 'stale-before-race' );
$raced_name = Creature_Demo_Loan::charge_lock_option_name( $raced->get_id() );
$GLOBALS['creature_demo_before_lock_delete'] = static function () use ( $raced_name ) {
	$fresh = array(
		'owner' => 'saved_card',
		'at'    => Creature_Demo_Loan::charge_lock_now(),
		'token' => 'fresh-during-takeover',
	);
	$GLOBALS['creature_demo_options'][ $raced_name ]    = $fresh;
	$GLOBALS['creature_demo_option_raw'][ $raced_name ] = maybe_serialize( $fresh );
};
$raced_token = Creature_Demo_Loan::acquire_charge_lock( $raced, 'pay_link' );
creature_demo_expect( false === $raced_token, 'a stale takeover does not win when a fresh lock arrives first' );
creature_demo_expect( 'saved_card' === creature_demo_lock_owner( $raced ) && 'fresh-during-takeover' === creature_demo_lock_row( $raced )['token'], 'a stale takeover cannot delete the fresh lock' );

$pair = wc_create_order();
$pair_stale_at = Creature_Demo_Loan::charge_lock_now() - Creature_Demo_Loan::CHARGE_LOCK_STALE_SECONDS;
creature_demo_plant_lock( $pair, 'pay_link', $pair_stale_at, 'stale-shared' );
creature_demo_expect( Creature_Demo_Loan::charge_lock_now() - $pair_stale_at >= Creature_Demo_Loan::CHARGE_LOCK_STALE_SECONDS, 'the raced lock is at least one stale window old' );
$pair_name = Creature_Demo_Loan::charge_lock_option_name( $pair->get_id() );
wp_cache_set( 'alloptions', array( 'siteurl' => 'https://example.test' ), 'options' );
wp_cache_set( 'notoptions', array( $pair_name => true, 'keep-me' => true ), 'options' );
wp_cache_set( $pair_name, 'cached-row', 'options' );
$pair_inserts_before = count( $wpdb->inserts );
$pair_second         = false;
$pair_saw_stale      = false;
$GLOBALS['creature_demo_before_lock_delete'] = static function () use ( $pair, $pair_name, &$pair_second, &$pair_saw_stale ) {
	$raw            = isset( $GLOBALS['creature_demo_option_raw'][ $pair_name ] ) ? (string) $GLOBALS['creature_demo_option_raw'][ $pair_name ] : '';
	$pair_saw_stale = false !== strpos( $raw, 'stale-shared' );
	$pair_second    = Creature_Demo_Loan::acquire_charge_lock( $pair, 'saved_card' );
};
$pair_first = Creature_Demo_Loan::acquire_charge_lock( $pair, 'pay_link' );
$GLOBALS['creature_demo_before_lock_delete'] = null;
$pair_inserted = 0;
foreach ( array_slice( $wpdb->inserts, $pair_inserts_before ) as $pair_insert ) {
	if ( $pair_name === $pair_insert['name'] && ! empty( $pair_insert['inserted'] ) ) {
		$pair_inserted++;
	}
}
creature_demo_expect( $pair_saw_stale, 'both stale takers observe the same stale row' );
creature_demo_expect( 1 === $pair_inserted, 'two stale takers get exactly one INSERT IGNORE win' );
creature_demo_expect( false === $pair_first, 'the stale taker that loses the race does not hold the lock' );
creature_demo_expect( is_string( $pair_second ) && '' !== $pair_second, 'the stale taker whose insert affects one row wins' );
creature_demo_expect( 'saved_card' === creature_demo_lock_owner( $pair ) && $pair_second === creature_demo_lock_row( $pair )['token'], 'the losing stale taker stays locked out' );
creature_demo_expect( false === Creature_Demo_Loan::acquire_charge_lock( $pair, 'pay_link' ), 'a later acquire stays locked while the winner holds the row' );
creature_demo_expect( false === Creature_Demo_Loan::release_charge_lock( $pair, 'pay_link', 'stale-shared' ), 'the losing stale taker cannot release the winner lock' );
creature_demo_expect( false === Creature_Demo_Loan::release_charge_lock( $pair, 'saved_card', 'not-the-winner' ), 'a wrong token cannot release the winner lock' );
creature_demo_expect( $pair_second === creature_demo_lock_row( $pair )['token'], 'the winner lock is still held after the loser tries to release it' );
creature_demo_expect( true === Creature_Demo_Loan::release_charge_lock( $pair, 'saved_card', $pair_second ), 'the winning stale taker can release the lock' );
creature_demo_expect( false === creature_demo_lock_row( $pair ), 'the winner release deletes the lock' );
$pair_alloptions = wp_cache_get( 'alloptions', 'options' );
creature_demo_expect( is_array( $pair_alloptions ) && 'https://example.test' === $pair_alloptions['siteurl'], 'a stale takeover does not delete alloptions' );
$pair_notoptions = wp_cache_get( 'notoptions', 'options' );
creature_demo_expect( is_array( $pair_notoptions ) && ! array_key_exists( $pair_name, $pair_notoptions ) && ! empty( $pair_notoptions['keep-me'] ), 'a stale takeover removes the lock name from notoptions' );
creature_demo_expect( false === wp_cache_get( $pair_name, 'options' ), 'a stale takeover clears the lock option cache' );

$mismatch = wc_create_order();
$mismatch_token = Creature_Demo_Loan::acquire_charge_lock( $mismatch, 'pay_link' );
creature_demo_expect( false === Creature_Demo_Loan::release_charge_lock( $mismatch, 'pay_link', 'wrong-token' ), 'a token mismatch does not release the lock' );
creature_demo_expect( 'pay_link' === creature_demo_lock_owner( $mismatch ), 'the lock stays after a token mismatch' );
creature_demo_expect( false === Creature_Demo_Loan::release_charge_lock( $mismatch, 'saved_card', $mismatch_token ), 'the other owner cannot release the lock' );
$mismatch_name = Creature_Demo_Loan::charge_lock_option_name( $mismatch->get_id() );
wp_cache_set( 'alloptions', array( 'siteurl' => 'https://example.test', 'blogname' => 'keep' ), 'options' );
wp_cache_set( 'notoptions', array( $mismatch_name => true, 'keep-me' => true ), 'options' );
wp_cache_set( $mismatch_name, 'cached-row', 'options' );
$release_deletes_before = count( $GLOBALS['creature_demo_cache_deletes'] );
creature_demo_expect( true === Creature_Demo_Loan::release_charge_lock( $mismatch, 'pay_link', $mismatch_token ), 'the matching token releases the lock' );
$release_alloptions = wp_cache_get( 'alloptions', 'options' );
creature_demo_expect( is_array( $release_alloptions ) && 'keep' === $release_alloptions['blogname'], 'release does not delete alloptions' );
$release_notoptions = wp_cache_get( 'notoptions', 'options' );
creature_demo_expect( is_array( $release_notoptions ) && ! array_key_exists( $mismatch_name, $release_notoptions ) && ! empty( $release_notoptions['keep-me'] ), 'release removes the lock name from notoptions' );
creature_demo_expect( false === wp_cache_get( $mismatch_name, 'options' ), 'release clears the lock option cache' );
$release_deleted_alloptions = false;
foreach ( array_slice( $GLOBALS['creature_demo_cache_deletes'], $release_deletes_before ) as $release_delete ) {
	if ( 'alloptions' === $release_delete[0] && 'options' === $release_delete[1] ) {
		$release_deleted_alloptions = true;
	}
}
creature_demo_expect( ! $release_deleted_alloptions, 'release does not call wp_cache_delete for alloptions' );
creature_demo_expect( false === creature_demo_lock_row( $mismatch ), 'a matching release deletes the option' );

$hpos_before = count( $GLOBALS['creature_demo_postmeta'] );
$hpos_order  = wc_create_order();
$hpos_order->update_meta_data( '_creature_demo_charge_order', 'yes' );
$hpos_order->update_meta_data( '_creature_demo_charge_lock', 'pay_link' );
$hpos_order->update_meta_data( '_creature_demo_charge_lock_at', '123' );
$hpos_order->save();
$hpos_token = Creature_Demo_Loan::acquire_charge_lock( $hpos_order, 'saved_card' );
creature_demo_expect( is_string( $hpos_token ), 'an HPOS order acquires the lock option' );
creature_demo_expect( '' === (string) $hpos_order->get_meta( '_creature_demo_charge_lock' ), 'an HPOS acquire clears old lock meta' );
creature_demo_expect( $hpos_before === count( $GLOBALS['creature_demo_postmeta'] ), 'an HPOS acquire does not touch postmeta' );
creature_demo_expect( true === Creature_Demo_Loan::release_charge_lock( $hpos_order, 'saved_card', $hpos_token ), 'an HPOS release deletes the option' );
creature_demo_expect( false === creature_demo_lock_row( $hpos_order ), 'the HPOS lock option is gone' );
creature_demo_expect( $hpos_before === count( $GLOBALS['creature_demo_postmeta'] ), 'an HPOS release does not touch postmeta' );
$loan_src = file_get_contents( dirname( __DIR__, 2 ) . '/creature-demo-loan/includes/class-creature-demo-loan.php' );
creature_demo_expect( false === strpos( $loan_src, 'update_post_meta' ) && false === strpos( $loan_src, 'add_post_meta' ), 'the charge lock does not call post meta' );
$stripe_src = file_get_contents( dirname( __DIR__, 2 ) . '/creature-demo-loan/includes/class-creature-demo-loan-stripe.php' );
creature_demo_expect( false !== strpos( $stripe_src, 'WC_Stripe_API::retrieve' ) && false !== strpos( $stripe_src, 'WC_Stripe_API::request' ), 'PaymentIntent lookup uses retrieve and falls back to request' );

$deleted_order = wc_create_order();
creature_demo_plant_lock( $deleted_order, 'pay_link', Creature_Demo_Loan::charge_lock_now(), 'delete-me' );
do_action( 'woocommerce_before_delete_order', $deleted_order->get_id() );
creature_demo_expect( false === creature_demo_lock_row( $deleted_order ), 'deleting an HPOS order removes its charge lock' );

$legacy_post = wc_create_order();
creature_demo_plant_lock( $legacy_post, 'saved_card', Creature_Demo_Loan::charge_lock_now(), 'post-lock' );
$GLOBALS['creature_demo_post_types'][ $legacy_post->get_id() ] = 'post';
do_action( 'before_delete_post', $legacy_post->get_id() );
creature_demo_expect( 'saved_card' === creature_demo_lock_owner( $legacy_post ), 'deleting a non-order post does not remove a charge lock' );
$GLOBALS['creature_demo_post_types'][ $legacy_post->get_id() ] = 'shop_order';
do_action( 'before_delete_post', $legacy_post->get_id() );
creature_demo_expect( false === creature_demo_lock_row( $legacy_post ), 'deleting a shop_order post removes its charge lock' );

$uninstall_order = wc_create_order();
creature_demo_plant_lock( $uninstall_order, 'pay_link', Creature_Demo_Loan::charge_lock_now(), 'uninstall-me' );
creature_demo_expect( 'creature_demo_loan_uninstall' === $GLOBALS['creature_demo_uninstall'][1], 'uninstall is registered' );
creature_demo_loan_uninstall();
creature_demo_expect( false === creature_demo_lock_row( $uninstall_order ), 'uninstall removes charge lock options' );
creature_demo_expect( isset( $GLOBALS['creature_demo_options']['woocommerce_stripe_settings'] ), 'uninstall leaves other options in place' );

creature_demo_expect( 'creature_demo_loan_deactivate' === $GLOBALS['creature_demo_deactivation'][1], 'deactivation is registered' );
creature_demo_loan_deactivate();
creature_demo_expect( empty( $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] ), 'deactivation unschedules the daily job' );

// ---- Keep price worked out from the bundle price at booking ----

$GLOBALS['creature_demo_bundle_id_override'] = null;
$GLOBALS['creature_demo_damage_cap_override'] = null;
add_filter(
	'creature_demo_loan_bundle_product_id',
	static function ( $id ) {
		return null === $GLOBALS['creature_demo_bundle_id_override'] ? $id : $GLOBALS['creature_demo_bundle_id_override'];
	}
);
add_filter(
	'creature_demo_loan_damage_cap_pence',
	static function ( $cap ) {
		return null === $GLOBALS['creature_demo_damage_cap_override'] ? $cap : $GLOBALS['creature_demo_damage_cap_override'];
	}
);

/**
 * Book a demo the way checkout does: a demo-only cart, the box ticked, the
 * order created through on_checkout_create_order, then started.
 *
 * @param string|null $bundle_price get_price() string for product 8425, or null for no product.
 */
function creature_demo_booked_loan( $bundle_price, $extra_post = array() ) {
	if ( null === $bundle_price ) {
		unset( $GLOBALS['creature_demo_product_prices'][8425] );
	} else {
		$GLOBALS['creature_demo_product_prices'][8425] = $bundle_price;
	}
	$saved_cart    = $GLOBALS['creature_demo_cart'];
	$saved_use     = $GLOBALS['creature_demo_use_product'];
	$saved_post    = $_POST;
	$GLOBALS['creature_demo_use_product'] = true;
	$GLOBALS['creature_demo_cart']        = array(
		array(
			'product_id'   => 9001,
			'variation_id' => 0,
		),
	);
	$_POST = array_merge( array( 'creature_demo_loan_consent' => '1' ), $extra_post );
	$order = wc_create_order(
		array(
			'customer_id' => 42,
			'status'      => 'processing',
		)
	);
	$order->set_billing_email( 'Rider@Example.com' );
	$order->update_meta_data( '_stripe_customer_id', 'cus_test_walker' );
	$order->update_meta_data( '_stripe_source_id', 'pm_test_walker' );
	Creature_Demo_Loan::on_checkout_create_order( $order );
	Creature_Demo_Loan::start_loan( $order, '2026-03-02', '2026-03-04' );
	$order->update_meta_data( '_creature_demo_findings', 'Test findings: dented clamp face.' );
	$order->update_meta_data( '_creature_demo_photos', array( 'https://example.com/uploads/demo-clamp-1.jpg' ) );
	$GLOBALS['creature_demo_cart']        = $saved_cart;
	$GLOBALS['creature_demo_use_product'] = $saved_use;
	$_POST                                = $saved_post;
	return $order;
}

creature_demo_expect( 8425 === Creature_Demo_Loan::bundle_product_id(), 'the bundle product id defaults to 8425' );
$GLOBALS['creature_demo_bundle_id_override'] = 8430;
creature_demo_expect( 8430 === Creature_Demo_Loan::bundle_product_id(), 'the bundle product id filter overrides the default' );
$GLOBALS['creature_demo_bundle_id_override'] = null;

// Keep price from a stored bundle price.
foreach ( array(
	array( '249.00', 24900, 21200 ),
	array( '229.00', 22900, 19200 ),
	array( '199', 19900, 16200 ),
) as $case ) {
	$booked = creature_demo_booked_loan( $case[0] );
	creature_demo_expect( $case[1] === (int) $booked->get_meta( '_creature_demo_bundle_price_pence' ), "the bundle price {$case[0]} is stored in pence" );
	creature_demo_expect( 'bundle' === $booked->get_meta( '_creature_demo_bundle_price_source' ), "the bundle price {$case[0]} is recorded with source bundle" );
	creature_demo_expect( $case[2] === Creature_Demo_Loan::loan_keep_price_pence( $booked ), "a {$case[0]} bundle gives a keep price of {$case[2]}" );
}

// A price change after booking does not move an existing loan.
$GLOBALS['creature_demo_product_prices'][8425] = '249.00';
$steady = creature_demo_booked_loan( '249.00' );
$GLOBALS['creature_demo_product_prices'][8425] = '199.00';
creature_demo_expect( 21200 === Creature_Demo_Loan::loan_keep_price_pence( $steady ), 'a later sale does not move the keep price of an existing loan' );
$steady_keep = Creature_Demo_Loan::choose( $steady, 'keep', '2026-03-10' );
creature_demo_expect( 21200 === $steady_keep['amount'], 'the keep link on an existing loan ignores a later price change' );
Creature_Demo_Loan::on_store_api_order( $steady, array( 'additional_fields' => array( 'creature/demo-loan-consent' => '1' ) ) );
creature_demo_expect( 24900 === (int) $steady->get_meta( '_creature_demo_bundle_price_pence' ), 'a repeat booking hook does not recapture the bundle price' );
$after_sale = creature_demo_booked_loan( '199.00' );
creature_demo_expect( 16200 === Creature_Demo_Loan::loan_keep_price_pence( $after_sale ), 'a booking made during the sale gets the sale keep price' );

// Capture is for demo carts only, and the Store API path captures too.
$GLOBALS['creature_demo_product_prices'][8425] = '229.00';
creature_demo_clear_cart();
$plain_order = wc_create_order();
Creature_Demo_Loan::on_checkout_create_order( $plain_order );
creature_demo_expect( '' === $plain_order->get_meta( '_creature_demo_bundle_price_source' ), 'a non-demo checkout order captures no bundle price' );
$plain_block = wc_create_order();
Creature_Demo_Loan::on_store_api_order( $plain_block, array() );
creature_demo_expect( '' === $plain_block->get_meta( '_creature_demo_bundle_price_source' ), 'a non-demo Store API order captures no bundle price' );
$block_demo = wc_create_order();
Creature_Demo_Loan::on_store_api_order( $block_demo, array( 'additional_fields' => array( 'creature/demo-loan-consent' => '1' ) ) );
creature_demo_expect( 22900 === (int) $block_demo->get_meta( '_creature_demo_bundle_price_pence' ) && 'bundle' === $block_demo->get_meta( '_creature_demo_bundle_price_source' ), 'a demo Store API order captures the bundle price at booking' );

// The cap is the lower of £212 and the loan's keep price, and the filter is clamped.
$cap192 = creature_demo_booked_loan( '229.00' );
$cap_r  = creature_demo_damage( $cap192, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 19200 === $cap_r['amount'], 'damage on a £192 loan is capped at £192' );
$cap162 = creature_demo_booked_loan( '199.00' );
$cap_r  = creature_demo_damage( $cap162, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 16200 === $cap_r['amount'], 'damage on a £162 loan is capped at £162' );
$cap212 = creature_demo_booked_loan( '249.00' );
$cap_r  = creature_demo_damage( $cap212, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 21200 === $cap_r['amount'], 'damage on a £212 loan is capped at £212' );
$GLOBALS['creature_demo_damage_cap_override'] = 99999;
$cap_up = creature_demo_booked_loan( '229.00' );
$cap_r  = creature_demo_damage( $cap_up, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 19200 === $cap_r['amount'], 'a filter above the keep price cannot lift the damage cap on a £192 loan' );
$cap_up212 = creature_demo_booked_loan( '249.00' );
$cap_r     = creature_demo_damage( $cap_up212, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 21200 === $cap_r['amount'], 'a filter above £212 cannot lift the damage cap past £212' );
$GLOBALS['creature_demo_damage_cap_override'] = 10000;
$cap_down = creature_demo_booked_loan( '229.00' );
$cap_r    = creature_demo_damage( $cap_down, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 10000 === $cap_r['amount'], 'a filter below the keep price still lowers the damage cap' );
$GLOBALS['creature_demo_damage_cap_override'] = -5;
$cap_neg = creature_demo_booked_loan( '229.00' );
$cap_r   = creature_demo_damage( $cap_neg, 30000, 'Damage', '2026-03-20' );
creature_demo_expect( 19200 === $cap_r['amount'], 'a negative filter falls back to the keep price' );
$GLOBALS['creature_demo_damage_cap_override'] = null;

// Missing meta, no product, no id, or a price of zero fall back to £212.
$legacy = creature_demo_loan();
creature_demo_expect( '' === $legacy->get_meta( '_creature_demo_bundle_price_source' ), 'a loan made without the booking hook has no bundle meta' );
creature_demo_expect( 21200 === Creature_Demo_Loan::loan_keep_price_pence( $legacy ), 'missing meta falls back to a £212 keep price' );
$no_product = creature_demo_booked_loan( null );
creature_demo_expect( 'fallback' === $no_product->get_meta( '_creature_demo_bundle_price_source' ), 'no product records the fallback source' );
creature_demo_expect( 21200 === Creature_Demo_Loan::loan_keep_price_pence( $no_product ), 'no product gives a £212 keep price' );
$zero_price = creature_demo_booked_loan( '0' );
creature_demo_expect( 'fallback' === $zero_price->get_meta( '_creature_demo_bundle_price_source' ) && 21200 === Creature_Demo_Loan::loan_keep_price_pence( $zero_price ), 'a price of zero records fallback and gives £212' );
$blank_price = creature_demo_booked_loan( '' );
creature_demo_expect( 'fallback' === $blank_price->get_meta( '_creature_demo_bundle_price_source' ) && 21200 === Creature_Demo_Loan::loan_keep_price_pence( $blank_price ), 'a blank price records fallback and gives £212' );
$GLOBALS['creature_demo_bundle_id_override'] = 0;
$no_id = creature_demo_booked_loan( '199.00' );
creature_demo_expect( 'fallback' === $no_id->get_meta( '_creature_demo_bundle_price_source' ) && 21200 === Creature_Demo_Loan::loan_keep_price_pence( $no_id ), 'no bundle id records fallback and gives £212' );
$GLOBALS['creature_demo_bundle_id_override'] = null;
$bundle_only = creature_demo_booked_loan( '10.00' );
creature_demo_expect( 'fallback_below_floor' === $bundle_only->get_meta( '_creature_demo_bundle_price_source' ) && 21200 === Creature_Demo_Loan::loan_keep_price_pence( $bundle_only ), 'a real bundle price below the deductions records fallback_below_floor and gives £212' );
creature_demo_expect( 1000 === (int) $bundle_only->get_meta( '_creature_demo_bundle_price_pence' ), 'a below-floor price still stores the real pence' );

// The floor: at or below £37.00 (demo fee plus used stock) is a price error.
creature_demo_expect( 3700 === Creature_Demo_Loan::BUNDLE_FLOOR_PENCE, 'the bundle floor is the demo fee plus used stock, 3700' );
foreach ( array(
	array( '37.00', 3700, 'fallback_below_floor', 21200 ),
	array( '36.99', 3699, 'fallback_below_floor', 21200 ),
	array( '37.01', 3701, 'bundle', 1 ),
	array( '249.00', 24900, 'bundle', 21200 ),
	array( '229.00', 22900, 'bundle', 19200 ),
) as $case ) {
	$floor_loan = creature_demo_booked_loan( $case[0] );
	creature_demo_expect( $case[2] === $floor_loan->get_meta( '_creature_demo_bundle_price_source' ), "a {$case[0]} bundle records source {$case[2]}" );
	creature_demo_expect( $case[1] === (int) $floor_loan->get_meta( '_creature_demo_bundle_price_pence' ), "a {$case[0]} bundle stores the real pence {$case[1]}" );
	creature_demo_expect( $case[3] === Creature_Demo_Loan::loan_keep_price_pence( $floor_loan ), "a {$case[0]} bundle gives a keep price of {$case[3]}" );
	$floor_deemed = Creature_Demo_Loan::maybe_raise_deemed( $floor_loan, '2026-04-07' );
	creature_demo_expect( ! empty( $floor_deemed['ok'] ) && $case[3] === $floor_deemed['amount'], "the deemed amount on a {$case[0]} bundle is {$case[3]}" );
}

// Read side only: the source decides, whatever the stored pence.
foreach ( array( 3000, 0 ) as $stored ) {
	$read_only = creature_demo_loan();
	$read_only->update_meta_data( '_creature_demo_bundle_price_pence', $stored );
	$read_only->update_meta_data( '_creature_demo_bundle_price_source', 'fallback_below_floor' );
	creature_demo_expect( 21200 === Creature_Demo_Loan::loan_keep_price_pence( $read_only ), "meta pence {$stored} with source fallback_below_floor gives £212" );
}

// Write-once holds for a below-floor booking.
$GLOBALS['creature_demo_product_prices'][8425] = '30.00';
$floor_once = creature_demo_booked_loan( '30.00' );
$GLOBALS['creature_demo_product_prices'][8425] = '249.00';
Creature_Demo_Loan::on_store_api_order( $floor_once, array( 'additional_fields' => array( 'creature/demo-loan-consent' => '1' ) ) );
creature_demo_expect( 3000 === (int) $floor_once->get_meta( '_creature_demo_bundle_price_pence' ) && 'fallback_below_floor' === $floor_once->get_meta( '_creature_demo_bundle_price_source' ), 'a second capture does not change a fallback_below_floor order' );
creature_demo_expect( 21200 === Creature_Demo_Loan::loan_keep_price_pence( $floor_once ), 'a second capture leaves the £212 keep price on a fallback_below_floor order' );

// Keep, deemed purchase and the 8.5(b) refund use the computed figure.
$keep192 = creature_demo_booked_loan( '229.00' );
$keep192_r = Creature_Demo_Loan::choose( $keep192, 'keep', '2026-03-10' );
creature_demo_expect( 19200 === $keep192_r['amount'], 'the keep link on a £192 loan is £192' );
$deemed192 = creature_demo_booked_loan( '229.00' );
$deemed192_r = Creature_Demo_Loan::maybe_raise_deemed( $deemed192, '2026-04-07' );
creature_demo_expect( 19200 === $deemed192_r['amount'], 'the deemed purchase on a £192 loan is £192' );
$deemed162 = creature_demo_booked_loan( '199.00' );
$deemed162->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$deemed162_r = Creature_Demo_Loan::maybe_raise_deemed( $deemed162, '2026-04-07' );
creature_demo_expect( 14200 === $deemed162_r['amount'], 'the deemed purchase on a £162 loan nets off late fees' );

$refund192 = creature_demo_booked_loan( '229.00' );
$refund192->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$refund192->update_meta_data( '_creature_demo_ever_chose_keep', 'yes' );
$refund192_r = Creature_Demo_Loan::maybe_raise_deemed( $refund192, '2026-04-07' );
creature_demo_expect( 17200 === $refund192_r['amount'], 'the deemed purchase on a £192 loan with £20 of late fees paid is £172' );
$refund192_charge = wc_get_order( $refund192_r['order_id'] );
$refund192_charge->update_meta_data( '_stripe_intent_id', 'pi_test_deemed_192' );
Creature_Demo_Loan::on_payment_complete( $refund192_r['order_id'] );
Creature_Demo_Loan::record_return_scan( $refund192, '2026-04-08', '2026-04-08' );
Creature_Demo_Loan::record_kit_received( $refund192, '2026-04-08' );
creature_demo_inspect( $refund192, '2026-04-08' );
$refund192->update_meta_data( '_creature_demo_damage_pence', 1500 );
$GLOBALS['creature_demo_refund_flag'] = true;
WC_Stripe_API::$response = (object) array(
	'id'     => 're_test_demo_192',
	'status' => 'succeeded',
);
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array( 'subject' => 'Test deduction for {order_id}', 'body' => 'Deduction {amount}.' );
$refund192_done = Creature_Demo_Loan::refund_deemed( $refund192, '2026-04-09' );
$GLOBALS['creature_demo_copy_override']['deduction_notice_deemed'] = array(
	'subject' => '',
	'body'    => '',
);
creature_demo_expect( ! empty( $refund192_done['ok'] ) && 15300 === $refund192_done['amount'], 'the 8.5(b) refund on a £192 loan keeps the rest of the £24 and the damage out of £172' );
$GLOBALS['creature_demo_refund_flag'] = null;

// A £35 mount kit inside reduced headroom.
$room_fee = wc_create_order();
$room_fee->update_meta_data( '_creature_demo_charge_amount_pence', 900 );
$room_fee->save();
$room_loan = creature_demo_booked_loan( '90.00' );
creature_demo_expect( 5300 === Creature_Demo_Loan::loan_keep_price_pence( $room_loan ), 'a £90 bundle gives a £53 keep price' );
$room_loan->update_meta_data( '_creature_demo_late_fee_order_id', $room_fee->get_id() );
$room_r = creature_demo_damage( $room_loan, 3500, 'Inverted mount kit', '2026-03-20' );
creature_demo_expect( ! empty( $room_r['ok'] ) && 3500 === $room_r['amount'] && empty( $room_r['clamped'] ), 'a £35 mount kit is charged in full when £44 of headroom is left' );
$tight_fee = wc_create_order();
$tight_fee->update_meta_data( '_creature_demo_charge_amount_pence', 2400 );
$tight_fee->save();
$tight_loan = creature_demo_booked_loan( '90.00' );
$tight_loan->update_meta_data( '_creature_demo_late_fee_order_id', $tight_fee->get_id() );
$tight_r = creature_demo_damage( $tight_loan, 3500, 'Inverted mount kit', '2026-03-20' );
creature_demo_expect( ! empty( $tight_r['ok'] ) && 2900 === $tight_r['amount'] && ! empty( $tight_r['clamped'] ) && 600 === $tight_r['reduced_pence'], 'a £35 mount kit is clamped to the £29 of headroom left on a £53 loan' );
$wide_loan = creature_demo_booked_loan( '249.00' );
$wide_loan->update_meta_data( '_creature_demo_late_fee_order_id', $tight_fee->get_id() );
$wide_r = creature_demo_damage( $wide_loan, 3500, 'Inverted mount kit', '2026-03-20' );
creature_demo_expect( ! empty( $wide_r['ok'] ) && 3500 === $wide_r['amount'] && empty( $wide_r['clamped'] ), 'a £35 mount kit still charges in full on a £212 loan with £24 of late fees' );

/**
 * Charge orders raised against one loan.
 */
function creature_demo_charge_orders_for( $loan ) {
	$n = 0;
	foreach ( $GLOBALS['creature_demo_orders'] as $candidate ) {
		if ( (int) $candidate->get_meta( '_creature_demo_parent_loan_id' ) === (int) $loan->get_id() ) {
			$n++;
		}
	}
	return $n;
}

function creature_demo_charge_mail_count() {
	return creature_demo_mail_count( 'charge_notice_late_fee' ) + creature_demo_mail_count( 'charge_notice_damage' ) + creature_demo_mail_count( 'deduction_notice_deemed' );
}

// Return dispatch date (terms 3.3): the earlier of the proof of posting and the first scan.
$dd_none = creature_demo_loan();
creature_demo_expect( '' === Creature_Demo_Loan::dispatch_date( $dd_none ), 'with no posting date and no scan there is no dispatch date' );
$dd_post   = creature_demo_loan();
$dd_post_r = Creature_Demo_Loan::record_return_posted( $dd_post, '2026-04-02' );
creature_demo_expect( ! empty( $dd_post_r['ok'] ) && '2026-04-02' === Creature_Demo_Loan::dispatch_date( $dd_post ), 'a posting date on its own is the dispatch date' );
$dd_post_again = Creature_Demo_Loan::record_return_posted( $dd_post, '2026-04-02' );
creature_demo_expect( ! empty( $dd_post_again['idempotent'] ), 'the same posting date twice is idempotent' );
$dd_scan   = creature_demo_loan();
$dd_scan_r = Creature_Demo_Loan::record_return_scan( $dd_scan, '2026-04-02', '2026-04-02' );
creature_demo_expect( '2026-04-02' === $dd_scan_r['dispatch'] && 1200 === $dd_scan_r['late_fee'], 'a scan on its own is the dispatch date, and 4 late days are £12' );
$dd_both = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $dd_both, '2026-03-28' );
$dd_both_r = Creature_Demo_Loan::record_return_scan( $dd_both, '2026-04-02', '2026-04-02' );
creature_demo_expect( '2026-03-28' === $dd_both_r['dispatch'] && 0 === $dd_both_r['late_fee'], 'a posting date inside the grace period beats a later scan: no late fee' );
creature_demo_expect( '2026-04-02' === $dd_both->get_meta( '_creature_demo_return_scan_date' ), 'the scan date is still stored as scanned' );
$dd_later = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $dd_later, '2026-04-05' );
$dd_later_r = Creature_Demo_Loan::record_return_scan( $dd_later, '2026-04-02', '2026-04-02' );
creature_demo_expect( '2026-04-02' === $dd_later_r['dispatch'] && 1200 === $dd_later_r['late_fee'], 'when the scan is the earlier date it sets the late-fee end date' );
$dd_after_scan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $dd_after_scan, '2026-04-08', '2026-04-08' );
$dd_after_scan_r = Creature_Demo_Loan::record_return_posted( $dd_after_scan, '2026-04-05' );
creature_demo_expect( ! empty( $dd_after_scan_r['ok'] ) && '2026-04-05' === $dd_after_scan_r['dispatch'], 'a posting date entered after the scan still counts' );
foreach ( array( '2026-02-30', '2026-13-01', '31/03/2026', 'garbage', '', '2026-4-2' ) as $bad ) {
	$bad_r = Creature_Demo_Loan::record_return_posted( $dd_none, $bad );
	creature_demo_expect( empty( $bad_r['ok'] ) && 'bad_date' === $bad_r['reason'], 'posting date "' . $bad . '" is refused' );
}
creature_demo_expect( '' === $dd_none->get_meta( '_creature_demo_return_posted_date' ), 'a refused posting date is not stored' );
$_POST = array(
	'creature_demo_loan_admin_nonce'   => 'creature_demo_loan_admin',
	'creature_demo_return_posted_date' => '2026-02-30',
);
Creature_Demo_Loan::save_meta_box( $dd_none->get_id(), $dd_none );
creature_demo_expect( '' === $dd_none->get_meta( '_creature_demo_return_posted_date' ), 'the order screen ignores an impossible date' );
$_POST['creature_demo_return_posted_date'] = '2026-03-27';
Creature_Demo_Loan::save_meta_box( $dd_none->get_id(), $dd_none );
creature_demo_expect( '2026-03-27' === $dd_none->get_meta( '_creature_demo_return_posted_date' ), 'the order screen saves a Y-m-d proof of posting date' );
ob_start();
Creature_Demo_Loan::render_meta_box( $dd_none );
$meta_html = ob_get_clean();
creature_demo_expect( false !== strpos( $meta_html, 'name="creature_demo_return_posted_date"' ) && false !== strpos( $meta_html, 'Proof of posting date' ), 'the order screen has a proof of posting date field' );
creature_demo_expect( strpos( $meta_html, 'name="creature_demo_return_scan_date"' ) < strpos( $meta_html, 'name="creature_demo_return_posted_date"' ), 'the posting date field sits beside the return scan date' );
$_POST = array();

// The deemed-purchase trigger uses the dispatch date.
$dp_ok = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $dp_ok, '2026-04-06' );
$dp_ok_r = Creature_Demo_Loan::record_return_scan( $dp_ok, '2026-04-09', '2026-04-09' );
creature_demo_expect( empty( $dp_ok_r['after_deemed'] ), 'posted on day 13 and scanned on day 16 is not a deemed purchase' );
creature_demo_expect( '' === $dp_ok->get_meta( '_creature_demo_deemed_order_id' ) && 'demo-inspect' === $dp_ok->get_status(), 'no deemed charge is raised and the kit goes to inspection' );
creature_demo_expect( 2400 === $dp_ok_r['late_fee'], 'the late fee runs to the posting date, capped at £24' );
$dp_late = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $dp_late, '2026-04-07' );
$dp_late_r = Creature_Demo_Loan::record_return_scan( $dp_late, '2026-04-07', '2026-04-07' );
creature_demo_expect( ! empty( $dp_late_r['after_deemed'] ) && 'demo-inspect' === $dp_late->get_status() && '' === $dp_late->get_meta( '_creature_demo_deemed_order_id' ), 'a dispatch date on day 14 is after the deemed purchase date: a late return (8.5(a)), no deemed charge raised' );
$dp_wait = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $dp_wait, '2026-04-05' );
$dp_wait_r = Creature_Demo_Loan::maybe_raise_deemed( $dp_wait, '2026-04-08' );
creature_demo_expect( empty( $dp_wait_r['ok'] ) && 'posted' === $dp_wait_r['reason'], 'posted before day 14 and not yet scanned is not deemed' );
Creature_Demo_Loan::tick( $dp_wait, '2026-04-08' );
creature_demo_expect( 'demo-deemed' !== $dp_wait->get_status(), 'the daily check leaves a posted kit alone' );
$dp_after = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $dp_after, '2026-04-07' );
$dp_after_r = Creature_Demo_Loan::maybe_raise_deemed( $dp_after, '2026-04-08' );
creature_demo_expect( ! empty( $dp_after_r['ok'] ), 'a posting date on day 14 does not stop the deemed purchase' );
$dp_none = creature_demo_loan();
$dp_none_r = Creature_Demo_Loan::maybe_raise_deemed( $dp_none, '2026-04-07' );
creature_demo_expect( ! empty( $dp_none_r['ok'] ) && 21200 === $dp_none_r['amount'], 'with neither date set the deemed purchase behaves as before' );

// One bill for late fees, damage and missing parts (terms 4.4, Appendix C).
$ob = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $ob, '2026-04-02', '2026-04-02' );
creature_demo_expect( 0 === creature_demo_charge_orders_for( $ob ), 'nothing is charged at the scan' );
$ob->update_meta_data( '_creature_demo_damage_pence', 2000 );
$ob->update_meta_data( '_creature_demo_missing_pence', 3500 );
$ob_mails = creature_demo_charge_mail_count();
Creature_Demo_Loan::record_kit_received( $ob, '2026-04-02' );
$ob_done  = creature_demo_inspect( $ob, '2026-04-02' );
$ob_order = wc_get_order( (int) $ob->get_meta( '_creature_demo_bill_order_id' ) );
creature_demo_expect( ! empty( $ob_done['ok'] ) && 'raised' === $ob->get_meta( '_creature_demo_bill_state' ), 'inspection raises the bill' );
creature_demo_expect( 1 === creature_demo_charge_orders_for( $ob ), 'late fee, damage and missing parts are one charge order' );
creature_demo_expect( 6700 === (int) $ob_order->get_meta( '_creature_demo_charge_amount_pence' ) && '67.00' === $ob_order->get_total(), 'the bill totals £12 + £20 + £35' );
$ob_lines = $ob_order->get_meta( '_creature_demo_charge_lines' );
creature_demo_expect( 3 === count( $ob_lines ) && array( 'late_fee', 'damage', 'missing_parts' ) === array_column( $ob_lines, 'kind' ) && array( 1200, 2000, 3500 ) === array_column( $ob_lines, 'pence' ), 'the bill has one line per item' );
creature_demo_expect( $ob_mails + 1 === creature_demo_charge_mail_count(), 'one bill email goes out' );
$ob_mail = creature_demo_last_mail( 'charge_notice_damage' );
creature_demo_expect( false !== strpos( $ob_mail['body'], '£67.00' ), 'the bill email names the total' );
creature_demo_expect( false !== strpos( $ob_mail['body'], 'Late fees for the demo loan: £12.00' ) && false !== strpos( $ob_mail['body'], 'Damage: £20.00' ) && false !== strpos( $ob_mail['body'], 'Missing parts: £35.00' ), 'the bill email lists every line' );
$ob_again = creature_demo_damage( $ob, 100, 'Damage', '2026-04-03' );
creature_demo_expect( ! empty( $ob_again['idempotent'] ) && 1 === creature_demo_charge_orders_for( $ob ), 'a second damage entry does not raise a second bill' );
creature_demo_expect( ! empty( creature_demo_inspect( $ob, '2026-04-04' )['idempotent'] ) && $ob_mails + 1 === creature_demo_charge_mail_count(), 'inspecting twice does not send a second bill' );
creature_demo_expect( ! empty( $ob_done['coupon']['code'] ), 'a billed return still gets the £25 code' );

// A late fee on its own uses the late-fee wording.
$lo = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $lo, '2026-04-02', '2026-04-02' );
$lo_mails = creature_demo_mail_count( 'charge_notice_late_fee' );
creature_demo_inspect( $lo, '2026-04-02' );
creature_demo_expect( $lo_mails + 1 === creature_demo_mail_count( 'charge_notice_late_fee' ), 'a late-fee-only bill uses the late-fee email' );
creature_demo_expect( 1200 === (int) wc_get_order( (int) $lo->get_meta( '_creature_demo_bill_order_id' ) )->get_meta( '_creature_demo_charge_amount_pence' ), 'the late-fee-only bill is £12' );

// A clean return has no bill. No scan or posting date means no late-fee line.
$clean = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $clean, '2026-03-20', '2026-03-20' );
$clean_done = creature_demo_inspect( $clean, '2026-03-20' );
creature_demo_expect( ! empty( $clean_done['ok'] ) && 'none' === $clean->get_meta( '_creature_demo_bill_state' ) && 0 === creature_demo_charge_orders_for( $clean ), 'an on-time clean return has no bill' );
creature_demo_expect( 'demo-closed' === $clean->get_status(), 'an on-time clean return closes the loan' );
$undated = creature_demo_loan();
$undated->update_meta_data( '_creature_demo_damage_pence', 2000 );
Creature_Demo_Loan::record_kit_received( $undated, '2026-04-02' );
creature_demo_inspect( $undated, '2026-04-02' );
creature_demo_expect( 2000 === (int) wc_get_order( (int) $undated->get_meta( '_creature_demo_bill_order_id' ) )->get_meta( '_creature_demo_charge_amount_pence' ), 'with neither date set the bill has no late-fee line' );

// Caps across the whole bill: £24 on late fees, and the overall cap on the total.
$cp = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cp, '2026-04-06', '2026-04-06' );
$cp->update_meta_data( '_creature_demo_damage_pence', 18000 );
$cp->update_meta_data( '_creature_demo_missing_pence', 3500 );
$cp_done = creature_demo_inspect( $cp, '2026-04-06' );
$cp_lines = wc_get_order( (int) $cp->get_meta( '_creature_demo_bill_order_id' ) )->get_meta( '_creature_demo_charge_lines' );
creature_demo_expect( 21200 === $cp_done['bill']['amount'], 'late fee, damage and missing parts together stop at £212' );
creature_demo_expect( array( 2400, 18000, 800 ) === array_column( $cp_lines, 'pence' ), 'the late fee stays £24 and missing parts take what is left' );
creature_demo_expect( ! empty( $cp_done['bill']['clamped'] ) && 2700 === $cp_done['bill']['reduced_pence'], 'the bill records how much was clamped' );
$cp_small = creature_demo_booked_loan( '50.00' );
creature_demo_expect( 1300 === Creature_Demo_Loan::loan_keep_price_pence( $cp_small ), 'a £50 bundle gives a £13 keep price' );
Creature_Demo_Loan::record_return_scan( $cp_small, '2026-04-06', '2026-04-06' );
$cp_small->update_meta_data( '_creature_demo_damage_pence', 5000 );
$cp_small_done = creature_demo_inspect( $cp_small, '2026-04-06' );
creature_demo_expect( 1300 === $cp_small_done['bill']['amount'] && array( 1300 ) === array_column( wc_get_order( (int) $cp_small->get_meta( '_creature_demo_bill_order_id' ) )->get_meta( '_creature_demo_charge_lines' ), 'pence' ), 'the late fee is held to the overall cap on a small keep price, and damage gets nothing' );
$cp_cheap = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $cp_cheap, '2026-04-20', '2026-04-20' );
creature_demo_expect( 'demo-inspect' === $cp_cheap->get_status() && '' === $cp_cheap->get_meta( '_creature_demo_deemed_order_id' ), 'a scan after day 14 with nothing paid is a late return, billed once, not a deemed purchase' );

// Day 5 working days: the shop is warned on day 4 and a late-fee-only bill goes out after day 5.
// 2026-04-02 is a Thursday. Day 4 is Wed 04-08, day 5 is Thu 04-09 (Saturday and Sunday are skipped).
$fb = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $fb, '2026-04-02', '2026-04-02' );
creature_demo_expect( '' === $fb->get_meta( '_creature_demo_received_date' ), 'a scan never sets the Kit received date' );
Creature_Demo_Loan::record_kit_received( $fb, '2026-04-02' );
$fb->update_meta_data( '_creature_demo_damage_pence', 2000 );
$warns = creature_demo_mail_count( 'admin_bill_due' );
Creature_Demo_Loan::tick( $fb, '2026-04-07' );
creature_demo_expect( $warns === creature_demo_mail_count( 'admin_bill_due' ), 'no warning before working day 4' );
Creature_Demo_Loan::tick( $fb, '2026-04-08' );
Creature_Demo_Loan::tick( $fb, '2026-04-08' );
creature_demo_expect( $warns + 1 === creature_demo_mail_count( 'admin_bill_due' ), 'the shop is emailed once on working day 4' );
$warn_mail = creature_demo_last_mail( 'admin_bill_due' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $warn_mail['to'] && 0 !== strpos( $warn_mail['subject'], '[TEST]' ), 'the working day 4 warning goes to info@' );
creature_demo_expect( '' === $fb->get_meta( '_creature_demo_bill_state' ) && 0 === creature_demo_charge_orders_for( $fb ), 'the 22:00 run on working day 4 sends the warning and no bill' );
creature_demo_absent( $warn_mail['body'], 'The bill must go out by the end of', 'the {deadline_day} sentence is gone from the day-4 alert' );
creature_demo_absent( $warn_mail['body'], '{deadline_day}', 'no {deadline_day} tag is left in the alert' );
creature_demo_expect( false !== strpos( $warn_mail['body'], 'so by 9 April 2026. Bank holidays aren’t counted' ) && false !== strpos( $warn_mail['subject'], 'inspect and bill by 9 April 2026 (order ' ), 'the {deadline} tag fills as a UK date (j F Y) in the body and the subject' );
creature_demo_expect( 'Work to this date.' . "\n\n" . 'Record the inspection on the order before 10pm on ' === substr( $warn_mail['body'], strpos( $warn_mail['body'], 'Work to this date.' ), strlen( 'Work to this date.' . "\n\n" . 'Record the inspection on the order before 10pm on ' ) ), 'the rest of the body is Cadence v3, with nothing added in its place' );
creature_demo_expect( '9 April 2026' === Creature_Demo_Loan_Copy::fill( '{deadline}', array( 'deadline' => '2026-04-09' ) ), 'fill() formats {deadline} as j F Y' );
creature_demo_expect( false !== strpos( $warn_mail['subject'], 'by 9 April 2026' ) && false !== strpos( $warn_mail['body'], 'received back on 2 April 2026' ) && 0 === preg_match( '/\b20\d\d-\d\d-\d\d\b/', $warn_mail['subject'] . $warn_mail['body'] ), 'item E: the shop alert shows UK dates (j F Y), no Y-m-d' );
$fb_mails = creature_demo_charge_mail_count();
Creature_Demo_Loan::tick( $fb, '2026-04-09' );
creature_demo_expect( 'late_only' === $fb->get_meta( '_creature_demo_bill_state' ), 'the 22:00 run on working day 5 sends the late-fee-only bill (>=, not day 6)' );
creature_demo_expect( $warns + 1 === creature_demo_mail_count( 'admin_bill_due' ), 'the warning is not repeated on day 5' );
Creature_Demo_Loan::tick( $fb, '2026-04-10' );
Creature_Demo_Loan::tick( $fb, '2026-04-11' );
creature_demo_expect( 'late_only' === $fb->get_meta( '_creature_demo_bill_state' ), 'once day 5 has come uninspected the bill window is closed' );
creature_demo_expect( 1 === creature_demo_charge_orders_for( $fb ) && $fb_mails + 1 === creature_demo_charge_mail_count(), 'one late-fee-only bill goes out, once' );
creature_demo_expect( 1200 === (int) wc_get_order( (int) $fb->get_meta( '_creature_demo_bill_order_id' ) )->get_meta( '_creature_demo_charge_amount_pence' ), 'the late-fee-only bill leaves out the damage that was entered' );
$fb_damage = creature_demo_damage( $fb, 2000, 'Damage', '2026-04-13' );
creature_demo_expect( empty( $fb_damage['ok'] ) && 'bill_closed' === $fb_damage['reason'], 'a later damage charge on that loan is refused' );
$fb_bill = Creature_Demo_Loan::raise_bill( $fb, '2026-04-13' );
creature_demo_expect( ! empty( $fb_bill['idempotent'] ) || empty( $fb_bill['ok'] ), 'a later bill on that loan is not raised' );
$fb_coupons = count( $GLOBALS['creature_demo_coupons'] );
$fb_done = creature_demo_inspect( $fb, '2026-04-13' );
creature_demo_expect( ! empty( $fb_done['ok'] ) && 1 === creature_demo_charge_orders_for( $fb ), 'inspecting after the window raises no second bill' );
creature_demo_expect( $fb_coupons + 1 === count( $GLOBALS['creature_demo_coupons'] ), 'the £25 code is still issued on the late inspected return' );
creature_demo_expect( false !== strpos( implode( "\n", $fb->notes ), 'not charged' ), 'the order notes say the damage is not charged' );
$fb_tick_after = creature_demo_charge_mail_count();
Creature_Demo_Loan::tick( $fb, '2026-04-20' );
creature_demo_expect( $fb_tick_after === creature_demo_charge_mail_count(), 'the daily check does nothing more once the bill is final' );

// Received over a Friday: Fri 2026-03-27, day 4 is Thu 04-02, day 5 is Fri 04-03.
$wk = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $wk, '2026-03-26', '2026-03-27' );
Creature_Demo_Loan::record_kit_received( $wk, '2026-03-27' );
Creature_Demo_Loan::tick( $wk, '2026-04-01' );
creature_demo_expect( 0 === creature_demo_charge_orders_for( $wk ) && '' === $wk->get_meta( '_creature_demo_bill_state' ), 'three working days in, no fallback yet' );
Creature_Demo_Loan::tick( $wk, '2026-04-03' );
creature_demo_expect( 'late_only' === $wk->get_meta( '_creature_demo_bill_state' ), 'working day 5 (Friday) falls back to a late-fee-only bill that evening' );

// A weekend between day 4 and day 5: received Mon 2026-03-30, day 4 is Fri 04-03, day 5 is Mon 04-06.
$we = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $we, '2026-03-30', '2026-03-30' );
Creature_Demo_Loan::record_kit_received( $we, '2026-03-30' );
$we_warns = creature_demo_mail_count( 'admin_bill_due' );
Creature_Demo_Loan::tick( $we, '2026-04-03' );
creature_demo_expect( $we_warns + 1 === creature_demo_mail_count( 'admin_bill_due' ) && '' === $we->get_meta( '_creature_demo_bill_state' ), 'Friday is working day 4: the alert goes, no bill yet' );
Creature_Demo_Loan::tick( $we, '2026-04-04' );
Creature_Demo_Loan::tick( $we, '2026-04-05' );
creature_demo_expect( '' === $we->get_meta( '_creature_demo_bill_state' ) && 0 === creature_demo_charge_orders_for( $we ) && $we_warns + 1 === creature_demo_mail_count( 'admin_bill_due' ), 'Saturday and Sunday run nothing: no bill and no second alert' );
Creature_Demo_Loan::tick( $we, '2026-04-06' );
creature_demo_expect( 'late_only' === $we->get_meta( '_creature_demo_bill_state' ), 'Monday, working day 5, sends the fallback bill' );

// The daily job runs at 22:00 site time (Europe/London), so working day 5 is still open when it fires.
$GLOBALS['creature_demo_tz'] = 'Europe/London';
$noon_gmt  = strtotime( '2026-03-02 12:00:00 UTC' );
$late_gmt  = strtotime( '2026-03-02 22:30:00 UTC' );
$noon_bst  = strtotime( '2026-06-02 12:00:00 UTC' );
creature_demo_expect( strtotime( '2026-03-02 22:00:00 UTC' ) === Creature_Demo_Loan::daily_run_timestamp( $noon_gmt ), 'the next run is 22:00 London the same day (GMT)' );
creature_demo_expect( strtotime( '2026-03-03 22:00:00 UTC' ) === Creature_Demo_Loan::daily_run_timestamp( $late_gmt ), 'after 22:00 the next run is 22:00 tomorrow' );
creature_demo_expect( strtotime( '2026-06-02 21:00:00 UTC' ) === Creature_Demo_Loan::daily_run_timestamp( $noon_bst ), '22:00 London in summer is 21:00 UTC' );
$GLOBALS['creature_demo_tz'] = 'America/New_York';
creature_demo_expect( strtotime( '2026-03-03 03:00:00 UTC' ) === Creature_Demo_Loan::daily_run_timestamp( $noon_gmt ), 'it uses wp_timezone(), not a fixed offset' );
$GLOBALS['creature_demo_tz'] = 'Europe/London';
// An event an earlier version scheduled at the wrong time is moved once.
unset( $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] );
$GLOBALS['creature_demo_unscheduled'] = array();
$GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] = array( strtotime( '2026-03-02 13:17:00 UTC' ), DAY_IN_SECONDS, array(), 'creature-demo-loan' );
Creature_Demo_Loan::schedule_daily( $noon_gmt );
$resched = $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'];
creature_demo_expect( strtotime( '2026-03-02 22:00:00 UTC' ) === $resched[0] && DAY_IN_SECONDS === $resched[1] && 1 === count( $GLOBALS['creature_demo_unscheduled'] ), 'an existing event at the wrong time is rescheduled to 22:00, once' );
Creature_Demo_Loan::schedule_daily( $noon_gmt );
Creature_Demo_Loan::schedule_daily( $late_gmt );
creature_demo_expect( $resched === $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] && 1 === count( $GLOBALS['creature_demo_unscheduled'] ), 'an event already at 22:00 is left alone on later loads' );
unset( $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'] );
Creature_Demo_Loan::schedule_daily( $noon_gmt );
creature_demo_expect( strtotime( '2026-03-02 22:00:00 UTC' ) === $GLOBALS['creature_demo_scheduled']['creature_demo_loan_daily'][0], 'a new schedule is set for the next 22:00' );
$GLOBALS['creature_demo_unscheduled'] = array();

// No late fee to bill: the window still closes, with no order.
$fb0 = creature_demo_loan();
Creature_Demo_Loan::record_return_posted( $fb0, '2026-03-28' );
Creature_Demo_Loan::record_return_scan( $fb0, '2026-03-30', '2026-04-02' );
Creature_Demo_Loan::record_kit_received( $fb0, '2026-04-02' );
$fb0->update_meta_data( '_creature_demo_damage_pence', 2000 );
Creature_Demo_Loan::tick( $fb0, '2026-04-10' );
creature_demo_expect( 'late_only' === $fb0->get_meta( '_creature_demo_bill_state' ) && 0 === creature_demo_charge_orders_for( $fb0 ), 'a return posted in grace falls back to no bill and no later damage charge' );
creature_demo_expect( empty( creature_demo_damage( $fb0, 2000, 'Damage', '2026-04-11' )['ok'] ), 'no damage charge after the window closes with nothing billed' );
$fb0_post = Creature_Demo_Loan::record_return_posted( $fb0, '2026-03-20' );
creature_demo_expect( empty( $fb0_post['ok'] ) && 'billed' === $fb0_post['reason'], 'the posting date is locked once the bill is final' );

// Item 2: a scan with no Kit received date starts no clock. The shop is told once. Entering the date starts it.
$nr = creature_demo_loan();
$nr_notices = creature_demo_mail_count( 'admin_received_date_missing' );
$nr_warns   = creature_demo_mail_count( 'admin_bill_due' );
$nr_mails   = creature_demo_charge_mail_count();
Creature_Demo_Loan::record_return_scan( $nr, '2026-04-02', '2026-04-02' );
creature_demo_expect( '' === $nr->get_meta( '_creature_demo_received_date' ), 'a scan with no received date leaves the received date empty' );
creature_demo_expect( $nr_notices + 1 === creature_demo_mail_count( 'admin_received_date_missing' ), 'the shop is told once that a scanned return has no Kit received date' );
$nr_mail = creature_demo_last_mail( 'admin_received_date_missing' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $nr_mail['to'] && 0 !== strpos( $nr_mail['subject'], '[TEST]' ) && false !== strpos( $nr_mail['body'], 'no Kit received date' ), 'the notice goes to the shop and says what is missing' );
foreach ( array( '2026-04-08', '2026-04-09', '2026-04-10', '2026-04-20', '2026-05-20' ) as $nr_day ) {
	Creature_Demo_Loan::tick( $nr, $nr_day );
}
creature_demo_expect( $nr_notices + 1 === creature_demo_mail_count( 'admin_received_date_missing' ), 'the notice is not repeated by the daily check' );
creature_demo_expect( $nr_warns === creature_demo_mail_count( 'admin_bill_due' ) && '' === $nr->get_meta( '_creature_demo_bill_state' ) && 0 === creature_demo_charge_orders_for( $nr ) && $nr_mails === creature_demo_charge_mail_count(), 'with no received date there is no day-4 alert and no fallback bill, however late' );
Creature_Demo_Loan::record_return_scan( $nr, '2026-04-03', '2026-04-03' );
creature_demo_expect( $nr_notices + 1 === creature_demo_mail_count( 'admin_received_date_missing' ), 'a repeated scan does not send the notice again' );
creature_demo_expect( ! empty( Creature_Demo_Loan::record_kit_received( $nr, '2026-06-01' )['ok'] ), 'the received date is then entered by hand' );
Creature_Demo_Loan::tick( $nr, '2026-06-04' );
creature_demo_expect( $nr_warns === creature_demo_mail_count( 'admin_bill_due' ) && '' === $nr->get_meta( '_creature_demo_bill_state' ), 'the clock runs from the entered date: Thursday 4 June is working day 3, so nothing yet' );
Creature_Demo_Loan::tick( $nr, '2026-06-05' );
creature_demo_expect( $nr_warns + 1 === creature_demo_mail_count( 'admin_bill_due' ) && '' === $nr->get_meta( '_creature_demo_bill_state' ), 'working day 4 from the entered date sends the alert' );
Creature_Demo_Loan::tick( $nr, '2026-06-08' );
creature_demo_expect( 'late_only' === $nr->get_meta( '_creature_demo_bill_state' ), 'working day 5 from the entered date sends the fallback bill' );
$nr_insp = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $nr_insp, '2026-03-30', '2026-03-30' );
creature_demo_inspect( $nr_insp, '2026-03-30' );
creature_demo_expect( '' === $nr_insp->get_meta( '_creature_demo_received_date' ), 'recording the inspection does not set a received date either' );

// A manual received date is kept when the scan is recorded.
$rc = creature_demo_loan();
Creature_Demo_Loan::record_kit_received( $rc, '2026-04-06' );
Creature_Demo_Loan::record_return_scan( $rc, '2026-04-02', '2026-04-02' );
creature_demo_expect( '2026-04-06' === $rc->get_meta( '_creature_demo_received_date' ), 'a received date entered by hand is kept when the scan is recorded' );
$rc_bad = Creature_Demo_Loan::record_kit_received( $rc, '2026-02-30' );
creature_demo_expect( empty( $rc_bad['ok'] ), 'an impossible received date is refused' );

// Damage evidence (terms 6.4(b)): findings are required and the photo slots feed the bill.
$nf = creature_demo_loan();
$nf->update_meta_data( '_creature_demo_findings', '' );
Creature_Demo_Loan::record_return_scan( $nf, '2026-03-30', '2026-03-30' );
$nf->update_meta_data( '_creature_demo_damage_pence', 2000 );
$nf_mails = creature_demo_charge_mail_count();
$nf_r = creature_demo_inspect( $nf, '2026-03-30' );
creature_demo_expect( empty( $nf_r['ok'] ) && 'no_findings' === $nf_r['reason'], 'a bill with a damage line and empty findings is refused' );
creature_demo_expect( 0 === creature_demo_charge_orders_for( $nf ) && $nf_mails === creature_demo_charge_mail_count(), 'a refused bill creates no order and sends no email' );
creature_demo_expect( '' === $nf->get_meta( '_creature_demo_inspection_done' ) && '' === $nf->get_meta( '_creature_demo_bill_state' ), 'a refused bill leaves the inspection open to try again' );
$nf->update_meta_data( '_creature_demo_findings', "  \n  " );
creature_demo_expect( 'no_findings' === Creature_Demo_Loan::raise_bill( $nf, '2026-03-30' )['reason'], 'findings of only spaces count as empty' );
creature_demo_expect( false !== strpos( implode( "\n", $nf->notes ), 'findings' ), 'the refusal is noted on the order' );
$nm = creature_demo_loan();
$nm->update_meta_data( '_creature_demo_findings', '' );
$nm->update_meta_data( '_creature_demo_missing_pence', 3500 );
Creature_Demo_Loan::record_kit_received( $nm, '2026-03-30' );
creature_demo_expect( 'no_findings' === Creature_Demo_Loan::raise_bill( $nm, '2026-03-30' )['reason'], 'a missing-parts line needs findings too' );
$nl = creature_demo_loan();
$nl->update_meta_data( '_creature_demo_findings', '' );
Creature_Demo_Loan::record_return_scan( $nl, '2026-04-02', '2026-04-02' );
$nl_r = Creature_Demo_Loan::raise_bill( $nl, '2026-04-02' );
creature_demo_expect( ! empty( $nl_r['ok'] ) && 1200 === $nl_r['amount'], 'a late-fee-only bill needs no findings' );
// Item 7: findings alone are not enough. A damage or missing-parts line needs at least one photo.
$np = creature_demo_loan();
$np->update_meta_data( '_creature_demo_photos', array() );
Creature_Demo_Loan::record_return_scan( $np, '2026-03-30', '2026-03-30' );
$np->update_meta_data( '_creature_demo_damage_pence', 2000 );
$np_mails = creature_demo_charge_mail_count();
$np_r     = creature_demo_inspect( $np, '2026-03-30' );
creature_demo_expect( empty( $np_r['ok'] ) && 'no_photos' === $np_r['reason'], 'a damage line with findings but no photo is refused' );
creature_demo_expect( 0 === creature_demo_charge_orders_for( $np ) && $np_mails === creature_demo_charge_mail_count() && '' === $np->get_meta( '_creature_demo_bill_state' ) && '' === $np->get_meta( '_creature_demo_inspection_done' ), 'the refused bill makes no order, sends no email and leaves the inspection open' );
creature_demo_expect( false !== strpos( implode( "\n", $np->notes ), 'there is no photo' ), 'the refusal says a photo is missing' );
$np->update_meta_data( '_creature_demo_photos', array( 'not a url', 'ftp://example.com/x.jpg', '0' ) );
creature_demo_expect( 'no_photos' === Creature_Demo_Loan::raise_bill( $np, '2026-03-30' )['reason'], 'photo slots that are not valid photos do not count' );
creature_demo_expect( 'no_photos' === creature_demo_damage( $np, 2000, 'Damage', '2026-03-30' )['reason'], 'raise_damage_charge needs a photo too' );
Creature_Demo_Loan::action_damage( $np );
creature_demo_expect( 0 === creature_demo_charge_orders_for( $np ) && $np_mails === creature_demo_charge_mail_count(), 'the admin Email a damage charge action sends nothing without a photo' );
$npm = creature_demo_loan();
$npm->update_meta_data( '_creature_demo_photos', array() );
$npm->update_meta_data( '_creature_demo_missing_pence', 3500 );
Creature_Demo_Loan::record_kit_received( $npm, '2026-03-30' );
creature_demo_expect( 'no_photos' === Creature_Demo_Loan::raise_bill( $npm, '2026-03-30' )['reason'], 'a missing-parts line needs a photo too' );
// raise_bill: no damage or missing-parts line until the Kit received date is entered (terms 6.4(b)).
foreach ( array( '_creature_demo_damage_pence' => 'damage', '_creature_demo_missing_pence' => 'missing parts' ) as $rb_key => $rb_what ) {
	$rb = creature_demo_loan();
	Creature_Demo_Loan::record_return_scan( $rb, '2026-04-02', '2026-04-02' );
	$rb->update_meta_data( $rb_key, 2000 );
	$rb_mails = creature_demo_charge_mail_count();
	$rb_r     = Creature_Demo_Loan::raise_bill( $rb, '2026-04-02' );
	creature_demo_expect( empty( $rb_r['ok'] ) && 'no_received_date' === $rb_r['reason'], "raise_bill: a {$rb_what} line is refused with no Kit received date" );
	creature_demo_expect( 0 === creature_demo_charge_orders_for( $rb ) && $rb_mails === creature_demo_charge_mail_count() && '' === $rb->get_meta( '_creature_demo_bill_state' ), "raise_bill: nothing is created or emailed for {$rb_what} without the date" );
	creature_demo_expect( empty( Creature_Demo_Loan::complete_inspection( $rb, '2026-04-02' )['ok'] ) && 'yes' !== $rb->get_meta( '_creature_demo_inspection_done' ), "raise_bill: recording the inspection is refused the same way for {$rb_what}" );
	Creature_Demo_Loan::record_kit_received( $rb, '2026-04-02' );
	$rb_ok = Creature_Demo_Loan::raise_bill( $rb, '2026-04-02' );
	creature_demo_expect( ! empty( $rb_ok['ok'] ) && 3200 === $rb_ok['amount'], "raise_bill: once the date is entered the {$rb_what} line goes on the bill (£12 late fee + £20)" );
}
$rbd = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $rbd, '2026-04-02', '2026-04-02' );
$rbd_r = Creature_Demo_Loan::raise_damage_charge( $rbd, 2000, 'Damage', '2026-04-02' );
creature_demo_expect( empty( $rbd_r['ok'] ) && 'no_received_date' === $rbd_r['reason'] && 0 === creature_demo_charge_orders_for( $rbd ), 'raise_bill: the damage charge action is refused the same way' );
$rbl = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $rbl, '2026-04-02', '2026-04-02' );
$rbl_r = Creature_Demo_Loan::raise_bill( $rbl, '2026-04-02' );
creature_demo_expect( ! empty( $rbl_r['ok'] ) && 1200 === $rbl_r['amount'], 'raise_bill: a late-fee-only bill still needs no received date' );
$npl = creature_demo_loan();
$npl->update_meta_data( '_creature_demo_photos', array() );
Creature_Demo_Loan::record_return_scan( $npl, '2026-04-02', '2026-04-02' );
creature_demo_expect( ! empty( Creature_Demo_Loan::raise_bill( $npl, '2026-04-02' )['ok'] ), 'a late-fee-only bill needs no photo' );
$np->update_meta_data( '_creature_demo_photos', array( 'https://example.com/uploads/crack.jpg' ) );
$np_r2 = creature_demo_inspect( $np, '2026-03-30' );
creature_demo_expect( ! empty( $np_r2['ok'] ) && 1 === creature_demo_charge_orders_for( $np ), 'with a photo added the same bill goes out' );
ob_start();
Creature_Demo_Loan::render_meta_box( $np );
$np_html = ob_get_clean();
creature_demo_expect( false !== strpos( $np_html, 'at least one photo' ), 'the order screen tells the admin a photo is required' );

$nf->update_meta_data( '_creature_demo_findings', 'Steerer cap cracked at the slot.' );
$nf->update_meta_data( '_creature_demo_photos', array( '123', 'https://example.com/a.jpg', 'ftp://example.com/b.jpg', 'javascript:alert(1)', 'not a url', '0', '456 789' ) );
$nf_r2 = creature_demo_inspect( $nf, '2026-03-30' );
creature_demo_expect( ! empty( $nf_r2['ok'] ) && 2300 === $nf_r2['bill']['amount'], 'with findings the bill goes out: £3 late fee and £20 damage' );
$nf_mail = creature_demo_last_mail( 'charge_notice_damage' );
creature_demo_expect( false !== strpos( $nf_mail['body'], 'Steerer cap cracked at the slot.' ), 'the findings go into the bill as {findings}' );
creature_demo_expect( false !== strpos( $nf_mail['body'], 'https://example.com/wp-content/uploads/123.jpg' ) && false !== strpos( $nf_mail['body'], 'https://example.com/a.jpg' ), 'photos go into the bill as {photos}: a media id becomes its URL and a URL stays' );
creature_demo_absent( $nf_mail['body'], 'javascript:', 'a script link is not a photo' );
creature_demo_absent( $nf_mail['body'], 'ftp://', 'only http and https links are photos' );
creature_demo_absent( $nf_mail['body'], '{findings}', 'the findings tag is filled' );
creature_demo_absent( $nf_mail['body'], '{photos}', 'the photos tag is filled' );
creature_demo_expect( array( '123', 'https://example.com/a.jpg' ) === Creature_Demo_Loan::photo_slots( $nf ), 'invalid photo slots are dropped' );
$six = creature_demo_loan();
$six->update_meta_data( '_creature_demo_photos', array( '1', '2', '3', '4', '5', '6', '7', '8' ) );
creature_demo_expect( 6 === count( Creature_Demo_Loan::photo_slots( $six ) ), 'there are at most 6 photo slots' );
$_POST = array(
	'creature_demo_loan_admin_nonce' => 'creature_demo_loan_admin',
	'creature_demo_damage'           => '20.00',
	'creature_demo_missing'          => '35,00',
	'creature_demo_findings'         => 'Bar clamp thread stripped.',
	'creature_demo_photo_1'          => '321',
	'creature_demo_photo_2'          => 'https://example.com/c.jpg',
	'creature_demo_photo_3'          => 'javascript:alert(1)',
	'creature_demo_photo_7'          => '999',
);
$form = creature_demo_loan();
Creature_Demo_Loan::save_meta_box( $form->get_id(), $form );
creature_demo_expect( 2000 === (int) $form->get_meta( '_creature_demo_damage_pence' ) && 3500 === (int) $form->get_meta( '_creature_demo_missing_pence' ), 'the order screen saves the damage and missing-parts amounts' );
creature_demo_expect( 'Bar clamp thread stripped.' === $form->get_meta( '_creature_demo_findings' ), 'the order screen saves the findings' );
creature_demo_expect( array( '321', 'https://example.com/c.jpg' ) === $form->get_meta( '_creature_demo_photos' ), 'the order screen saves up to 6 valid photo slots' );
ob_start();
Creature_Demo_Loan::render_meta_box( $form );
$form_html = ob_get_clean();
foreach ( array( 'creature_demo_damage', 'creature_demo_missing', 'creature_demo_findings', 'creature_demo_photo_1', 'creature_demo_photo_6' ) as $field ) {
	creature_demo_expect( false !== strpos( $form_html, 'name="' . $field . '"' ), 'the order screen has the ' . $field . ' field' );
}
creature_demo_expect( false === strpos( $form_html, 'creature_demo_photo_7' ), 'the order screen has 6 photo slots, not 7' );
$_POST = array();

// Charge after an upheld dispute counts from the final-decision email (terms 4.4(b)).
$ud = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $ud, '2026-03-30', '2026-03-30' );
$ud_charge = creature_demo_bill_charge( $ud );
Creature_Demo_Loan::mark_disputed( $ud_charge, '2026-03-31' );
$ud_mails = creature_demo_mail_count( 'dispute_decision' );
Creature_Demo_Loan::record_dispute_decision( $ud_charge, 'upheld', '2026-04-12', null );
creature_demo_expect( $ud_mails + 1 === creature_demo_mail_count( 'dispute_decision' ), 'the final-decision email is sent once' );
creature_demo_expect( '2026-04-12' === $ud_charge->get_meta( '_creature_demo_decision_sent_at' ), 'the decision-sent time is stored on the charge' );
creature_demo_expect( 'notice_too_recent' === Creature_Demo_Loan::evaluate_charge( $ud, $ud_charge, '2026-04-13' )['code'], 'the day after an upheld decision is too soon, though the first notice was 14 days ago' );
creature_demo_expect( 'notice_too_recent' === Creature_Demo_Loan::evaluate_charge( $ud, $ud_charge, '2026-04-18' )['code'], 'six days after the decision email is too soon' );
creature_demo_expect( '' === Creature_Demo_Loan::evaluate_charge( $ud, $ud_charge, '2026-04-19' )['code'], 'seven days after the decision email clears the gate' );
creature_demo_expect( 6 === Creature_Demo_Loan::evaluate_charge( $ud, $ud_charge, '2026-04-18' )['state']['days_since_notice'], 'the day count runs from the decision email' );
$ud_wv = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $ud_wv, '2026-03-30', '2026-03-30' );
$ud_wv_charge = creature_demo_bill_charge( $ud_wv );
Creature_Demo_Loan::mark_disputed( $ud_wv_charge, '2026-03-31' );
Creature_Demo_Loan::record_dispute_decision( $ud_wv_charge, 'waived', '2026-04-12', null );
creature_demo_expect( 'dispute_waived' === Creature_Demo_Loan::evaluate_charge( $ud_wv, $ud_wv_charge, '2026-04-30' )['code'], 'a waived dispute still refuses the charge' );
$ud_nd = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $ud_nd, '2026-03-30', '2026-03-30' );
$ud_nd_charge = creature_demo_bill_charge( $ud_nd );
creature_demo_expect( '' === $ud_nd_charge->get_meta( '_creature_demo_decision_sent_at' ) && '' === Creature_Demo_Loan::evaluate_charge( $ud_nd, $ud_nd_charge, '2026-04-06' )['code'], 'a charge with no dispute still counts 7 days from the notice' );

// Consent versions read back as the text they stored.
$v4_text  = 'I agree that Creature Cycles Ltd may charge this saved card later, without asking me again at the time, for late fees up to £40.00, a deemed purchase up to £212.00, and damage or missing parts, never more than the cost of repair or replacement (inverted mount kit £35.00). This box is separate from the general terms and is not ticked for me.';
$v5_text  = 'I agree that Creature Cycles Ltd can charge this card later, without asking me again at the time, for: late fees (up to £24), the deemed purchase price if I don\'t send the kit back (£212), and damage or missing parts at published spare-parts prices, including £35 for a missing inverted mount kit. All of these together will never be more than £212. You\'ll email me the amount and the reason first, and I\'ll have 7 days to pay by link or dispute it. (Demo terms, clause 4.)';
$v5b_text = 'I agree that Creature Cycles Ltd can charge this card later, without asking me again at the time, for: late fees (up to £24), the deemed purchase price if I don\'t send the kit back (my keep price, capped at £212), and damage or missing parts at published spare-parts prices, including £35 for a missing inverted mount kit. All of these together will never be more than £212. You\'ll email me the amount and the reason first, and I\'ll have 7 days to pay by link or dispute it. (Demo terms, clause 4.)';
creature_demo_expect( $v4_text === Creature_Demo_Loan_Copy::consent_wording( 'v4' ), 'v4 wording is byte-for-byte what it was' );
creature_demo_expect( $v5_text === Creature_Demo_Loan_Copy::consent_wording( 'v5' ), 'v5 wording is byte-for-byte what it was' );
creature_demo_expect( $v5b_text === Creature_Demo_Loan_Copy::consent_wording( 'v5b' ), 'v5b wording is exactly the agreed text' );
creature_demo_expect( $fixture === Creature_Demo_Loan_Copy::consent_wording(), 'version-5 is the default wording' );
creature_demo_expect( $v5_text === str_replace( '(my keep price, capped at £212)', '(£212)', $v5b_text ) && $v5b_text !== $v5_text, 'v5b differs from v5 only in the one phrase' );
creature_demo_expect( 1 === substr_count( $v5b_text, 'my keep price, capped at £212' ), 'v5b names the keep price once' );
creature_demo_expect( $v5b_text === str_replace( array( '’' ), array( "'" ), $fixture ) && $v5b_text !== $fixture, 'version-5 is v5b with the three published curly apostrophes' );
creature_demo_expect( $fixture === Creature_Demo_Loan_Copy::consent_wording( 'v9-unknown' ), 'a version that matches nothing falls back to version-5' );
creature_demo_expect( $fixture === Creature_Demo_Loan_Copy::consent_wording( '' ), 'an empty version falls back to version-5' );
creature_demo_expect( $v4_text !== Creature_Demo_Loan_Copy::consent_wording( 'v5b' ) && $v4_text !== $v5_text && $v4_text !== $fixture && $v5_text !== $fixture, 'the four versions are four different texts' );
creature_demo_expect( array( 'version-5', 'v5b', 'v5', 'v4' ) === Creature_Demo_Loan_Copy::consent_versions(), 'the plugin knows version-5, v5b, v5 and v4' );
foreach ( array( 'version-5', 'v5b' ) as $v5_key ) {
	creature_demo_expect( 'Terms version 5' === Creature_Demo_Loan_Copy::consent_version_label( $v5_key ), "{$v5_key} reads back as Terms version 5" );
}
creature_demo_expect( 'Terms version 4' === Creature_Demo_Loan_Copy::consent_version_label( 'v4' ), 'v4 reads back as Terms version 4' );
creature_demo_expect( 'Terms version 5' !== Creature_Demo_Loan_Copy::consent_version_label( 'v5' ) && false !== strpos( Creature_Demo_Loan_Copy::consent_version_label( 'v5' ), 'earlier draft' ), 'the old v5 draft is not passed off as Terms version 5' );
// A filter naming a version the plugin does not know stores version-5, so the stored version matches the wording.
$GLOBALS['creature_demo_consent_version'] = 'v9-unknown';
creature_demo_expect( 'version-5' === Creature_Demo_Loan::consent_version(), 'an unknown version string from the filter falls back to version-5' );
$odd_order = wc_create_order();
Creature_Demo_Loan::store_consent( $odd_order, true, '2026-03-02T12:00:00+00:00' );
creature_demo_expect( 'version-5' === $odd_order->get_meta( '_creature_demo_consent_version' ) && $fixture === $odd_order->get_meta( '_creature_demo_consent_wording' ), 'an unknown version stores version-5 and its wording' );
$GLOBALS['creature_demo_consent_version'] = 'v5b';
creature_demo_expect( 'v5b' === Creature_Demo_Loan::consent_version(), 'the filter can still name v5b' );
$GLOBALS['creature_demo_consent_version'] = null;
foreach ( array( 'v4' => $v4_text, 'v5' => $v5_text ) as $ver => $text ) {
	$GLOBALS['creature_demo_consent_version'] = $ver;
	$old_order = wc_create_order();
	Creature_Demo_Loan::store_consent( $old_order, true, '2026-03-02T12:00:00+00:00' );
	creature_demo_expect( $ver === $old_order->get_meta( '_creature_demo_consent_version' ), "an order stored as {$ver} reads back {$ver}" );
	creature_demo_expect( $text === $old_order->get_meta( '_creature_demo_consent_wording' ), "an order stored as {$ver} keeps the {$ver} wording" );
}
$GLOBALS['creature_demo_consent_version'] = null;
$new_order = creature_demo_booked_loan( '249.00' );
creature_demo_expect( 'version-5' === $new_order->get_meta( '_creature_demo_consent_version' ), 'a new order reads back version-5' );
// Orders that stored v5b, v5 or v4 read back exactly as they stored them. The admin record says Terms version 5 for version-5 and v5b.
foreach ( array(
	'version-5' => array( 'Terms version 5', $fixture ),
	'v5b'       => array( 'Terms version 5', $v5b_text ),
	'v5'        => array( 'Terms version 5 (earlier draft, stored as v5)', $v5_text ),
	'v4'        => array( 'Terms version 4', $v4_text ),
) as $stored => $expect ) {
	$GLOBALS['creature_demo_consent_version'] = $stored;
	$rb = wc_create_order();
	Creature_Demo_Loan::store_consent( $rb, true, '2026-03-02T12:00:00+00:00' );
	$GLOBALS['creature_demo_consent_version'] = null;
	$rec = Creature_Demo_Loan::consent_record( $rb );
	creature_demo_expect( $stored === $rec['version'] && $expect[0] === $rec['label'] && $expect[1] === $rec['wording'], "an order stored as {$stored} reads back as {$expect[0]} with its own wording" );
	creature_demo_expect( '2 March 2026' === $rec['date'], "the {$stored} record shows the date as 2 March 2026" );
	$rb->update_meta_data( '_creature_demo_loan', 'yes' );
	ob_start();
	Creature_Demo_Loan::render_meta_box( $rb );
	$rb_html = ob_get_clean();
	creature_demo_expect( false !== strpos( $rb_html, 'Consent: ' . $expect[0] ), "the order screen shows {$expect[0]} for {$stored}" );
}
$rb_empty = wc_create_order();
creature_demo_expect( '' === Creature_Demo_Loan::consent_record( $rb_empty )['label'], 'an order with no consent has no consent label' );

// The two deemed emails quote the computed price.
$mail192 = creature_demo_booked_loan( '229.00' );
Creature_Demo_Loan::tick( $mail192, '2026-04-03' );
$warn192 = creature_demo_last_mail( 'reminder_deemed_warning' );
creature_demo_expect( is_array( $warn192 ) && false !== strpos( $warn192['body'], 'at your keep price of £192.00.' ), 'the deemed warning on a £192 loan quotes £192.00' );
creature_demo_expect( false === strpos( $warn192['body'], 'keep price of £212' ), 'the deemed warning does not quote £212 as the price' );
creature_demo_expect( false === strpos( $warn192['body'], '£212' ), 'the deemed warning on a £192 loan names no £212' );
creature_demo_expect( false === strpos( $warn192['body'], '{price}' ), 'the deemed warning leaves no placeholder behind' );
$notice192 = creature_demo_booked_loan( '229.00' );
$notice192->update_meta_data( '_creature_demo_late_fees_paid_pence', 2000 );
$notice192_r = Creature_Demo_Loan::maybe_raise_deemed( $notice192, '2026-04-07' );
$deemed_mail = creature_demo_last_mail( 'charge_notice_deemed' );
creature_demo_expect( ! empty( $notice192_r['ok'] ) && is_array( $deemed_mail ), 'the deemed charge notice is sent' );
creature_demo_expect( false !== strpos( $deemed_mail['body'], 'Amount: £172.00' ), 'the deemed notice quotes the amount in the payment link' );
creature_demo_expect( false !== strpos( $deemed_mail['body'], 'for your keep price of £192.00 less any late fees you’ve already paid' ), 'the deemed notice quotes the £192.00 keep price' );
creature_demo_expect( false === strpos( $deemed_mail['body'], '£212' ), 'the deemed notice on a £192 loan does not quote £212' );
creature_demo_expect( false === strpos( $deemed_mail['body'], '{price}' ), 'the deemed notice leaves no placeholder behind' );
$mail_fallback = creature_demo_loan();
Creature_Demo_Loan::tick( $mail_fallback, '2026-04-03' );
$warn_fallback = creature_demo_last_mail( 'reminder_deemed_warning' );
creature_demo_expect( false !== strpos( $warn_fallback['body'], 'at your keep price of £212.00.' ), 'the deemed warning on a fallback loan quotes £212.00' );
$mail_floor = creature_demo_booked_loan( '30.00' );
Creature_Demo_Loan::tick( $mail_floor, '2026-04-03' );
$warn_floor = creature_demo_last_mail( 'reminder_deemed_warning' );
creature_demo_expect( is_array( $warn_floor ) && false !== strpos( $warn_floor['body'], 'at your keep price of £212.00.' ), 'the deemed warning on a fallback_below_floor loan quotes £212.00' );
$template = Creature_Demo_Loan_Copy::catalogue();
creature_demo_expect( false !== strpos( $template['reminder_deemed_warning']['body'], 'keep price of {price}.' ) && false !== strpos( $template['charge_notice_deemed']['body'], 'keep price of {price} less' ), 'both deemed emails are templates with a price placeholder' );

$GLOBALS['creature_demo_product_prices'] = array();

// ---- Three emails Cadence has not written: dates_on_delivery, charge_declined, booking_confirmation ----
add_action(
	'creature_demo_loan_mail_dates_on_delivery',
	static function ( $row, $order, $args ) {
		$GLOBALS['creature_demo_hook_args']['dates_on_delivery'][] = array( $row, $order, $args );
	},
	10,
	3
);
add_action(
	'creature_demo_loan_mail_charge_declined',
	static function ( $row, $order, $args ) {
		$GLOBALS['creature_demo_hook_args']['charge_declined'][] = array( $row, $order, $args );
	},
	10,
	3
);
add_filter(
	'creature_demo_loan_confirmation_files',
	static function ( $files ) {
		return array_merge( (array) $files, $GLOBALS['creature_demo_conf_files'] );
	}
);

// dates_on_delivery (terms 3.2): once, when the loan is marked delivered.
$dod_before = creature_demo_mail_count( 'dates_on_delivery' );
$calls      = $GLOBALS['creature_demo_mail_calls'];
$dod        = creature_demo_loan();
creature_demo_expect( $dod_before + 1 === creature_demo_mail_count( 'dates_on_delivery' ), 'dates_on_delivery goes out when a loan starts with a tracked delivery date' );
creature_demo_expect( ! Creature_Demo_Loan::send_dates_on_delivery( $dod ) && $dod_before + 1 === creature_demo_mail_count( 'dates_on_delivery' ), 'dates_on_delivery fires once per loan' );
$dod_hook = end( $GLOBALS['creature_demo_hook_args']['dates_on_delivery'] );
creature_demo_expect( '2026-03-24' === $dod_hook[2]['due'] && '2026-03-29' === $dod_hook[2]['grace_end'] && '2026-04-07' === $dod_hook[2]['deemed_on'], 'it carries the due date, the last day of grace and the deemed purchase date' );
creature_demo_expect( false === $dod_hook[0]['empty'] && $calls < $GLOBALS['creature_demo_mail_calls'] && 'Your De-Reacher demo dates: due back 24 March 2026' === preg_replace( '/^\[TEST\] /', '', $dod_hook[0]['subject'] ), 'with the v2 wording the dates email is a real email with the UK due date' );
creature_demo_expect( 'Rider@Example.com' === $dod_hook[1]->get_billing_email() && 'demo-loan-test@creaturecycles.co.uk' === $dod_hook[0]['to'], 'the dates email is a customer email, redirected like the others' );
creature_demo_expect( '24 March 2026 29 March 2026 7 April 2026' === Creature_Demo_Loan_Copy::fill( '{due} {grace_end} {deemed_on}', $dod_hook[2] ), 'the three dates read in the UK format' );
$dod_est = wc_create_order();
Creature_Demo_Loan::start_loan( $dod_est, '2026-03-02', '' );
creature_demo_expect( $dod_before + 1 === creature_demo_mail_count( 'dates_on_delivery' ), 'an estimated delivery date is not a delivery, so no dates email yet' );
Creature_Demo_Loan::correct_delivery_date( $dod_est, '2026-03-06' );
creature_demo_expect( $dod_before + 2 === creature_demo_mail_count( 'dates_on_delivery' ), 'the tracked delivery date sends the dates email' );
Creature_Demo_Loan::correct_delivery_date( $dod_est, '2026-03-09' );
creature_demo_expect( $dod_before + 2 === creature_demo_mail_count( 'dates_on_delivery' ), 'a later correction does not send it again' );
$dod_copy = $GLOBALS['creature_demo_hook_args']['dates_on_delivery'];
creature_demo_expect( '2026-03-26' === $dod_copy[ count( $dod_copy ) - 1 ][2]['due'], 'the email used the due date from the tracked delivery' );
// With wording supplied, it is a real email with Reply-To.
$GLOBALS['creature_demo_copy_override']['dates_on_delivery'] = array(
	'subject' => 'Your dates',
	'body'    => 'Due {due}. Grace ends {grace_end}. Deemed {deemed_on}.',
);
$dod_wired = creature_demo_loan();
$dod_wired_mail = creature_demo_last_mail( 'dates_on_delivery' );
creature_demo_expect( false === $dod_wired_mail['empty'] && 'Due 24 March 2026. Grace ends 29 March 2026. Deemed 7 April 2026.' === $dod_wired_mail['body'], 'once wording is set the dates email carries the UK dates' );
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();

// charge_declined (terms 4.5): a declined saved card gets a payment link, once.
creature_demo_stripe_test();
$decl         = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $decl, '2026-03-30', '2026-03-30' );
$decl_charge  = creature_demo_bill_charge( $decl );
$decl_before  = creature_demo_mail_count( 'charge_declined' );
$auth_before  = creature_demo_mail_count( 'charge_authentication_required' );
WC_Stripe_API::$response = (object) array(
	'error' => (object) array(
		'code'         => 'card_declined',
		'decline_code' => 'generic_decline',
	),
);
$decl_r = Creature_Demo_Loan::charge_saved_card( $decl, '2026-04-06' );
creature_demo_expect( 'stripe_error' === $decl_r['code'] && $decl_before + 1 === creature_demo_mail_count( 'charge_declined' ), 'a declined card sends the charge_declined email' );
$decl_hook = end( $GLOBALS['creature_demo_hook_args']['charge_declined'] );
creature_demo_expect( false !== strpos( $decl_hook[2]['link'], 'https://staging.example/checkout/order-pay/' . $decl_charge->get_id() ) && '£3.00' === $decl_hook[2]['amount'], 'it carries the payment link and the amount owed' );
creature_demo_expect( 'yes' !== $decl_charge->get_meta( '_creature_demo_charge_paid' ) && false !== strpos( implode( "\n", $decl->notes ), 'still owed' ), 'the amount is still owed and the order says so' );
Creature_Demo_Loan::charge_saved_card( $decl, '2026-04-07' );
creature_demo_expect( $decl_before + 1 === creature_demo_mail_count( 'charge_declined' ), 'a second decline on the same charge does not email again' );
creature_demo_expect( $auth_before === creature_demo_mail_count( 'charge_authentication_required' ), 'a decline is not the authentication email' );
$decl_api = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $decl_api, '2026-03-30', '2026-03-30' );
creature_demo_bill_charge( $decl_api );
WC_Stripe_API::$response = (object) array( 'error' => (object) array( 'code' => 'api_error' ) );
Creature_Demo_Loan::charge_saved_card( $decl_api, '2026-04-06' );
creature_demo_expect( $decl_before + 1 === creature_demo_mail_count( 'charge_declined' ), 'an API fault is not a decline and sends no declined email' );
$decl_auth = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $decl_auth, '2026-03-30', '2026-03-30' );
creature_demo_bill_charge( $decl_auth );
WC_Stripe_API::$response = new WP_Error( 'stripe_error', 'authentication_required' );
Creature_Demo_Loan::charge_saved_card( $decl_auth, '2026-04-06' );
creature_demo_expect( $decl_before + 1 === creature_demo_mail_count( 'charge_declined' ) && $auth_before + 1 === creature_demo_mail_count( 'charge_authentication_required' ), 'authentication required keeps its own email and sends no declined email' );
WC_Stripe_API::$response = (object) array( 'id' => 'pi_test_demo_9', 'status' => 'succeeded' );

// booking_confirmation (terms 11.1, Appendix B): content on the order confirmation, and the two files.
$bc_email = (object) array( 'id' => 'customer_processing_order' );
$bc_order = creature_demo_booked_loan( '249.00' );
$bc_order->update_meta_data( '_creature_demo_express_request', 'yes' );
$bc_plain = wc_create_order();
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $bc_order, false, false, $bc_email );
$bc_v2 = ob_get_clean();
creature_demo_expect( false !== strpos( $bc_v2, 'De-Reacher demo booking (order ' . $bc_order->get_id() . ')' ) && false !== strpos( $bc_v2, 'Your keep price: £' ), 'with the v2 wording the confirmation gets the booking block' );
// D1: no checkbox label (the slot is empty), so the rider was never asked. The whole sentence goes, even with an answer stored.
creature_demo_absent( $bc_v2, 'Send my kit now', 'D1: with an empty express_request_checkbox the booking confirmation drops the whole "Send my kit now" sentence' );
creature_demo_absent( $bc_v2, 'If you answered Yes', 'D1: and its Yes / No follow-ups' );
creature_demo_absent( $bc_v2, 'answer to', 'D1: no dangling "answer to" text' );
creature_demo_expect( false !== strpos( $bc_v2, 'Your keep price: £' ) && false !== strpos( $bc_v2, 'Your consent to later charges' ) && false !== strpos( $bc_v2, 'Cancelling: you can cancel' ), 'D1: the paragraphs either side of the dropped sentence are kept' );
creature_demo_absent( $bc_v2, "\n\n\n", 'D1: dropping the sentence leaves no extra blank lines' );
// D1: a label is set but the answer is blank (never recorded): dropped too.
$GLOBALS['creature_demo_copy_override']['express_request_checkbox'] = array( 'subject' => '', 'body' => 'Test label for the express request.' );
$bc_blank = creature_demo_booked_loan( '249.00' );
$bc_blank->update_meta_data( '_creature_demo_express_request', '' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $bc_blank, false, false, $bc_email );
$bc_blank_out = ob_get_clean();
creature_demo_expect( false !== strpos( $bc_blank_out, 'De-Reacher demo booking' ) && false === strpos( $bc_blank_out, 'Send my kit now' ) && false === strpos( $bc_blank_out, 'If you answered' ), 'D1: a label with a blank answer drops the sentence too' );
// D1: a label and an answer: the sentence is there.
$bc_answered = creature_demo_booked_loan( '249.00' );
$bc_answered->update_meta_data( '_creature_demo_express_request', 'yes' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $bc_answered, false, false, $bc_email );
creature_demo_expect( false !== strpos( ob_get_clean(), 'Your answer to “Send my kit now”: Yes.' ), 'D1: with a label and an answer the sentence is shown' );
$GLOBALS['creature_demo_copy_override']['express_request_checkbox'] = array(
	'subject' => '',
	'body'    => '',
);
$GLOBALS['creature_demo_copy_override']['booking_confirmation'] = array(
	'subject' => '',
	'body'    => "Consent: {consent_version} on {consent_at}.\n{consent}\nExpress request: {express_answer}.",
);
$bc_order->update_meta_data( '_creature_demo_booking_block_sent', '' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $bc_order, false, false, $bc_email );
$bc_html = ob_get_clean();
creature_demo_expect( false !== strpos( $bc_html, 'Consent: Terms version 5 on ' . Creature_Demo_Loan::consent_record( $bc_order )['date'] . '.' ) && '' !== Creature_Demo_Loan::consent_record( $bc_order )['date'], 'the confirmation names the consent version and the date' );
creature_demo_expect( false !== strpos( $bc_html, str_replace( '&#039;', '’', htmlspecialchars( $fixture, ENT_QUOTES, 'UTF-8' ) ) ) || false !== strpos( $bc_html, $fixture ), 'the confirmation carries the consent wording the rider agreed to' );
creature_demo_expect( false !== strpos( $bc_html, 'Express request: Yes.' ), 'the confirmation gives the rider’s answer to the express request' );
$bc_order->update_meta_data( '_creature_demo_booking_block_sent', '' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $bc_order, false, true, $bc_email );
$bc_text = ob_get_clean();
creature_demo_expect( false !== strpos( $bc_text, 'Express request: Yes.' ) && false === strpos( $bc_text, '<p>' ), 'the plain text email gets the same content without markup' );
$bc_order->update_meta_data( '_creature_demo_express_request', 'no' );
$bc_order->update_meta_data( '_creature_demo_booking_block_sent', '' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $bc_order, false, true, $bc_email );
creature_demo_expect( false !== strpos( ob_get_clean(), 'Express request: No.' ), 'a "no" to the express request reads No' );
$bc_order->update_meta_data( '_creature_demo_booking_block_sent', '' );
foreach ( array(
	'a non-demo order'        => array( $bc_plain, false, $bc_email ),
	'the admin copy'          => array( $bc_order, true, $bc_email ),
	'another email'           => array( $bc_order, false, (object) array( 'id' => 'customer_completed_order' ) ),
	'no email object'         => array( $bc_order, false, null ),
) as $label => $case ) {
	ob_start();
	Creature_Demo_Loan::render_booking_confirmation( $case[0], $case[1], false, $case[2] );
	creature_demo_expect( '' === ob_get_clean(), "the booking confirmation content is not added for {$label}" );
}
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();

// Item B, terms 11.3 and Appendix B: the "Send my kit now" checkbox, stored as _creature_demo_express_request.
creature_demo_expect( '' === Creature_Demo_Loan::express_label() && '' === Creature_Demo_Loan::express_checkbox_html() && null === Creature_Demo_Loan::express_block_field_definition(), 'v2 has no label for the checkbox, so none is shown and no block field is defined' );
$ex_none = creature_demo_booked_loan( '249.00' );
creature_demo_expect( '' === $ex_none->get_meta( '_creature_demo_express_request' ) && '' === Creature_Demo_Loan::express_answer( $ex_none ), 'with no label nothing is stored, so an unasked question is never recorded as a no' );
$GLOBALS['creature_demo_copy_override']['express_request_checkbox'] = array(
	'subject' => '',
	'body'    => 'Test label for the express request.',
);
$ex_html = Creature_Demo_Loan::express_checkbox_html();
creature_demo_expect( false !== strpos( $ex_html, 'name="creature_demo_express_request"' ) && false !== strpos( $ex_html, 'Test label for the express request.' ) && false === strpos( $ex_html, 'checked' ), 'with a label the checkbox is shown, not ticked in advance' );
$GLOBALS['creature_demo_cart']        = array( array( 'product_id' => 9001, 'variation_id' => 0 ) );
$GLOBALS['creature_demo_use_product'] = true;
ob_start();
Creature_Demo_Loan::on_before_submit();
$ex_checkout = ob_get_clean();
creature_demo_expect( false !== strpos( $ex_checkout, 'creature_demo_loan_consent' ) && false !== strpos( $ex_checkout, 'creature_demo_express_request' ), 'the demo checkout shows the consent box and the express box' );
$GLOBALS['creature_demo_cart'] = array( array( 'product_id' => 50 ) );
ob_start();
Creature_Demo_Loan::on_before_submit();
creature_demo_expect( '' === ob_get_clean(), 'a basket that is not a demo shows neither' );
creature_demo_clear_cart();
$GLOBALS['creature_demo_use_product'] = false;
$ex_yes = creature_demo_booked_loan( '249.00', array( 'creature_demo_express_request' => '1' ) );
$ex_no  = creature_demo_booked_loan( '249.00' );
creature_demo_expect( 'yes' === $ex_yes->get_meta( '_creature_demo_express_request' ) && 'Yes' === Creature_Demo_Loan::express_answer( $ex_yes ), 'ticked: stored as yes' );
creature_demo_expect( 'no' === $ex_no->get_meta( '_creature_demo_express_request' ) && 'No' === Creature_Demo_Loan::express_answer( $ex_no ), 'unticked: stored as no' );
foreach ( array( 'ticked' => $ex_yes, 'unticked' => $ex_no ) as $ex_label => $ex_order ) {
	foreach ( array( false, true ) as $ex_plain ) {
		$ex_order->update_meta_data( '_creature_demo_booking_block_sent', '' );
		ob_start();
		Creature_Demo_Loan::render_booking_confirmation( $ex_order, false, $ex_plain, $bc_email );
		$ex_out = ob_get_clean();
		creature_demo_expect( false !== strpos( $ex_out, 'Your answer to “Send my kit now”: ' . ( $ex_order === $ex_yes ? 'Yes' : 'No' ) . '.' ), "{$ex_label}: the booking confirmation shows the answer" . ( $ex_plain ? ' (plain text)' : '' ) );
	}
}
ob_start();
Creature_Demo_Loan::render_meta_box( $ex_yes );
$ex_admin_yes = ob_get_clean();
ob_start();
Creature_Demo_Loan::render_meta_box( $ex_no );
$ex_admin_no = ob_get_clean();
ob_start();
Creature_Demo_Loan::render_meta_box( $ex_none );
$ex_admin_none = ob_get_clean();
foreach ( array( $ex_admin_yes, $ex_admin_no, $ex_admin_none ) as $ex_admin_html ) {
	creature_demo_expect( false !== strpos( $ex_admin_html, 'Admin step for an 11.3 cancellation and for a 10.4 refund: tick &ldquo;Refunded under 10.4/11 (no £25 code)&rdquo;' ), 'item 6: the order screen tells the admin to tick Refunded under 10.4/11 for an 11.3 cancellation and a 10.4 refund' );
}
creature_demo_expect( false !== strpos( $ex_admin_yes, 'Send my kit now (terms 11.3, asked at checkout): Yes.' ) && false !== strpos( $ex_admin_no, 'asked at checkout): No.' ) && false !== strpos( $ex_admin_none, 'asked at checkout): not asked.' ), 'the admin order screen shows Yes, No or not asked' );
creature_demo_expect( 'yes' !== $ex_yes->get_meta( '_creature_demo_refund_done' ) && '' === $ex_yes->get_meta( '_creature_demo_refund_pence' ), 'ticking it adds no refund logic: the pro-rata refund stays manual' );
// Block checkout.
$ex_def = Creature_Demo_Loan::express_block_field_definition();
creature_demo_expect( is_array( $ex_def ) && 'creature/demo-express-request' === $ex_def['id'] && 'checkbox' === $ex_def['type'] && false === $ex_def['required'], 'the block checkout has an optional checkbox field' );
$GLOBALS['creature_demo_cart']        = array( array( 'product_id' => 9001, 'variation_id' => 0 ) );
$GLOBALS['creature_demo_use_product'] = true;
$ex_b_yes = wc_create_order();
Creature_Demo_Loan::on_store_api_order( $ex_b_yes, array( 'additional_fields' => array( 'creature/demo-loan-consent' => '1', 'creature/demo-express-request' => '1' ) ) );
$ex_b_no = wc_create_order();
Creature_Demo_Loan::on_store_api_order( $ex_b_no, array( 'additional_fields' => array( 'creature/demo-loan-consent' => '1' ) ) );
creature_demo_expect( 'yes' === $ex_b_yes->get_meta( '_creature_demo_express_request' ) && 'no' === $ex_b_no->get_meta( '_creature_demo_express_request' ), 'block checkout: ticked is yes, unticked is no' );
$GLOBALS['creature_demo_cart'] = array( array( 'product_id' => 50 ) );
$ex_other = wc_create_order();
Creature_Demo_Loan::on_store_api_order( $ex_other, array( 'additional_fields' => array( 'creature/demo-express-request' => '1' ) ) );
creature_demo_expect( '' === $ex_other->get_meta( '_creature_demo_express_request' ), 'an order that is not a demo stores no answer' );
creature_demo_clear_cart();
$GLOBALS['creature_demo_use_product'] = false;
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();

// Item C: the keep price is on the booking confirmation, formatted like the other emails.
$kp_212 = creature_demo_booked_loan( '249.00' );
$kp_192 = creature_demo_booked_loan( '229.00' );
$kp_fb  = creature_demo_booked_loan( null );
foreach ( array( array( $kp_212, '£212.00' ), array( $kp_192, '£192.00' ), array( $kp_fb, '£212.00' ) ) as $kp_case ) {
	ob_start();
	Creature_Demo_Loan::render_booking_confirmation( $kp_case[0], false, true, $bc_email );
	$kp_out = ob_get_clean();
	creature_demo_expect( false !== strpos( $kp_out, 'Your keep price: ' . $kp_case[1] ) && false === strpos( $kp_out, '{price}' ), 'the booking confirmation shows the loan\'s keep price ' . $kp_case[1] );
}
$kp_192->update_meta_data( '_creature_demo_booking_block_sent', '' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $kp_192, false, false, $bc_email );
creature_demo_expect( false !== strpos( ob_get_clean(), 'Your keep price: £192.00' ), 'the HTML confirmation shows it too' );
creature_demo_expect( Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan::loan_keep_price_pence( $kp_192 ) ) === '£192.00', 'it uses the same money format as the reminder and bill emails (£x.xx)' );

// Item G: the booking block goes in once per order, even on-hold then processing.
$gb_order = creature_demo_booked_loan( '249.00' );
$gb_hold  = (object) array( 'id' => 'customer_on_hold_order' );
$gb_proc  = (object) array( 'id' => 'customer_processing_order' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_order, false, false, $gb_hold );
$gb_first = ob_get_clean();
creature_demo_expect( false !== strpos( $gb_first, 'De-Reacher demo booking' ) && 'yes' === $gb_order->get_meta( '_creature_demo_booking_block_sent' ), 'the on-hold email gets the block and a sent marker is stored' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_order, false, false, $gb_proc );
creature_demo_expect( '' === ob_get_clean(), 'the processing email that follows does not get it again' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_order, false, true, $gb_proc );
creature_demo_expect( '' === ob_get_clean(), 'nor does a plain-text version' );
$gb_other = creature_demo_booked_loan( '249.00' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_other, false, false, $gb_proc );
creature_demo_expect( false !== strpos( ob_get_clean(), 'De-Reacher demo booking' ), 'another order still gets its own block' );
// Straight to processing: once.
$gb_direct = creature_demo_booked_loan( '249.00' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_direct, false, false, $gb_proc );
$gb_d1 = ob_get_clean();
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_direct, false, false, $gb_proc );
creature_demo_expect( '' !== $gb_d1 && '' === ob_get_clean(), 'processing twice still adds it once' );
// An empty slot sets no marker, so the block can still come once wording exists.
$GLOBALS['creature_demo_copy_override']['booking_confirmation'] = array( 'subject' => '', 'body' => '' );
$gb_empty = creature_demo_booked_loan( '249.00' );
ob_start();
Creature_Demo_Loan::render_booking_confirmation( $gb_empty, false, false, $gb_proc );
ob_end_clean();
creature_demo_expect( '' === $gb_empty->get_meta( '_creature_demo_booking_block_sent' ), 'no marker is stored when there was nothing to add' );
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();

// The attachments: media ids or file paths, set through the filter (the config screen sets it).
$up = sys_get_temp_dir() . '/creature-demo-uploads-' . getmypid();
@mkdir( $up, 0777, true );
$GLOBALS['creature_demo_uploads'] = $up;
file_put_contents( $up . '/terms-v5.pdf', '%PDF-1.4 terms' );
file_put_contents( $up . '/model-cancellation-form.pdf', '%PDF-1.4 form' );
file_put_contents( $up . '/script.php', '<?php // not a document' );
$outside = sys_get_temp_dir() . '/creature-demo-outside-' . getmypid() . '.pdf';
file_put_contents( $outside, '%PDF-1.4 outside' );
$terms_path = realpath( $up . '/terms-v5.pdf' );
$form_path  = realpath( $up . '/model-cancellation-form.pdf' );
$GLOBALS['creature_demo_attached'] = array(
	77 => $up . '/terms-v5.pdf',
	78 => $up . '/model-cancellation-form.pdf',
	79 => $outside,
);
$bc_files = static function ( $order = null, $email_id = 'customer_processing_order', $existing = array() ) use ( $bc_order ) {
	return Creature_Demo_Loan::filter_booking_attachments( $existing, $email_id, null === $order ? $bc_order : $order );
};
$GLOBALS['creature_demo_conf_files'] = array();
creature_demo_expect( array() === $bc_files(), 'with no file set nothing is attached' );
$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => $up . '/terms-v5.pdf', 'cancellation_form' => $up . '/model-cancellation-form.pdf' );
creature_demo_expect( array( $terms_path, $form_path ) === $bc_files(), 'two file paths in the uploads folder are attached' );
$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => '77', 'cancellation_form' => 78 );
creature_demo_expect( array( $terms_path, $form_path ) === $bc_files(), 'media ids are attached by their file' );
creature_demo_expect( array( 'existing.pdf', $terms_path, $form_path ) === $bc_files( null, 'customer_processing_order', array( 'existing.pdf' ) ), 'files are added after any attachment already on the email' );
creature_demo_expect( array( $terms_path, $form_path ) === $bc_files( null, 'customer_on_hold_order' ), 'the on-hold confirmation carries them too' );
creature_demo_expect( array() === $bc_files( null, 'customer_completed_order' ), 'other emails get no attachment' );
creature_demo_expect( array() === $bc_files( $bc_plain ), 'a normal order gets no attachment' );
creature_demo_expect( array() === $bc_files( creature_demo_booked_loan( '249.00' ), 'new_order' ), 'the shop email gets no attachment' );
foreach ( array(
	'a path outside the uploads folder' => $outside,
	'a path that climbs out with ..'   => $up . '/../' . basename( $outside ),
	'a server file'                     => '/etc/passwd',
	'a script in the uploads folder'    => $up . '/script.php',
	'a missing file'                    => $up . '/nope.pdf',
	'a path with a null byte'           => $up . "/terms-v5.pdf\0.php",
	'a stream wrapper'                  => 'php://filter/resource=' . $up . '/terms-v5.pdf',
	'a media id outside the uploads'    => '79',
	'a media id with no file'           => '9999',
	'a directory'                       => $up,
) as $label => $bad_value ) {
	$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => $bad_value );
	creature_demo_expect( array() === $bc_files(), "{$label} is not attached" );
}
$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => $up . '/terms-v5.pdf', 'extra' => '77' );
creature_demo_expect( array( $terms_path ) === $bc_files(), 'a duplicate file is attached once' );
// Item F: no attachment wording unless both files can be attached, and the shop is told.
$f_render = static function ( $files ) use ( $bc_email ) {
	$GLOBALS['creature_demo_conf_files'] = $files;
	$order = creature_demo_booked_loan( '249.00' );
	$before = creature_demo_mail_count( 'admin_attachments_missing' );
	ob_start();
	Creature_Demo_Loan::render_booking_confirmation( $order, false, true, $bc_email );
	return array( ob_get_clean(), creature_demo_mail_count( 'admin_attachments_missing' ) - $before, $order );
};
$f_both = $f_render( array( 'terms_pdf' => '77', 'cancellation_form' => '78' ) );
creature_demo_expect( false !== strpos( $f_both[0], 'are attached to this email' ) && false !== strpos( $f_both[0], 'or use the attached model cancellation form' ) && 0 === $f_both[1], 'both files set: the wording says they are attached and the shop gets no notice' );
foreach ( array( 'one set (terms only)' => array( 'terms_pdf' => '77' ), 'one set (form only)' => array( 'cancellation_form' => '78' ), 'none set' => array() ) as $f_label => $f_files ) {
	$f_r = $f_render( $f_files );
	creature_demo_expect( false === stripos( $f_r[0], 'attached' ) && false !== strpos( $f_r[0], 'We’ll email your due date' ) && false !== strpos( $f_r[0], 'Email info@creaturecycles.co.uk.' ), "{$f_label}: no wording about attachments, the rest of the block stays" );
	creature_demo_expect( 1 === $f_r[1] && false !== strpos( implode( "\n", $f_r[2]->notes ), 'without the attachment wording' ), "{$f_label}: the shop gets one notice and the order a note" );
}
$f_mail = creature_demo_last_mail( 'admin_attachments_missing' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $f_mail['to'] && false !== strpos( $f_mail['body'], 'Terms PDF (version 5), Model cancellation form' ), 'the notice goes to the shop and names what is missing' );
$f_set_bad = $f_render( array( 'terms_pdf' => '77', 'cancellation_form' => $outside ) );
creature_demo_expect( false === stripos( $f_set_bad[0], 'attached' ) && 1 === $f_set_bad[1], 'a file that is set but cannot be attached counts as missing' );
$GLOBALS['creature_demo_conf_files'] = array();
// The admin notice when a file is not set.
$GLOBALS['creature_demo_conf_files'] = array();
$GLOBALS['creature_demo_can']        = true;
ob_start();
Creature_Demo_Loan::render_attachment_notice();
$notice_none = ob_get_clean();
creature_demo_expect( false !== strpos( $notice_none, 'notice-warning' ) && false !== strpos( $notice_none, 'No file is set for: Terms PDF (version 5), Model cancellation form' ), 'with no file set there is an admin notice naming both' );
$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => '77' );
ob_start();
Creature_Demo_Loan::render_attachment_notice();
$notice_one = ob_get_clean();
creature_demo_expect( false !== strpos( $notice_one, 'No file is set for: Model cancellation form' ) && false === strpos( $notice_one, 'No file is set for: Terms PDF' ), 'with one file set the notice names only the other' );
$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => '77', 'cancellation_form' => $outside );
ob_start();
Creature_Demo_Loan::render_attachment_notice();
$notice_bad = ob_get_clean();
creature_demo_expect( false !== strpos( $notice_bad, 'cannot be attached' ) && false !== strpos( $notice_bad, 'Model cancellation form' ), 'a file that is set but cannot be attached is in the notice' );
$GLOBALS['creature_demo_conf_files'] = array( 'terms_pdf' => '77', 'cancellation_form' => '78' );
ob_start();
Creature_Demo_Loan::render_attachment_notice();
creature_demo_expect( '' === ob_get_clean(), 'with both files set there is no notice' );
$GLOBALS['creature_demo_conf_files'] = array();
$GLOBALS['creature_demo_can']        = false;
ob_start();
Creature_Demo_Loan::render_attachment_notice();
creature_demo_expect( '' === ob_get_clean(), 'the notice is only for shop managers' );
$hooks_flat = array();
foreach ( $GLOBALS['creature_demo_hooks'] as $hook_row ) {
	$hooks_flat[] = $hook_row[0] . ':' . ( is_array( $hook_row[1] ) ? $hook_row[1][1] : '' );
}
foreach ( array( 'woocommerce_email_after_order_table:render_booking_confirmation', 'woocommerce_email_attachments:filter_booking_attachments', 'woocommerce_email_headers:filter_email_headers', 'admin_notices:render_attachment_notice' ) as $hooked ) {
	creature_demo_expect( in_array( $hooked, $hooks_flat, true ), "{$hooked} is hooked in boot" );
}
@unlink( $up . '/terms-v5.pdf' );
@unlink( $up . '/model-cancellation-form.pdf' );
@unlink( $up . '/script.php' );
@unlink( $outside );
@rmdir( $up );
$GLOBALS['creature_demo_uploads'] = '';

// Customer-facing dates read "30 November 2026". Stored dates stay Y-m-d.
creature_demo_expect( '30 November 2026' === Creature_Demo_Loan_Calendar::format_uk( '2026-11-30' ), 'a date reads 30 November 2026' );
creature_demo_expect( '1 March 2026' === Creature_Demo_Loan_Calendar::format_uk( '2026-03-01' ), 'the day has no leading zero' );
creature_demo_expect( '29 February 2028' === Creature_Demo_Loan_Calendar::format_uk( '2028-02-29' ), 'a leap day reads right' );
creature_demo_expect( 'soon' === Creature_Demo_Loan_Calendar::format_uk( 'soon' ) && '2026-02-30' === Creature_Demo_Loan_Calendar::format_uk( '2026-02-30' ) && '' === Creature_Demo_Loan_Calendar::format_uk( '' ), 'something that is not a date is left alone' );
creature_demo_expect( '30 November 2026 / 5 December 2026' === Creature_Demo_Loan_Copy::fill( '{due} / {grace_end}', array( 'due' => '2026-11-30', 'grace_end' => '2026-12-05' ) ), '{due} and the other date tags are formatted' );
$ukd = creature_demo_loan();
Creature_Demo_Loan::tick( $ukd, '2026-03-19' );
$ukd_mail = creature_demo_last_mail( 'reminder_choices_due_minus_5' );
creature_demo_expect( '2026-03-24' === $ukd->get_meta( '_creature_demo_due_date' ), 'the stored due date stays Y-m-d' );
creature_demo_expect( 'Your De-Reacher demo is due back on 24 March 2026: your choices' === preg_replace( '/^\[TEST\] /', '', $ukd_mail['subject'] ), 'the reminder subject shows the due date as 24 March 2026' );
creature_demo_expect( false !== strpos( $ukd_mail['body'], 'is due back on 24 March 2026. You have two choices.' ) && false !== strpos( $ukd_mail['body'], 'please post the kit back by 24 March 2026.' ), 'the reminder body shows {due} as 24 March 2026' );
creature_demo_absent( $ukd_mail['subject'] . $ukd_mail['body'], '2026-03-24', 'no raw Y-m-d date reaches the rider in the reminder' );
Creature_Demo_Loan::tick( $ukd, '2026-03-29' );
$ukd_grace = creature_demo_last_mail( 'reminder_grace_end' );
creature_demo_expect( false !== strpos( $ukd_grace['body'], 'was due back on 24 March 2026.' ) && false !== strpos( $ukd_grace['subject'], '(due date 24 March 2026)' ), 'the grace-end reminder shows the due date as 24 March 2026' );
$ukd_scan = creature_demo_loan();
Creature_Demo_Loan::record_return_scan( $ukd_scan, '2026-04-02', '2026-04-02' );
creature_demo_inspect( $ukd_scan, '2026-04-02' );
$ukd_bill = creature_demo_last_mail( 'charge_notice_late_fee' );
creature_demo_expect( false !== strpos( $ukd_bill['body'], 'Your due date was 24 March 2026.' ), 'the bill shows the due date as 24 March 2026' );
$ukd_all = '';
foreach ( Creature_Demo_Loan_Mail::$sent as $ukd_row ) {
	if ( 'customer' === $ukd_row['kind'] ) {
		$ukd_all .= $ukd_row['subject'] . "\n" . $ukd_row['body'] . "\n";
	}
}
creature_demo_expect( 0 === preg_match( '/\b20\d\d-\d\d-\d\d\b/', $ukd_all ), 'no customer email shows a raw Y-m-d date' );

// Every customer email carries Reply-To: info@creaturecycles.co.uk.
$reply = 'Reply-To: info@creaturecycles.co.uk';
$sent_customer = 0;
foreach ( Creature_Demo_Loan_Mail::$sent as $reply_row ) {
	if ( 'customer' === $reply_row['kind'] ) {
		$sent_customer++;
		creature_demo_expect( array( $reply ) === $reply_row['headers'], 'customer email "' . $reply_row['slot'] . '" carries Reply-To' );
	} else {
		creature_demo_expect( array() === $reply_row['headers'], 'shop email "' . $reply_row['slot'] . '" has no Reply-To' );
	}
}
creature_demo_expect( $sent_customer > 20, 'the Reply-To check saw many customer emails' );
$wp_mail_seen = 0;
foreach ( $GLOBALS['creature_demo_wp_mail'] as $wp_row ) {
	$wp_mail_seen++;
	if ( 'info@creaturecycles.co.uk' === $wp_row[0] ) {
		creature_demo_expect( array() === $wp_row[3], 'wp_mail to the shop has no Reply-To' );
	} else {
		creature_demo_expect( array( $reply ) === $wp_row[3], 'wp_mail to the rider is called with the Reply-To header' );
	}
}
creature_demo_expect( $wp_mail_seen > 20, 'wp_mail was called with headers many times' );
creature_demo_expect( 'info@creaturecycles.co.uk' === Creature_Demo_Loan_Mail::REPLY_TO, 'the Reply-To address is info@creaturecycles.co.uk' );
$woo_headers = "Content-Type: text/html; charset=UTF-8\r\n";
creature_demo_expect( $woo_headers . $reply . "\r\n" === Creature_Demo_Loan::filter_email_headers( $woo_headers, 'customer_processing_order', $bc_order ), 'a Woo customer email for a demo order gets the Reply-To header' );
creature_demo_expect( 'Content-Type: text/plain' . "\r\n" . $reply . "\r\n" === Creature_Demo_Loan::filter_email_headers( 'Content-Type: text/plain', 'customer_invoice', $bc_order ), 'a header string with no trailing newline is handled' );
creature_demo_expect( $reply . "\r\n" === Creature_Demo_Loan::filter_email_headers( '', 'customer_completed_order', $bc_order ), 'an empty header string gets the Reply-To header' );
creature_demo_expect( $woo_headers === Creature_Demo_Loan::filter_email_headers( $woo_headers, 'customer_processing_order', $bc_plain ), 'a normal order is left alone' );
creature_demo_expect( $woo_headers === Creature_Demo_Loan::filter_email_headers( $woo_headers, 'new_order', $bc_order ), 'the shop email is left alone' );
$with_reply = "Reply-To: other@example.com\r\n";
creature_demo_expect( $with_reply === Creature_Demo_Loan::filter_email_headers( $with_reply, 'customer_processing_order', $bc_order ), 'an existing Reply-To is not duplicated' );

// The default demo product (item 9): 8946 when the constant, the filter and the setting are all empty.
$GLOBALS['creature_demo_use_product'] = false;
creature_demo_expect( array( 8946 ) === Creature_Demo_Loan::demo_product_ids(), 'the demo product defaults to 8946' );
creature_demo_expect( 8946 === Creature_Demo_Loan::DEFAULT_DEMO_PRODUCT_ID, 'the default is the 8946 constant' );
$GLOBALS['creature_demo_cart'] = array( array( 'product_id' => 8946, 'variation_id' => 0 ) );
creature_demo_expect( true === Creature_Demo_Loan::cart_is_demo_only(), 'a basket with product 8946 is a demo basket with nothing configured' );
$GLOBALS['creature_demo_cart'] = array( array( 'product_id' => 8946 ), array( 'product_id' => 50 ) );
creature_demo_expect( false === Creature_Demo_Loan::cart_is_demo_only() && true === Creature_Demo_Loan::cart_contains_demo(), 'product 8946 mixed with another item is a mixed basket' );
$GLOBALS['creature_demo_cart'] = array( array( 'product_id' => 50 ) );
creature_demo_expect( false === Creature_Demo_Loan::cart_contains_demo(), 'any other product is not a demo' );
creature_demo_clear_cart();
$GLOBALS['creature_demo_use_product'] = true;
creature_demo_expect( array( 9001 ) === Creature_Demo_Loan::demo_product_ids(), 'a filter (the config screen) overrides the default' );
$GLOBALS['creature_demo_use_product'] = false;
$const_run = static function ( $constant_value ) {
	$dir  = dirname( __DIR__, 2 ) . '/creature-demo-loan/includes/';
	$code = 'define("ABSPATH","/"); define("CREATURE_DEMO_LOAN_PRODUCT_IDS",' . var_export( $constant_value, true ) . ');'
		. 'foreach(array("calendar","money","copy","mail","stripe") as $f){require "' . $dir . 'class-creature-demo-loan-".$f.".php";}'
		. 'require "' . $dir . 'class-creature-demo-loan.php"; echo json_encode(Creature_Demo_Loan::demo_product_ids());';
	return trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $code ) . ' 2>&1' ) );
};
creature_demo_expect( '[111,222]' === $const_run( '111, 222' ), 'a constant with ids wins over the default' );
creature_demo_expect( '[8946]' === $const_run( '' ), 'an empty constant falls back to 8946' );

// E1: the Demo loan tab in My account (terms 3A.1 and 3.2).
$e1_labels = array(
	'my_account_label_due'        => array( 'subject' => '', 'body' => 'Test label due:' ),
	'my_account_label_grace_end'  => array( 'subject' => '', 'body' => 'Test label grace:' ),
	'my_account_label_deemed_on'  => array( 'subject' => '', 'body' => 'Test label deemed:' ),
	'my_account_label_keep_price' => array( 'subject' => '', 'body' => 'Test label price:' ),
	'my_account_no_loans'         => array( 'subject' => '', 'body' => 'Test no loans line.' ),
);
$e1_hooks = array();
foreach ( $GLOBALS['creature_demo_hooks'] as $e1_row ) {
	$e1_hooks[] = $e1_row[0];
}
foreach ( array( 'woocommerce_account_menu_items', 'woocommerce_account_demo-loan_endpoint', 'query_vars' ) as $e1_hook ) {
	creature_demo_expect( in_array( $e1_hook, $e1_hooks, true ), 'E1: ' . $e1_hook . ' is hooked' );
}
$e1_menu = Creature_Demo_Loan::filter_account_menu( array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'customer-logout' => 'Log out' ) );
creature_demo_expect( array( 'dashboard', 'orders', 'demo-loan', 'customer-logout' ) === array_keys( $e1_menu ) && 'Demo loan' === $e1_menu['demo-loan'], 'E1: the menu item "Demo loan" (my_account_heading) goes in above Log out' );
creature_demo_expect( in_array( 'demo-loan', Creature_Demo_Loan::filter_query_vars( array( 'p' ) ), true ), 'E1: the endpoint is a query var' );
// The endpoint and one rewrite flush per plugin version.
$GLOBALS['creature_demo_rewrite'] = array( 'endpoints' => array(), 'flushes' => 0 );
if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
	function add_rewrite_endpoint( $name, $places ) {
		$GLOBALS['creature_demo_rewrite']['endpoints'][] = array( $name, $places );
	}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) {
		unset( $hard );
		$GLOBALS['creature_demo_rewrite']['flushes']++;
	}
}
unset( $GLOBALS['creature_demo_options']['creature_demo_loan_rewrite_version'] );
Creature_Demo_Loan::register_account_endpoint();
creature_demo_expect( 'demo-loan' === $GLOBALS['creature_demo_rewrite']['endpoints'][0][0] && 1 === $GLOBALS['creature_demo_rewrite']['flushes'] && CREATURE_DEMO_LOAN_VERSION === get_option( 'creature_demo_loan_rewrite_version' ), 'E1: the endpoint is added and the rewrite rules are flushed once' );
Creature_Demo_Loan::register_account_endpoint();
Creature_Demo_Loan::register_account_endpoint();
creature_demo_expect( 3 === count( $GLOBALS['creature_demo_rewrite']['endpoints'] ) && 1 === $GLOBALS['creature_demo_rewrite']['flushes'], 'E1: later requests add the endpoint again but do not flush again' );
$GLOBALS['creature_demo_options']['creature_demo_loan_rewrite_version'] = '0.0.1';
Creature_Demo_Loan::register_account_endpoint();
creature_demo_expect( 2 === $GLOBALS['creature_demo_rewrite']['flushes'], 'E1: a version bump flushes once more' );
// Render. Users 5500 (no loans), 5501 (one loan) and 5502 (someone else's).
$GLOBALS['creature_demo_copy_override'] = array_merge( creature_demo_empty_copy_baseline(), $e1_labels );
$GLOBALS['creature_demo_user'] = 5500;
ob_start();
Creature_Demo_Loan::render_account_tab();
$e1_none = ob_get_clean();
creature_demo_expect( false !== strpos( $e1_none, '<h2>Demo loan</h2>' ) && false !== strpos( $e1_none, 'Test no loans line.' ) && false === strpos( $e1_none, 'creature_demo_choice' ), 'E1: no loans: the heading and the no-loans line, no buttons' );
$e1_loan  = creature_demo_loan( '2026-10-26', '2026-10-27' );
$e1_loan->user_id = 5501;
$e1_other = creature_demo_loan( '2026-10-26', '2026-10-27' );
$e1_other->user_id = 5502;
$e1_closed = creature_demo_loan( '2026-10-26', '2026-10-27' );
$e1_closed->user_id = 5501;
$e1_closed->status = 'demo-closed';
creature_demo_expect( 'demo-loan' === $e1_loan->get_status() && '2026-11-16' === $e1_loan->get_meta( '_creature_demo_due_date' ), 'E1 setup: an active loan with due date 16 November 2026 (got ' . $e1_loan->get_status() . ' ' . $e1_loan->get_meta( '_creature_demo_due_date' ) . ')' );
$GLOBALS['creature_demo_user'] = 5501;
ob_start();
Creature_Demo_Loan::render_account_tab();
$e1_one = ob_get_clean();
creature_demo_expect( false !== strpos( $e1_one, 'Test label due:</strong> 16 November 2026' ) && false !== strpos( $e1_one, 'Test label grace:</strong> 21 November 2026' ) && false !== strpos( $e1_one, 'Test label deemed:</strong> 30 November 2026' ), 'E1: one active loan shows the due date, grace end and deemed date as j F Y' );
creature_demo_expect( false !== strpos( $e1_one, 'Test label price:</strong> £212.00' ), 'E1: a loan with no recorded bundle price shows the £212 ceiling' );
creature_demo_expect( false !== strpos( $e1_one, 'creature_demo_choice=return' ) && false !== strpos( $e1_one, 'creature_demo_choice=keep' ), 'E1: it shows the return and keep actions' );
creature_demo_expect( false !== strpos( $e1_one, '/view-order/' . $e1_loan->get_id() . '/">#' . $e1_loan->get_id() . '</a>' ), 'E1: it links to the order' );
creature_demo_expect( false === strpos( $e1_one, 'creature_demo_order=' . $e1_other->get_id() ) && false === strpos( $e1_one, '/view-order/' . $e1_other->get_id() . '/' ), "E1: another customer's loan is not shown" );
creature_demo_expect( false === strpos( $e1_one, 'creature_demo_order=' . $e1_closed->get_id() ), 'E1: a closed loan is not shown' );
creature_demo_expect( false === strpos( $e1_one, 'Test no loans line.' ), 'E1: the no-loans line is not shown when there is a loan' );
// Priced from the bundle: the same keep price path as the order page and the emails.
$e1_loan->update_meta_data( '_creature_demo_bundle_price_pence', 15000 );
$e1_loan->update_meta_data( '_creature_demo_bundle_price_source', 'woocommerce' );
$e1_expect = Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan::loan_keep_price_pence( $e1_loan ) );
ob_start();
Creature_Demo_Loan::render_account_tab();
$e1_priced = ob_get_clean();
creature_demo_expect( false !== strpos( $e1_priced, 'Test label price:</strong> ' . $e1_expect ) && '£212.00' !== $e1_expect, 'E1: a live bundle price gives its keep price (' . $e1_expect . ')' );
$e1_loan->update_meta_data( '_creature_demo_bundle_price_source', 'fallback_unavailable' );
ob_start();
Creature_Demo_Loan::render_account_tab();
creature_demo_expect( false !== strpos( ob_get_clean(), 'Test label price:</strong> £212.00' ), 'E1: a fallback booking shows the £212 ceiling, from the same loan_keep_price_pence path' );
// An empty label slot hides its row: nothing is shown under a made-up label.
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();
ob_start();
Creature_Demo_Loan::render_account_tab();
$e1_nolabel = ob_get_clean();
creature_demo_expect( false === strpos( $e1_nolabel, '16 November 2026' ) && false === strpos( $e1_nolabel, '£212.00' ) && false !== strpos( $e1_nolabel, 'creature_demo_choice=return' ), 'E1: with the label slots empty no date or price row is shown, and the actions and order link still are' );
creature_demo_expect( array() === Creature_Demo_Loan::account_loans( 0 ), 'E1: a logged-out visitor has no loans' );
// The order's own page keeps its Demo loan block.
$GLOBALS['creature_demo_copy_override'] = array_merge( creature_demo_empty_copy_baseline(), $e1_labels );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $e1_loan );
creature_demo_expect( false !== strpos( ob_get_clean(), 'creature-demo-loan-choices' ), 'E1: the order view page block is unchanged' );

// E2: the crash notice when the crash flag hides Keep: in the tab, in the order's Demo loan section, and on the pay page.
$e2_text = 'Test crash notice (3A.3).';
$GLOBALS['creature_demo_copy_override']['crash_hold_notice'] = array( 'subject' => '', 'body' => $e2_text );
ob_start();
Creature_Demo_Loan::render_account_tab();
creature_demo_expect( false === strpos( ob_get_clean(), $e2_text ), 'E2: with no crash flag there is no crash notice in the tab' );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $e1_loan );
creature_demo_expect( false === strpos( ob_get_clean(), $e2_text ), 'E2: with no crash flag there is no crash notice on the order page' );
$e1_loan->update_meta_data( '_creature_demo_crash_reported', 'yes' );
ob_start();
Creature_Demo_Loan::render_account_tab();
$e2_tab = ob_get_clean();
creature_demo_expect( false !== strpos( $e2_tab, $e2_text ) && false === strpos( $e2_tab, 'creature_demo_choice=keep' ) && false !== strpos( $e2_tab, 'creature_demo_choice=return' ), 'E2: a crash flag hides Keep and the tab shows the crash notice' );
ob_start();
Creature_Demo_Loan::render_my_account_choices( $e1_loan );
$e2_order = ob_get_clean();
creature_demo_expect( false !== strpos( $e2_order, $e2_text ) && false === strpos( $e2_order, 'creature_demo_choice=keep' ), "E2: the order's Demo loan section shows the crash notice too" );
creature_demo_expect( 1 === substr_count( $e2_order, $e2_text ), 'E2: the notice is shown once' );
creature_demo_expect( $e2_text === Creature_Demo_Loan::refusal_message( 'crash_hold' ), 'E2: the pay-page refusal still shows the same notice' );
$GLOBALS['creature_demo_copy_override']['crash_hold_notice'] = array( 'subject' => '', 'body' => '' );
ob_start();
Creature_Demo_Loan::render_account_tab();
$e2_empty = ob_get_clean();
creature_demo_expect( false === strpos( $e2_empty, 'creature-demo-loan-crash-notice' ) && false === strpos( $e2_empty, 'creature_demo_choice=keep' ), 'E2: with the notice wording empty Keep is still hidden and no empty notice is printed' );
creature_demo_expect( false === Creature_Demo_Loan::choice_allowed( $e1_loan, 'keep', '2026-11-01' ), 'E2: choice_allowed() is unchanged: Keep stays refused while the crash flag is on (re-offering it after an inspection stays manual)' );
$GLOBALS['creature_demo_user'] = 0;
$GLOBALS['creature_demo_copy_override'] = creature_demo_empty_copy_baseline();

// E4: the 22:00 job. A run is meant for a London date; a late or skipped run is caught up as of the right date and flagged.
$e4_charge_notice_at = static function ( $loan ) {
	foreach ( $GLOBALS['creature_demo_orders'] as $candidate ) {
		if ( (int) $candidate->get_meta( '_creature_demo_parent_loan_id' ) === (int) $loan->get_id() ) {
			return (string) $candidate->get_meta( '_creature_demo_charge_notice_at' );
		}
	}
	return '';
};
$e4_orders_before = $GLOBALS['creature_demo_daily_orders'];
$e4_make = static function () {
	$loan = creature_demo_loan();
	Creature_Demo_Loan::record_return_scan( $loan, '2026-04-02', '2026-04-02' );
	Creature_Demo_Loan::record_kit_received( $loan, '2026-04-08' );
	return $loan;
};
// Received Wed 2026-04-08: working day 4 is 04-14, working day 5 is 04-15.
// A normal run: 22:00 BST on 04-14 is 21:00 UTC. Not late, nothing flagged.
unset( $GLOBALS['creature_demo_options']['creature_demo_loan_daily_covered'], $GLOBALS['creature_demo_options']['creature_demo_loan_late_run'] );
$e4a = $e4_make();
$GLOBALS['creature_demo_daily_orders'] = array( $e4a );
$e4_late_mails = creature_demo_mail_count( 'admin_daily_job_late' );
Creature_Demo_Loan::run_scheduled( strtotime( '2026-04-14 21:00:00 UTC' ) );
creature_demo_expect( '2026-04-14' === get_option( 'creature_demo_loan_daily_covered' ) && $e4_late_mails === creature_demo_mail_count( 'admin_daily_job_late' ) && false === get_option( 'creature_demo_loan_late_run', false ), 'E4: an on-time 22:00 run covers its own date and raises no flag' );
creature_demo_expect( '' === $e4a->get_meta( '_creature_demo_bill_state' ), 'E4: working day 4 sends the alert, not the bill' );
// Late: the day-5 run starts at 00:30 London on 04-16 (23:30 UTC on 04-15). It is for 04-15, not 04-16.
Creature_Demo_Loan::run_scheduled( strtotime( '2026-04-15 23:30:00 UTC' ) );
creature_demo_expect( 'late_only' === $e4a->get_meta( '_creature_demo_bill_state' ) && '2026-04-15' === $e4_charge_notice_at( $e4a ), 'E4: a run at 00:30 the next day makes the day-5 decision as of working day 5 (the bill is dated 04-15, not 04-16)' );
creature_demo_expect( '2026-04-15' === get_option( 'creature_demo_loan_daily_covered' ), 'E4: it records 04-15 as covered, not 04-16' );
$e4_flag = get_option( 'creature_demo_loan_late_run', array() );
creature_demo_expect( is_array( $e4_flag ) && 'yes' === $e4_flag['flag'] && 1 === count( $e4_flag['events'] ) && 'after_midnight' === $e4_flag['events'][0]['kind'] && '2026-04-15' === $e4_flag['events'][0]['date'], 'E4: a late-run flag is recorded for 04-15 (started after midnight)' );
creature_demo_expect( $e4_late_mails + 1 === creature_demo_mail_count( 'admin_daily_job_late' ), 'E4: the shop is told once' );
$e4_mail = creature_demo_last_mail( 'admin_daily_job_late' );
creature_demo_expect( 'info@creaturecycles.co.uk' === $e4_mail['to'] && false !== strpos( $e4_mail['body'], '2026-04-15' ) && false !== strpos( $e4_mail['body'], 'after midnight' ), 'E4: the notice goes to the shop and names the date' );
Creature_Demo_Loan::run_scheduled( strtotime( '2026-04-15 23:45:00 UTC' ) );
creature_demo_expect( $e4_late_mails + 1 === creature_demo_mail_count( 'admin_daily_job_late' ) && 1 === count( get_option( 'creature_demo_loan_late_run' )['events'] ), 'E4: the same occurrence is not reported twice' );
// The next on-time run (04-16, 22:00 BST) is fine and adds nothing.
Creature_Demo_Loan::run_scheduled( strtotime( '2026-04-16 21:00:00 UTC' ) );
creature_demo_expect( '2026-04-16' === get_option( 'creature_demo_loan_daily_covered' ) && $e4_late_mails + 1 === creature_demo_mail_count( 'admin_daily_job_late' ), 'E4: an on-time run after a late one is not flagged' );
// A skipped day: no run on Tue 04-14 or Wed 04-15 (nothing ran). Thu 04-16 22:00 BST comes round, with 04-13 as the last covered.
unset( $GLOBALS['creature_demo_options']['creature_demo_loan_late_run'] );
$GLOBALS['creature_demo_options']['creature_demo_loan_daily_covered'] = '2026-04-13';
$e4b = $e4_make();
$GLOBALS['creature_demo_daily_orders'] = array( $e4b );
$e4_late_mails = creature_demo_mail_count( 'admin_daily_job_late' );
Creature_Demo_Loan::run_scheduled( strtotime( '2026-04-16 21:00:00 UTC' ) );
creature_demo_expect( 'late_only' === $e4b->get_meta( '_creature_demo_bill_state' ) && '2026-04-15' === $e4_charge_notice_at( $e4b ), 'E4: a skipped day is caught up in order: the day-5 bill is dated 04-15, not the day the run happened (04-16)' );
creature_demo_expect( '2026-04-16' === get_option( 'creature_demo_loan_daily_covered' ), 'E4: after the catch-up 04-16 is covered' );
$e4_flag = get_option( 'creature_demo_loan_late_run', array() );
$e4_kinds = array();
foreach ( $e4_flag['events'] as $e4_ev ) {
	$e4_kinds[ $e4_ev['date'] ] = $e4_ev['kind'];
}
creature_demo_expect( array( '2026-04-14' => 'skipped', '2026-04-15' => 'skipped' ) === $e4_kinds, 'E4: both skipped dates are flagged (04-16 itself ran on time)' );
creature_demo_expect( $e4_late_mails + 1 === creature_demo_mail_count( 'admin_daily_job_late' ), 'E4: one notice for the whole catch-up, listing both days' );
creature_demo_expect( false !== strpos( creature_demo_last_mail( 'admin_daily_job_late' )['body'], '2026-04-14: no run happened' ) && false !== strpos( creature_demo_last_mail( 'admin_daily_job_late' )['body'], '2026-04-15: no run happened' ), 'E4: the notice names each skipped day' );
// A first ever run has nothing to compare with, so it covers its own date and flags nothing.
unset( $GLOBALS['creature_demo_options']['creature_demo_loan_daily_covered'], $GLOBALS['creature_demo_options']['creature_demo_loan_late_run'] );
$e4_late_mails = creature_demo_mail_count( 'admin_daily_job_late' );
Creature_Demo_Loan::run_scheduled( strtotime( '2026-05-04 21:00:00 UTC' ) );
creature_demo_expect( '2026-05-04' === get_option( 'creature_demo_loan_daily_covered' ) && $e4_late_mails === creature_demo_mail_count( 'admin_daily_job_late' ), 'E4: a first run covers its own date and flags nothing' );
// A gap longer than the catch-up limit runs the last 14 days and says so.
$GLOBALS['creature_demo_options']['creature_demo_loan_daily_covered'] = '2026-05-04';
Creature_Demo_Loan::run_scheduled( strtotime( '2026-06-15 21:00:00 UTC' ) );
$e4_kinds = array();
foreach ( get_option( 'creature_demo_loan_late_run' )['events'] as $e4_ev ) {
	$e4_kinds[] = $e4_ev['kind'];
}
creature_demo_expect( in_array( 'gap_not_run', $e4_kinds, true ) && '2026-06-15' === get_option( 'creature_demo_loan_daily_covered' ), 'E4: a gap over 14 days is capped and the shop is told the earlier days were not run' );
$GLOBALS['creature_demo_daily_orders'] = $e4_orders_before;
creature_demo_expect( in_array( 'admin_daily_job_late', Creature_Demo_Loan_Copy::slots(), true ), 'E4: the shop notice is a slot' );

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
