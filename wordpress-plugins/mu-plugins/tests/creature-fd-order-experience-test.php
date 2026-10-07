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
$yoke_button = strpos( (string) $yoke, 'Design yours in Frame Designer' );
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

echo "\n{$GLOBALS['creature_fd_exp_passed']} passed, {$GLOBALS['creature_fd_exp_failed']} failed\n";
exit( $GLOBALS['creature_fd_exp_failed'] > 0 ? 1 : 0 );
