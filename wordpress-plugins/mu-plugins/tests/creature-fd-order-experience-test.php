<?php
/**
 * Decision tests for creature-fd-order-experience.php.
 *
 *   php wordpress-plugins/mu-plugins/tests/creature-fd-order-experience-test.php
 *
 * No WooCommerce install. Stubs stand in for the hooks and the order objects.
 */

if ( PHP_SAPI !== 'cli' || ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) !== realpath( __FILE__ ) ) ) {
	return;
}

$GLOBALS['creature_fd_exp_filters']  = array();
$GLOBALS['creature_fd_exp_orders']   = array();
$GLOBALS['creature_fd_exp_endpoint'] = '';
$GLOBALS['creature_fd_exp_query']    = array();
$GLOBALS['creature_fd_exp_is_admin'] = false;
$GLOBALS['creature_fd_exp_printed']  = array();
$GLOBALS['creature_fd_exp_failed']   = 0;
$GLOBALS['creature_fd_exp_passed']   = 0;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		if ( ! isset( $GLOBALS['creature_fd_exp_filters'][ $hook ] ) ) {
			$GLOBALS['creature_fd_exp_filters'][ $hook ] = array();
		}
		$GLOBALS['creature_fd_exp_filters'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
		ksort( $GLOBALS['creature_fd_exp_filters'][ $hook ] );
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
		if ( empty( $GLOBALS['creature_fd_exp_filters'][ $hook ][ (int) $priority ] ) ) {
			return false;
		}
		$kept = array();
		foreach ( $GLOBALS['creature_fd_exp_filters'][ $hook ][ (int) $priority ] as $row ) {
			if ( $row[0] !== $callback ) {
				$kept[] = $row;
			}
		}
		if ( ! $kept ) {
			unset( $GLOBALS['creature_fd_exp_filters'][ $hook ][ (int) $priority ] );
		} else {
			$GLOBALS['creature_fd_exp_filters'][ $hook ][ (int) $priority ] = $kept;
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
		if ( empty( $GLOBALS['creature_fd_exp_filters'][ $hook ] ) ) {
			return $value;
		}
		$priorities = array_keys( $GLOBALS['creature_fd_exp_filters'][ $hook ] );
		sort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $priority ) {
			if ( empty( $GLOBALS['creature_fd_exp_filters'][ $hook ][ $priority ] ) ) {
				continue;
			}
			$rows = $GLOBALS['creature_fd_exp_filters'][ $hook ][ $priority ];
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
		if ( empty( $GLOBALS['creature_fd_exp_filters'][ $hook ] ) ) {
			return;
		}
		$priorities = array_keys( $GLOBALS['creature_fd_exp_filters'][ $hook ] );
		sort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $priority ) {
			if ( empty( $GLOBALS['creature_fd_exp_filters'][ $hook ][ $priority ] ) ) {
				continue;
			}
			$rows = $GLOBALS['creature_fd_exp_filters'][ $hook ][ $priority ];
			foreach ( $rows as $row ) {
				$params = $args;
				if ( $row[1] > 0 && count( $params ) > $row[1] ) {
					$params = array_slice( $params, 0, $row[1] );
				}
				call_user_func_array( $row[0], $params );
			}
		}
	}
}
if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $endpoint = '' ) {
		return (string) $GLOBALS['creature_fd_exp_endpoint'] === (string) $endpoint;
	}
}
if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( $key ) {
		return isset( $GLOBALS['creature_fd_exp_query'][ $key ] ) ? $GLOBALS['creature_fd_exp_query'][ $key ] : '';
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) {
		$id = (int) $id;
		return isset( $GLOBALS['creature_fd_exp_orders'][ $id ] ) ? $GLOBALS['creature_fd_exp_orders'][ $id ] : false;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['creature_fd_exp_is_admin'] );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'wc_locate_template' ) ) {
	function wc_locate_template( $template_name ) {
		$dir = sys_get_temp_dir() . '/creature-fd-exp-templates';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$path = $dir . '/' . basename( $template_name );
		if ( ! file_exists( $path ) ) {
			file_put_contents( $path, "<?php\n" );
		}
		return $path;
	}
}
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $notice_type = 'success' ) {
		if ( ! isset( $GLOBALS['creature_fd_exp_notices'] ) || ! is_array( $GLOBALS['creature_fd_exp_notices'] ) ) {
			$GLOBALS['creature_fd_exp_notices'] = array();
		}
		$GLOBALS['creature_fd_exp_notices'][] = array( (string) $message, (string) $notice_type );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();

		public function add( $code, $message ) {
			$this->errors[ (string) $code ] = (string) $message;
		}

		public function has_errors() {
			return ! empty( $this->errors );
		}
	}
}
if ( ! function_exists( 'wc_print_notice' ) ) {
	function wc_print_notice( $message, $notice_type = 'success', $data = array(), $return = false ) {
		unset( $data );
		$GLOBALS['creature_fd_exp_printed'][] = array( (string) $message, (string) $notice_type );
		$html = '<div class="woocommerce-' . $notice_type . '">' . $message . '</div>';
		if ( $return ) {
			return $html;
		}
		echo $html;
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

require dirname( __DIR__ ) . '/creature-fd-only-purchase.php';
require dirname( __DIR__ ) . '/creature-fd-order-experience.php';

do_action( 'plugins_loaded' );

class Creature_Fd_Exp_Item {
	public $product_id;
	public $variation_id;
	public $meta;

	public function __construct( $product_id, $meta = array(), $variation_id = 0 ) {
		$this->product_id   = (int) $product_id;
		$this->variation_id = (int) $variation_id;
		$this->meta         = $meta;
	}

	public function get_product_id() {
		return $this->product_id;
	}

	public function get_variation_id() {
		return $this->variation_id;
	}

	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
}

class Creature_Fd_Exp_Order {
	public $id;
	public $key;
	public $items;
	public $meta;
	public $total = '136.00';
	public $notes = array();

	public function __construct( $id, $key, $items, $meta = array() ) {
		$this->id    = (int) $id;
		$this->key   = (string) $key;
		$this->items = $items;
		$this->meta  = $meta;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_order_key() {
		return $this->key;
	}

	public function get_items() {
		return $this->items;
	}

	public function get_meta( $key, $single = true ) {
		unset( $single );
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save() {
		return $this->id;
	}

	public function get_total() {
		return $this->total;
	}

	public function add_order_note( $note ) {
		$this->notes[] = (string) $note;
	}
}

function creature_fd_exp_expect( $ok, $label ) {
	if ( $ok ) {
		$GLOBALS['creature_fd_exp_passed']++;
		echo "ok  {$label}\n";
		return;
	}
	$GLOBALS['creature_fd_exp_failed']++;
	echo "FAIL {$label}\n";
}

function creature_fd_exp_order( $id, $items, $meta = array(), $key = 'order_key_1' ) {
	$order = new Creature_Fd_Exp_Order( $id, $key, $items, $meta );
	$GLOBALS['creature_fd_exp_orders'][ (int) $id ] = $order;
	return $order;
}

function creature_fd_exp_arm_pay( $order ) {
	$GLOBALS['creature_fd_exp_endpoint'] = 'order-pay';
	$GLOBALS['creature_fd_exp_query']    = array( 'order-pay' => $order->get_id() );
	$_GET['key']                         = $order->get_order_key();
	do_action( 'before_woocommerce_pay' );
}

function creature_fd_exp_meta_row( $key, $value, $display_key = null ) {
	return (object) array(
		'key'           => $key,
		'value'         => $value,
		'display_key'   => null === $display_key ? $key : $display_key,
		'display_value' => $value,
	);
}

function creature_fd_exp_sample_meta() {
	return array(
		1 => creature_fd_exp_meta_row( 'design_id', 'design-1' ),
		2 => creature_fd_exp_meta_row( 'creature_design_id', 'design-1' ),
		3 => creature_fd_exp_meta_row( 'geometry_summary', 'Reach: 450mm' ),
		4 => creature_fd_exp_meta_row( 'pa_colour', 'Raw', 'Colour' ),
	);
}

$guest_us = 'You are paying for a guest order. Please continue with payment only if you recognize this order.';
$guest_gb = 'You are paying for a guest order. Please continue with payment only if you recognise this order.';
$other_error = 'Sorry, this order is invalid and cannot be paid for.';

$src = file_get_contents( dirname( __DIR__ ) . '/creature-fd-order-experience.php' );
creature_fd_exp_expect( false === strpos( $src, 'guest_should_verify_email' ), 'source does not touch guest email verification' );
creature_fd_exp_expect( ! preg_match( '/current_user_can\s*\(/', $src ), 'source does not call current_user_can' );
creature_fd_exp_expect( false === strpos( $src, 'woocommerce_order_received_verify_known_shoppers' ), 'source does not touch known-shopper verification' );
creature_fd_exp_expect( false === strpos( $src, 'woocommerce_order_before_calculate_totals' ), 'source does not hook order totals' );
creature_fd_exp_expect( false === strpos( $src, 'woocommerce_cart_calculate_fees' ), 'source does not hook cart fees' );
creature_fd_exp_expect( false === strpos( $src, 'woocommerce_add_to_cart_validation' ), 'source does not hook basket validation' );

$hooks = array();
foreach ( $GLOBALS['creature_fd_exp_filters'] as $hook => $priorities ) {
	$hooks[ $hook ] = true;
}
creature_fd_exp_expect( isset( $hooks['before_woocommerce_pay'] ), 'arms on before_woocommerce_pay' );
creature_fd_exp_expect( isset( $hooks['woocommerce_order_item_get_formatted_meta_data'] ), 'filters formatted line meta' );
creature_fd_exp_expect( isset( $hooks['woocommerce_thankyou'] ), 'thank-you hook registered' );
creature_fd_exp_expect( isset( $hooks['woocommerce_email_before_order_table'] ), 'email lead-time hook registered' );
creature_fd_exp_expect( ! isset( $hooks['woocommerce_add_error'] ), 'guest error filter is not global' );

$fd_item = new Creature_Fd_Exp_Item( 8634, array( 'design_id' => 'design-1', 'geometry_summary' => 'Reach: 450mm' ) );
$fd      = creature_fd_exp_order( 10, array( $fd_item ) );
creature_fd_exp_expect( Creature_Fd_Order_Experience::is_fd_order( $fd ), '8634 line with design_id is an FD order' );
creature_fd_exp_expect( 'design-1' === Creature_Fd_Order_Experience::design_id_from_order( $fd ), 'design id comes from the line' );

$bare = creature_fd_exp_order( 11, array( new Creature_Fd_Exp_Item( 8634, array() ) ), array( 'design_id' => 'order-only' ) );
creature_fd_exp_expect( ! Creature_Fd_Order_Experience::is_fd_order( $bare ), 'order meta alone does not qualify' );
creature_fd_exp_expect( 'order-only' === Creature_Fd_Order_Experience::design_id_from_order( $bare ), 'lookup still falls back to order meta' );

$creature_only = creature_fd_exp_order(
	12,
	array( new Creature_Fd_Exp_Item( 8635, array( 'creature_design_id' => 'design-2' ) ) )
);
creature_fd_exp_expect( Creature_Fd_Order_Experience::is_fd_order( $creature_only ), 'creature_design_id on an SS line qualifies' );
creature_fd_exp_expect( 'design-2' === Creature_Fd_Order_Experience::design_id_from_order( $creature_only ), 'creature_design_id is the lookup' );

$variation = creature_fd_exp_order(
	13,
	array( new Creature_Fd_Exp_Item( 999, array( 'design_id' => 'design-3' ), 8636 ) )
);
creature_fd_exp_expect( Creature_Fd_Order_Experience::is_fd_order( $variation ), 'variation id 8636 with design_id qualifies' );

$other = creature_fd_exp_order(
	14,
	array( new Creature_Fd_Exp_Item( 8371, array( 'design_id' => 'design-9' ) ) )
);
creature_fd_exp_expect( ! Creature_Fd_Order_Experience::is_fd_order( $other ), 'a non-FD product with design_id is not an FD order' );

creature_fd_exp_arm_pay( $other );
creature_fd_exp_expect(
	$guest_us === apply_filters( 'woocommerce_add_error', $guest_us ),
	'non-FD guest order keeps Woo\'s notice'
);
creature_fd_exp_expect(
	'/templates/notices/error.php' === apply_filters(
		'wc_get_template',
		'/templates/notices/error.php',
		'notices/error.php',
		array( 'notices' => array( array( 'notice' => $guest_us ) ) )
	),
	'non-FD guest notice stays an error template'
);

$_GET['key'] = 'wrong-key';
do_action( 'before_woocommerce_pay' );
creature_fd_exp_expect(
	$guest_us === apply_filters( 'woocommerce_add_error', $guest_us ),
	'mismatched order key does not rewrite the guest notice'
);
$_GET['key'] = $fd->get_order_key();

creature_fd_exp_arm_pay( $fd );
$replaced = apply_filters( 'woocommerce_add_error', $guest_us );
$expected_pay = 'Your Frame Designer order. Design files are delivered within 5 working days of payment.';
creature_fd_exp_expect( $expected_pay === $replaced, 'FD order-pay replaces the guest error text' );
$template = apply_filters(
	'wc_get_template',
	'/templates/notices/error.php',
	'notices/error.php',
	array( 'notices' => array( array( 'notice' => $replaced ) ) )
);
creature_fd_exp_expect( wc_locate_template( 'notices/notice.php' ) === $template, 'FD guest notice renders as info' );
creature_fd_exp_expect(
	$guest_us === apply_filters( 'woocommerce_add_error', $guest_us ),
	'the swap is one-shot and does not stay armed'
);

creature_fd_exp_arm_pay( $fd );
creature_fd_exp_expect(
	$other_error === apply_filters( 'woocommerce_add_error', $other_error ),
	'other order-pay errors stay errors on an FD order'
);
creature_fd_exp_expect(
	'/templates/notices/error.php' === apply_filters(
		'wc_get_template',
		'/templates/notices/error.php',
		'notices/error.php',
		array( 'notices' => array( array( 'notice' => $other_error ) ) )
	),
	'other order-pay errors keep the error template'
);
$gb = apply_filters( 'woocommerce_add_error', $guest_gb );
creature_fd_exp_expect( $expected_pay === $gb, 'British recognise spelling is the same guest notice' );
apply_filters(
	'wc_get_template',
	'/templates/notices/error.php',
	'notices/error.php',
	array( 'notices' => array( array( 'notice' => $gb ) ) )
);

function creature_fd_exp_lead_override() {
	return '9 working days';
}
add_filter( 'creature_fd_lead_time', 'creature_fd_exp_lead_override' );
creature_fd_exp_expect(
	'Design files are delivered within 9 working days of payment.' === Creature_Fd_Order_Experience::delivery_sentence(),
	'lead time filter changes the sentence'
);
creature_fd_exp_arm_pay( $fd );
$overridden = apply_filters( 'woocommerce_add_error', $guest_us );
creature_fd_exp_expect(
	'Your Frame Designer order. Design files are delivered within 9 working days of payment.' === $overridden,
	'order-pay notice uses the filtered lead time'
);
apply_filters(
	'wc_get_template',
	'/templates/notices/error.php',
	'notices/error.php',
	array( 'notices' => array( array( 'notice' => $overridden ) ) )
);
remove_filter( 'creature_fd_lead_time', 'creature_fd_exp_lead_override' );
creature_fd_exp_expect(
	'5 working days' === Creature_Fd_Order_Experience::lead_time(),
	'lead time falls back after the filter is removed'
);

add_filter(
	'creature_fd_lead_time',
	static function () {
		return array( 'nope' );
	}
);
creature_fd_exp_expect( '5 working days' === Creature_Fd_Order_Experience::lead_time(), 'a non-string lead time is ignored' );
$GLOBALS['creature_fd_exp_filters']['creature_fd_lead_time'] = array();

$customer_meta = apply_filters( 'woocommerce_order_item_get_formatted_meta_data', creature_fd_exp_sample_meta(), $fd_item );
creature_fd_exp_expect( ! isset( $customer_meta[1] ) && ! isset( $customer_meta[2] ), 'customer view hides design_id and creature_design_id' );
creature_fd_exp_expect( isset( $customer_meta[3] ) && 'Geometry' === $customer_meta[3]->display_key, 'customer view labels geometry_summary Geometry' );
creature_fd_exp_expect( isset( $customer_meta[4] ) && 'Colour' === $customer_meta[4]->display_key, 'unrelated line meta stays' );

$GLOBALS['creature_fd_exp_is_admin'] = true;
$admin_meta = apply_filters( 'woocommerce_order_item_get_formatted_meta_data', creature_fd_exp_sample_meta(), $fd_item );
creature_fd_exp_expect( isset( $admin_meta[1] ) && isset( $admin_meta[2] ), 'wp-admin still shows design ids' );
creature_fd_exp_expect( 'geometry_summary' === $admin_meta[3]->display_key, 'wp-admin keeps the geometry_summary key label' );

$GLOBALS['creature_fd_exp_is_admin'] = false;
$email = (object) array( 'id' => 'customer_processing_order' );
$during = null;
add_action(
	'woocommerce_email_order_details',
	static function () use ( &$during, $fd_item ) {
		$during = apply_filters( 'woocommerce_order_item_get_formatted_meta_data', creature_fd_exp_sample_meta(), $fd_item );
	},
	10,
	4
);
do_action( 'woocommerce_email_order_details', $fd, true, false, $email );
creature_fd_exp_expect( isset( $during[1] ) && isset( $during[2] ), 'sent_to_admin email shows design ids' );
creature_fd_exp_expect( 'geometry_summary' === $during[3]->display_key, 'sent_to_admin keeps the geometry key' );

$GLOBALS['creature_fd_exp_is_admin'] = true;
$during_customer = null;
add_action(
	'woocommerce_email_order_details',
	static function () use ( &$during_customer, $fd_item ) {
		$during_customer = apply_filters( 'woocommerce_order_item_get_formatted_meta_data', creature_fd_exp_sample_meta(), $fd_item );
	},
	11,
	4
);
do_action( 'woocommerce_email_order_details', $fd, false, false, $email );
creature_fd_exp_expect( ! isset( $during_customer[1] ) && ! isset( $during_customer[2] ), 'customer email hides design ids even inside wp-admin' );
creature_fd_exp_expect( 'Geometry' === $during_customer[3]->display_key, 'customer email labels geometry Geometry' );
$GLOBALS['creature_fd_exp_is_admin'] = false;

add_filter(
	'creature_fd_hidden_customer_meta_keys',
	static function ( $keys ) {
		$keys[] = 'internal_note';
		$keys[] = '_creature_rear_end_discount';
		return $keys;
	}
);
$extra = apply_filters(
	'woocommerce_order_item_get_formatted_meta_data',
	array(
		7 => creature_fd_exp_meta_row( 'internal_note', 'secret' ),
		8 => creature_fd_exp_meta_row( '_creature_rear_end_discount', '1' ),
		9 => creature_fd_exp_meta_row( 'geometry_summary', 'Reach: 1' ),
	),
	$fd_item
);
creature_fd_exp_expect( ! isset( $extra[7] ), 'filter can hide another customer key' );
creature_fd_exp_expect( isset( $extra[8] ), 'underscore fee meta is not hidden by this filter' );
$GLOBALS['creature_fd_exp_filters']['creature_fd_hidden_customer_meta_keys'] = array();

$GLOBALS['creature_fd_exp_printed'] = array();
ob_start();
do_action( 'woocommerce_thankyou', 10 );
$thank_html = ob_get_clean();
creature_fd_exp_expect(
	1 === count( $GLOBALS['creature_fd_exp_printed'] )
		&& 'notice' === $GLOBALS['creature_fd_exp_printed'][0][1]
		&& 'Design files are delivered within 5 working days of payment.' === $GLOBALS['creature_fd_exp_printed'][0][0],
	'thank-you prints an info lead-time notice for an FD order'
);
creature_fd_exp_expect( false !== strpos( $thank_html, 'woocommerce-notice' ), 'thank-you notice is not an error box' );

$GLOBALS['creature_fd_exp_printed'] = array();
ob_start();
do_action( 'woocommerce_thankyou', 14 );
ob_end_clean();
creature_fd_exp_expect( array() === $GLOBALS['creature_fd_exp_printed'], 'thank-you stays quiet for a non-FD order' );

$processing = (object) array( 'id' => 'customer_processing_order' );
$on_hold    = (object) array( 'id' => 'customer_on_hold_order' );
$completed  = (object) array( 'id' => 'customer_completed_order' );
$invoice    = (object) array( 'id' => 'customer_invoice' );
foreach ( array( $processing, $on_hold, $completed ) as $mail ) {
	ob_start();
	do_action( 'woocommerce_email_before_order_table', $fd, false, false, $mail );
	$html = ob_get_clean();
	creature_fd_exp_expect(
		false !== strpos( $html, '<p class="creature-fd-lead-time">Design files are delivered within 5 working days of payment.</p>' ),
		$mail->id . ' customer email includes the lead time'
	);
	ob_start();
	do_action( 'woocommerce_email_before_order_table', $fd, false, true, $mail );
	$text = ob_get_clean();
	creature_fd_exp_expect(
		false !== strpos( $text, "\nDesign files are delivered within 5 working days of payment.\n" ),
		$mail->id . ' plain text includes the lead time'
	);
	creature_fd_exp_expect( false === strpos( $text, '<p' ), $mail->id . ' plain text has no html paragraph' );
	creature_fd_exp_expect( false === strpos( $html, '14-day right to cancel ends' ), $mail->id . ' email has no waiver line before consent' );
}
ob_start();
do_action( 'woocommerce_email_before_order_table', $fd, true, false, $processing );
$admin_mail = ob_get_clean();
creature_fd_exp_expect( '' === $admin_mail, 'admin email does not get the customer lead time' );
ob_start();
do_action( 'woocommerce_email_before_order_table', $fd, false, false, $invoice );
$invoice_mail = ob_get_clean();
creature_fd_exp_expect( '' === $invoice_mail, 'invoice email does not get the lead time' );
ob_start();
do_action( 'woocommerce_email_before_order_table', $other, false, false, $processing );
$other_mail = ob_get_clean();
creature_fd_exp_expect( '' === $other_mail, 'non-FD processing email does not get the lead time' );

add_filter( 'creature_fd_lead_time', 'creature_fd_exp_lead_override' );
ob_start();
do_action( 'woocommerce_email_before_order_table', $fd, false, false, $completed );
$filtered_mail = ob_get_clean();
creature_fd_exp_expect(
	false !== strpos( $filtered_mail, '9 working days' ),
	'customer email uses the filtered lead time'
);
remove_filter( 'creature_fd_lead_time', 'creature_fd_exp_lead_override' );

$yoke = Creature_Fd_Only_Purchase::purchase_markup( 8634, true );
$yoke_button = strpos( (string) $yoke, 'Open Frame Designer' );
$yoke_lead   = strpos( (string) $yoke, 'Design files are delivered within 5 working days of payment.' );
creature_fd_exp_expect( false !== $yoke_button && false !== $yoke_lead && $yoke_button < $yoke_lead, '8634 page shows the lead time under the button' );
$ss = Creature_Fd_Only_Purchase::purchase_markup( 8635, true );
creature_fd_exp_expect( false !== strpos( (string) $ss, '5 working days' ), '8635 page shows the lead time' );
$soon = Creature_Fd_Only_Purchase::purchase_markup( 8636, true );
creature_fd_exp_expect( false === strpos( (string) $soon, 'working days' ), '8636 Coming soon skips the lead time' );
add_filter( 'creature_fd_lead_time', 'creature_fd_exp_lead_override' );
$yoke_over = Creature_Fd_Only_Purchase::purchase_markup( 8634, true );
creature_fd_exp_expect(
	false !== strpos( (string) $yoke_over, '9 working days' ),
	'product page uses the experience plugin lead time'
);
remove_filter( 'creature_fd_lead_time', 'creature_fd_exp_lead_override' );

$waiver = 'I want my design files made and supplied straight away, and I understand I lose my 14-day right to cancel once work starts.';
$waiver_email = 'You asked us to start straight away and acknowledged that the 14-day right to cancel ends once work starts.';
$waiver_error = 'Please tick the box to confirm you want your design files made and supplied straight away. Payment has not been taken.';

creature_fd_exp_arm_pay( $other );
ob_start();
Creature_Fd_Order_Experience::on_pay_order_before_submit();
$other_box = ob_get_clean();
creature_fd_exp_expect( '' === $other_box, 'non-FD order-pay has no cancellation checkbox' );
ob_start();
Creature_Fd_Order_Experience::on_before_pay_form_express( $other );
creature_fd_exp_expect( '' === ob_get_clean(), 'non-FD order-pay does not hide express buttons' );

creature_fd_exp_arm_pay( $fd );
ob_start();
Creature_Fd_Order_Experience::on_pay_order_before_submit();
$fd_box = ob_get_clean();
creature_fd_exp_expect( false !== strpos( $fd_box, 'name="creature_fd_cancellation_waiver"' ), 'FD order-pay renders the waiver checkbox' );
creature_fd_exp_expect( false !== strpos( $fd_box, 'required="required"' ), 'waiver checkbox is required' );
creature_fd_exp_expect( false !== strpos( $fd_box, $waiver ), 'waiver checkbox uses the T&amp;C wording' );
creature_fd_exp_expect( false === strpos( $fd_box, 'name="terms"' ), 'waiver checkbox is not the Woo terms box' );
ob_start();
Creature_Fd_Order_Experience::on_before_pay_form_express( $fd );
$express_hide = ob_get_clean();
creature_fd_exp_expect( false !== strpos( $express_hide, '.wcpay-express-checkout-wrapper' ), 'FD order-pay hides WooPayments express buttons' );

function creature_fd_exp_waiver_wording() {
	return 'Custom waiver wording for the final terms.';
}
add_filter( 'creature_fd_cancellation_waiver_text', 'creature_fd_exp_waiver_wording' );
ob_start();
Creature_Fd_Order_Experience::on_pay_order_before_submit();
$custom_box = ob_get_clean();
creature_fd_exp_expect( false !== strpos( $custom_box, 'Custom waiver wording for the final terms.' ), 'waiver wording follows its filter' );
remove_filter( 'creature_fd_cancellation_waiver_text', 'creature_fd_exp_waiver_wording' );

$GLOBALS['creature_fd_exp_notices'] = array();
unset( $_POST['creature_fd_cancellation_waiver'] );
$fd->total = '136.00';
Creature_Fd_Order_Experience::on_before_pay_action( $fd );
creature_fd_exp_expect(
	isset( $GLOBALS['creature_fd_exp_notices'][0] ) && 'error' === $GLOBALS['creature_fd_exp_notices'][0][1] && $waiver_error === $GLOBALS['creature_fd_exp_notices'][0][0],
	'missing waiver rejects pay_action with an error'
);
creature_fd_exp_expect( ! Creature_Fd_Order_Experience::order_has_waiver( $fd ), 'rejected pay_action does not store consent' );
creature_fd_exp_expect( '136.00' === $fd->get_total(), 'rejected pay_action leaves the total unchanged' );
$GLOBALS['creature_fd_exp_notices'] = array();
Creature_Fd_Order_Experience::on_before_pay_action( $other );
creature_fd_exp_expect( array() === $GLOBALS['creature_fd_exp_notices'], 'non-FD pay_action does not ask for the waiver' );

$_POST['creature_fd_cancellation_waiver'] = '1';
Creature_Fd_Order_Experience::on_before_pay_action( $fd );
creature_fd_exp_expect( '1' === (string) $fd->get_meta( '_creature_fd_cancellation_waiver' ), 'consent is stored as order meta' );
creature_fd_exp_expect( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $fd->get_meta( '_creature_fd_cancellation_waiver_at' ) ), 'consent timestamp is UTC' );
creature_fd_exp_expect( $waiver === (string) $fd->get_meta( '_creature_fd_cancellation_waiver_text' ), 'consent stores the wording shown' );
creature_fd_exp_expect( '1' === (string) $fd->get_meta( '_creature_fd_cancellation_waiver_version' ), 'consent stores the wording version' );
creature_fd_exp_expect( '136.00' === $fd->get_total(), 'recording consent leaves the total unchanged' );
creature_fd_exp_expect( 1 === count( $fd->notes ) && false !== strpos( $fd->notes[0], $waiver ), 'wp-admin order note records the waiver' );
Creature_Fd_Order_Experience::on_before_pay_action( $fd );
creature_fd_exp_expect( 1 === count( $fd->notes ), 'a second pay attempt does not add another note' );
unset( $_POST['creature_fd_cancellation_waiver'] );

ob_start();
Creature_Fd_Order_Experience::on_admin_order_waiver( $fd );
$admin_waiver = ob_get_clean();
creature_fd_exp_expect( false !== strpos( $admin_waiver, 'Cancellation waiver:' ) && false !== strpos( $admin_waiver, $waiver ), 'order screen shows the waiver' );

ob_start();
do_action( 'woocommerce_email_before_order_table', $fd, false, false, $processing );
$paid_mail = ob_get_clean();
creature_fd_exp_expect( false !== strpos( $paid_mail, $waiver_email ), 'processing email confirms the waiver for an FD order' );
ob_start();
do_action( 'woocommerce_email_before_order_table', $fd, false, true, $processing );
$paid_text = ob_get_clean();
creature_fd_exp_expect( false !== strpos( $paid_text, $waiver_email ), 'processing plain text confirms the waiver' );
ob_start();
do_action( 'woocommerce_email_before_order_table', $fd, false, false, $on_hold );
$hold_mail = ob_get_clean();
creature_fd_exp_expect( false === strpos( $hold_mail, $waiver_email ), 'on-hold email does not add the waiver line' );
$other->update_meta_data( '_creature_fd_cancellation_waiver', '1' );
ob_start();
do_action( 'woocommerce_email_before_order_table', $other, false, false, $processing );
$other_paid = ob_get_clean();
creature_fd_exp_expect( false === strpos( $other_paid, $waiver_email ), 'a non-FD order does not get the waiver line' );

if ( ! isset( $GLOBALS['wp'] ) || ! is_object( $GLOBALS['wp'] ) ) {
	$GLOBALS['wp'] = new stdClass();
}
$GLOBALS['wp']->query_vars = array( 'rest_route' => '/wc/store/v1/checkout/10' );
$store_errors = new WP_Error();
Creature_Fd_Order_Experience::on_validate_before_payment( $fd, $store_errors );
creature_fd_exp_expect( $store_errors->has_errors(), 'Store API order-pay rejects an FD order without the waiver' );
$cart_errors = new WP_Error();
$GLOBALS['wp']->query_vars = array( 'rest_route' => '/wc/store/v1/checkout' );
Creature_Fd_Order_Experience::on_validate_before_payment( $fd, $cart_errors );
creature_fd_exp_expect( ! $cart_errors->has_errors(), 'regular Store API checkout is not asked for the waiver' );
$GLOBALS['wp']->query_vars = array( 'rest_route' => '/wc/store/v1/checkout/14' );
$plain_errors = new WP_Error();
Creature_Fd_Order_Experience::on_validate_before_payment( $other, $plain_errors );
creature_fd_exp_expect( ! $plain_errors->has_errors(), 'Store API order-pay ignores a non-FD order' );

$paypal_fd = creature_fd_exp_order( 15, array( new Creature_Fd_Exp_Item( 8634, array( 'design_id' => 'design-15' ) ) ) );
$threw = false;
try {
	Creature_Fd_Order_Experience::on_paypal_create_order( array( 'context' => 'pay-now', 'order_id' => 15, 'form' => array() ) );
} catch ( RuntimeException $e ) {
	$threw = $waiver_error === $e->getMessage();
}
creature_fd_exp_expect( $threw, 'PayPal pay-now rejects an FD order without the waiver' );
$threw = false;
try {
	Creature_Fd_Order_Experience::on_paypal_create_order(
		array(
			'context'  => 'pay-now',
			'order_id' => 15,
			'form'     => array( array( 'name' => 'creature_fd_cancellation_waiver', 'value' => '1' ) ),
		)
	);
} catch ( RuntimeException $e ) {
	$threw = true;
}
creature_fd_exp_expect( ! $threw && Creature_Fd_Order_Experience::order_has_waiver( $paypal_fd ), 'PayPal pay-now with the box ticked stores consent' );
creature_fd_exp_expect( '136.00' === $paypal_fd->get_total(), 'PayPal consent does not change the total' );
$threw = false;
try {
	Creature_Fd_Order_Experience::on_paypal_create_order( array( 'context' => 'pay-now', 'order_id' => 14, 'form' => array() ) );
} catch ( RuntimeException $e ) {
	$threw = true;
}
creature_fd_exp_expect( ! $threw, 'PayPal pay-now leaves a non-FD order alone' );
do_action( 'after_woocommerce_pay' );

echo "\n{$GLOBALS['creature_fd_exp_passed']} passed, {$GLOBALS['creature_fd_exp_failed']} failed\n";
exit( $GLOBALS['creature_fd_exp_failed'] > 0 ? 1 : 0 );
