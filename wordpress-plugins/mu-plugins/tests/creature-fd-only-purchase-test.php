<?php
/**
 * Decision tests for creature-fd-only-purchase.php.
 *
 *   php wordpress-plugins/mu-plugins/tests/creature-fd-only-purchase-test.php
 *
 * No WooCommerce install. Stubs stand in for the hooks the plugin registers.
 * The rear-end discount class is loaded so a kept set still counts as one
 * whole-rear-end set, and a bare yoke line does not.
 */

if ( PHP_SAPI !== 'cli' || ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) !== realpath( __FILE__ ) ) ) {
	return;
}

$GLOBALS['creature_fd_hooks']   = array();
$GLOBALS['creature_fd_notices'] = array();
$GLOBALS['creature_fd_wc']      = null;

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param callable $callback
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['creature_fd_hooks'][] = array( $hook, $callback, (int) $priority, (int) $accepted_args );
		if ( ! isset( $GLOBALS['creature_fd_filters'][ $hook ] ) ) {
			$GLOBALS['creature_fd_filters'][ $hook ] = array();
		}
		$GLOBALS['creature_fd_filters'][ $hook ][ (int) $priority ][] = array( $callback, (int) $accepted_args );
		ksort( $GLOBALS['creature_fd_filters'][ $hook ] );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		return remove_filter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['creature_fd_filters'][ $hook ][ (int) $priority ] ) ) {
			return false;
		}
		$kept = array();
		foreach ( $GLOBALS['creature_fd_filters'][ $hook ][ (int) $priority ] as $row ) {
			if ( $row[0] !== $callback ) {
				$kept[] = $row;
			}
		}
		if ( ! $kept ) {
			unset( $GLOBALS['creature_fd_filters'][ $hook ][ (int) $priority ] );
		} else {
			$GLOBALS['creature_fd_filters'][ $hook ][ (int) $priority ] = $kept;
		}
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		if ( empty( $GLOBALS['creature_fd_filters'][ $hook ] ) ) {
			return;
		}
		if ( ! isset( $GLOBALS['creature_fd_current_filter'] ) ) {
			$GLOBALS['creature_fd_current_filter'] = array();
		}
		$GLOBALS['creature_fd_current_filter'][] = $hook;
		$priorities = array_keys( $GLOBALS['creature_fd_filters'][ $hook ] );
		sort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $priority ) {
			if ( ! isset( $GLOBALS['creature_fd_filters'][ $hook ][ $priority ] ) || ! $GLOBALS['creature_fd_filters'][ $hook ][ $priority ] ) {
				continue;
			}
			$rows = $GLOBALS['creature_fd_filters'][ $hook ][ $priority ];
			foreach ( $rows as $row ) {
				$params = $args;
				if ( $row[1] > 0 && count( $params ) > $row[1] ) {
					$params = array_slice( $params, 0, $row[1] );
				}
				call_user_func_array( $row[0], $params );
			}
		}
		array_pop( $GLOBALS['creature_fd_current_filter'] );
	}
}

if ( ! function_exists( 'current_filter' ) ) {
	function current_filter() {
		if ( empty( $GLOBALS['creature_fd_current_filter'] ) ) {
			return '';
		}
		$stack = $GLOBALS['creature_fd_current_filter'];
		return (string) $stack[ count( $stack ) - 1 ];
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $callback = false ) {
		if ( empty( $GLOBALS['creature_fd_filters'][ $hook ] ) ) {
			return false;
		}
		if ( false === $callback ) {
			return true;
		}
		foreach ( $GLOBALS['creature_fd_filters'][ $hook ] as $priority => $rows ) {
			foreach ( $rows as $row ) {
				if ( $row[0] === $callback ) {
					return (int) $priority;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['creature_fd_filters'][ $hook ] ) ) {
			return $value;
		}
		ksort( $GLOBALS['creature_fd_filters'][ $hook ] );
		foreach ( $GLOBALS['creature_fd_filters'][ $hook ] as $rows ) {
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

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'notice' ) {
		$GLOBALS['creature_fd_notices'][] = array( (string) $message, (string) $type );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return $GLOBALS['creature_fd_wc'];
	}
}

require dirname( __DIR__ ) . '/creature-fd-only-purchase.php';
require dirname( __DIR__ ) . '/creature-rear-end-discount.php';

class Creature_Fd_Only_Test_Product {
	/** @var int */
	public $id;
	/** @var int */
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

	public function is_type( $type ) {
		return 'variation' === $type && $this->parent > 0;
	}

	public function get_type() {
		return $this->parent > 0 ? 'variation' : 'simple';
	}
}

class Creature_Fd_Only_Test_Cart {
	/** @var array */
	public $items = array();
	/** @var string[] */
	public $removed = array();

	public function get_cart() {
		return $this->items;
	}

	public function remove_cart_item( $key ) {
		$this->removed[] = (string) $key;
		unset( $this->items[ $key ] );
		return true;
	}
}

class Creature_Fd_Only_Test_WC {
	/** @var Creature_Fd_Only_Test_Cart */
	public $cart;

	public function __construct( Creature_Fd_Only_Test_Cart $cart ) {
		$this->cart = $cart;
	}
}

class Creature_Fd_Only_Test_Line {
	/** @var array */
	public $meta = array();

	public function add_meta_data( $key, $value, $unique = false ) {
		unset( $unique );
		$this->meta[ $key ] = $value;
	}
}

$failures = 0;
$checks   = 0;

/**
 * @param bool   $condition
 * @param string $message
 */
function creature_fd_expect( $condition, $message ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL {$message}\n" );
}

function creature_fd_clear_notices() {
	$GLOBALS['creature_fd_notices'] = array();
}

function creature_fd_hooks_for( $class_name ) {
	$found = array();
	foreach ( $GLOBALS['creature_fd_hooks'] as $row ) {
		$callback = $row[1];
		if ( is_array( $callback ) && isset( $callback[0] ) && $callback[0] === $class_name ) {
			$found[] = $row[0];
		}
	}
	return $found;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

if ( ! function_exists( 'woocommerce_simple_add_to_cart' ) ) {
	function woocommerce_simple_add_to_cart() {
		echo 'WOO_ADD_TO_CART_FORM';
	}
}

if ( ! function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
	function woocommerce_template_single_add_to_cart() {
		$product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;
		$type    = ( is_object( $product ) && method_exists( $product, 'get_type' ) ) ? $product->get_type() : 'simple';
		do_action( 'woocommerce_' . $type . '_add_to_cart' );
	}
}

add_action( 'woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );
Creature_Fd_Only_Purchase::boot();

$hooks = creature_fd_hooks_for( 'Creature_Fd_Only_Purchase' );

foreach (
	array(
		'woocommerce_simple_add_to_cart',
		'woocommerce_loop_add_to_cart_link',
		'astra_addon_shop_cards_buttons_html',
		'render_block',
		'woocommerce_add_to_cart_validation',
		'woocommerce_add_cart_item_data',
		'woocommerce_store_api_validate_add_to_cart',
		'woocommerce_check_cart_items',
		'woocommerce_checkout_create_order_line_item',
		'wcpay_payment_request_is_product_supported',
		'wcpay_woopay_button_is_product_supported',
		'woocommerce_paypal_payments_product_supports_payment_request_button',
		'woocommerce_paypal_payments_product_buttons_disabled',
	) as $required
) {
	creature_fd_expect( in_array( $required, $hooks, true ), "missing hook {$required}" );
}

creature_fd_expect(
	! in_array( 'woocommerce_single_product_summary', $hooks, true ),
	'summary hook must stay untouched'
);

foreach (
	array(
		'woocommerce_order_before_calculate_totals',
		'woocommerce_before_pay_action',
		'woocommerce_rest_insert_shop_order_object',
		'woocommerce_rest_pre_insert_shop_order_object',
		'woocommerce_checkout_order_processed',
		'wp',
	) as $forbidden
) {
	creature_fd_expect( ! in_array( $forbidden, $hooks, true ), "order/order-pay hook registered: {$forbidden}" );
}

$validation_args = 0;
foreach ( $GLOBALS['creature_fd_hooks'] as $row ) {
	if ( 'woocommerce_add_to_cart_validation' === $row[0] && is_array( $row[1] ) && 'Creature_Fd_Only_Purchase' === $row[1][0] ) {
		$validation_args = $row[3];
	}
}
creature_fd_expect( 6 === $validation_args, 'validation filter must accept 6 args so Store API cart item data arrives' );

creature_fd_expect(
	30 === has_action( 'woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart' ),
	'Woo simple handler stays registered until a guarded product renders'
);

creature_fd_expect(
	Creature_Fd_Only_Purchase::product_ids() === array( 8634, 8635, 8636 ),
	'default product ids'
);
creature_fd_expect(
	Creature_Fd_Only_Purchase::coming_soon_ids() === array( 8636 ),
	'default coming soon ids'
);
creature_fd_expect(
	'/apps/frame-designer.html' === Creature_Fd_Only_Purchase::designer_url(),
	'default designer url'
);
creature_fd_expect( Creature_Fd_Only_Purchase::is_gated_id( 8634 ), '8634 gated' );
creature_fd_expect( Creature_Fd_Only_Purchase::is_gated_id( 8635 ), '8635 gated' );
creature_fd_expect( Creature_Fd_Only_Purchase::is_gated_id( 8636 ), '8636 gated' );
creature_fd_expect( ! Creature_Fd_Only_Purchase::is_gated_id( 8637 ), '8637 is not a checkout part' );
creature_fd_expect( Creature_Fd_Only_Purchase::is_coming_soon_id( 8636 ), '8636 coming soon' );
creature_fd_expect( ! Creature_Fd_Only_Purchase::is_coming_soon_id( 8634 ), '8634 is on sale' );

add_filter(
	'creature_fd_only_product_ids',
	static function () {
		return array( 8634, 'nope', 0, 8634 );
	}
);
creature_fd_expect(
	Creature_Fd_Only_Purchase::product_ids() === array( 8634 ),
	'product id filter normalizes to unique positive ints'
);
creature_fd_expect( ! Creature_Fd_Only_Purchase::is_gated_id( 8635 ), 'filtered-out 8635 is not gated' );
$GLOBALS['creature_fd_filters']['creature_fd_only_product_ids'] = array();
creature_fd_expect(
	Creature_Fd_Only_Purchase::product_ids() === array( 8634, 8635, 8636 ),
	'product ids restored'
);

add_filter(
	'creature_fd_only_coming_soon_ids',
	static function () {
		return array();
	}
);
creature_fd_expect( ! Creature_Fd_Only_Purchase::is_coming_soon_id( 8636 ), 'empty coming soon filter' );
$GLOBALS['creature_fd_filters']['creature_fd_only_coming_soon_ids'] = array();
creature_fd_expect( Creature_Fd_Only_Purchase::is_coming_soon_id( 8636 ), 'coming soon restored' );

add_filter(
	'creature_fd_only_designer_url',
	static function () {
		return 'javascript:alert(1)';
	}
);
creature_fd_expect(
	'/apps/frame-designer.html' === Creature_Fd_Only_Purchase::designer_url(),
	'javascript designer url is rejected'
);
$GLOBALS['creature_fd_filters']['creature_fd_only_designer_url'] = array();

$bb   = new Creature_Fd_Only_Test_Product( 8634 );
$drop = new Creature_Fd_Only_Test_Product( 8636 );
$other = new Creature_Fd_Only_Test_Product( 100 );

$bb_html = Creature_Fd_Only_Purchase::purchase_markup( $bb, true );
creature_fd_expect( is_string( $bb_html ) && false !== strpos( $bb_html, 'Open Frame Designer' ), 'BB button label' );
creature_fd_expect( false !== strpos( (string) $bb_html, 'href="/apps/frame-designer.html"' ), 'BB button href is the plain FD url' );
creature_fd_expect( false === strpos( (string) $bb_html, 'frame-designer.html?' ), 'no invented query param' );
creature_fd_expect( false === strpos( (string) $bb_html, 'ajax_add_to_cart' ), 'no ajax class' );
creature_fd_expect( false === strpos( (string) $bb_html, 'data-product_id' ), 'no ajax product id attr' );
creature_fd_expect( false === strpos( (string) $bb_html, 'add-to-cart' ), 'no add-to-cart url' );
creature_fd_expect( false === strpos( (string) $bb_html, 'quantity' ), 'no quantity box' );
$bb_button = strpos( (string) $bb_html, 'Open Frame Designer' );
$bb_lead   = strpos( (string) $bb_html, 'Design files are delivered within 5 working days of payment.' );
creature_fd_expect( false !== $bb_button && false !== $bb_lead && $bb_button < $bb_lead, 'lead time sits under the FD button' );
creature_fd_expect( false !== strpos( (string) $bb_html, 'creature-fd-lead-time' ), 'lead time paragraph class' );

function creature_fd_only_test_lead() {
	return '2 working days';
}
add_filter( 'creature_fd_lead_time', 'creature_fd_only_test_lead' );
$bb_over = Creature_Fd_Only_Purchase::purchase_markup( $bb, true );
creature_fd_expect(
	false !== strpos( (string) $bb_over, 'Design files are delivered within 2 working days of payment.' ),
	'product lead time follows creature_fd_lead_time'
);
remove_filter( 'creature_fd_lead_time', 'creature_fd_only_test_lead' );

$bb_bare = Creature_Fd_Only_Purchase::purchase_markup( $bb, false );
creature_fd_expect( false === strpos( (string) $bb_bare, 'creature-fd-lead-time' ), 'loop link has no lead time paragraph' );

$soon_wrap = Creature_Fd_Only_Purchase::purchase_markup( $drop, true );
creature_fd_expect( false === strpos( (string) $soon_wrap, 'creature-fd-lead-time' ), 'coming soon page skips the lead time' );
creature_fd_expect( false === strpos( (string) $soon_wrap, 'working days' ), 'coming soon page has no lead time sentence' );

$soon_html = Creature_Fd_Only_Purchase::purchase_markup( $drop, false );
creature_fd_expect( false !== strpos( (string) $soon_html, '>Coming soon<' ), 'dropouts button is Coming soon' );
creature_fd_expect( false === strpos( (string) $soon_html, 'href=' ), 'coming soon is not a link' );
creature_fd_expect( false === strpos( (string) $soon_html, 'add-to-cart' ), 'coming soon is not an add' );
creature_fd_expect( false !== strpos( (string) $soon_html, 'aria-disabled="true"' ), 'coming soon is disabled' );

creature_fd_expect( null === Creature_Fd_Only_Purchase::purchase_markup( $other ), 'other products keep Woo markup' );

$loop = Creature_Fd_Only_Purchase::on_loop_link(
	'<a href="/?add-to-cart=8634" class="button ajax_add_to_cart" data-product_id="8634">Add to basket</a>',
	$bb
);
creature_fd_expect( false === strpos( $loop, 'ajax_add_to_cart' ), 'loop strips ajax' );
creature_fd_expect( false === strpos( $loop, 'add-to-cart' ), 'loop strips add-to-cart' );
creature_fd_expect( false !== strpos( $loop, 'href="/apps/frame-designer.html"' ), 'loop uses FD url' );
creature_fd_expect( false !== strpos( $loop, 'class="button creature-fd-only-link"' ), 'loop link keeps the Woo button class' );

$loop_other = Creature_Fd_Only_Purchase::on_loop_link( '<a href="/?add-to-cart=100">Add</a>', $other );
creature_fd_expect( '<a href="/?add-to-cart=100">Add</a>' === $loop_other, 'other loop buttons stay' );

/**
 * One pass of Woo's simple add-to-cart action, the path Astra calls.
 *
 * @return string
 */
function creature_fd_render_simple() {
	ob_start();
	do_action( 'woocommerce_simple_add_to_cart' );
	return (string) ob_get_clean();
}

$GLOBALS['product'] = $other;
$plain = creature_fd_render_simple();
creature_fd_expect( 1 === substr_count( $plain, 'WOO_ADD_TO_CART_FORM' ), 'a normal product renders exactly one Woo buy form' );
creature_fd_expect( false === strpos( $plain, 'Open Frame Designer' ), 'a normal product does not get the FD button' );
creature_fd_expect( false === strpos( $plain, 'Coming soon' ), 'a normal product does not get Coming soon' );

$plain_again = creature_fd_render_simple();
creature_fd_expect( 1 === substr_count( $plain_again, 'WOO_ADD_TO_CART_FORM' ), 'a second view of a normal product is still one form' );

$GLOBALS['product'] = $bb;
$yoke = creature_fd_render_simple();
creature_fd_expect( 0 === substr_count( $yoke, 'WOO_ADD_TO_CART_FORM' ), '8634 renders zero Woo buy forms' );
creature_fd_expect( 1 === substr_count( $yoke, 'Open Frame Designer' ), '8634 renders one FD button' );
creature_fd_expect( false === strpos( $yoke, 'quantity' ), '8634 form has no quantity box' );
creature_fd_expect( false === strpos( $yoke, 'add-to-cart' ), '8634 form is not an add-to-cart' );

$GLOBALS['product'] = $other;
$after_yoke = creature_fd_render_simple();
creature_fd_expect( 1 === substr_count( $after_yoke, 'WOO_ADD_TO_CART_FORM' ), 'the Woo handler is restored after a guarded product' );
creature_fd_expect( false === strpos( $after_yoke, 'creature-fd-only' ), 'the restored product does not keep the FD control' );

$GLOBALS['product'] = $drop;
$soon = creature_fd_render_simple();
creature_fd_expect( 0 === substr_count( $soon, 'WOO_ADD_TO_CART_FORM' ), '8636 renders zero Woo buy forms' );
creature_fd_expect( 1 === substr_count( $soon, '>Coming soon<' ), '8636 renders one Coming soon control' );
creature_fd_expect( false === strpos( $soon, 'href=' ), '8636 Coming soon is not a link' );
creature_fd_expect( false === strpos( $soon, 'Open Frame Designer' ), '8636 does not get the FD link' );

add_action(
	'woocommerce_single_product_summary',
	static function () {
		echo 'ASTRA_STRUCTURE_START';
		woocommerce_template_single_add_to_cart();
		echo 'ASTRA_STRUCTURE_END';
	},
	10
);

$GLOBALS['product'] = $other;
ob_start();
do_action( 'woocommerce_single_product_summary' );
$astra_plain = (string) ob_get_clean();
creature_fd_expect( 1 === substr_count( $astra_plain, 'ASTRA_STRUCTURE_START' ), 'Astra structure runs once' );
creature_fd_expect( 1 === substr_count( $astra_plain, 'WOO_ADD_TO_CART_FORM' ), 'Astra normal product still has one buy form' );
creature_fd_expect( false === strpos( $astra_plain, 'Open Frame Designer' ), 'Astra normal product has no extra FD button' );

$GLOBALS['product'] = $bb;
ob_start();
do_action( 'woocommerce_single_product_summary' );
$astra_yoke = (string) ob_get_clean();
creature_fd_expect( 1 === substr_count( $astra_yoke, 'ASTRA_STRUCTURE_START' ), 'Astra yoke page structure runs once' );
creature_fd_expect( 0 === substr_count( $astra_yoke, 'WOO_ADD_TO_CART_FORM' ), 'Astra yoke page does not keep the Woo form' );
creature_fd_expect( 1 === substr_count( $astra_yoke, 'Open Frame Designer' ), 'Astra yoke page shows one FD button inside the form slot' );
$fd_at = strpos( $astra_yoke, 'Open Frame Designer' );
$end_at = strpos( $astra_yoke, 'ASTRA_STRUCTURE_END' );
creature_fd_expect( false !== $fd_at && false !== $end_at && $fd_at < $end_at, 'FD button is inside Astra structure, not after it' );

$card_html = '<span class="onsale">Sale</span><a href="/?add-to-cart=8634" data-quantity="1" class="ast-on-card-button ast-select-options-trigger product_type_simple add_to_cart_button ajax_add_to_cart" data-product_id="8634" rel="nofollow"><span class="ast-card-action-tooltip">Add to basket</span></a>';
$card = Creature_Fd_Only_Purchase::on_astra_card_buttons( $card_html, $bb );
creature_fd_expect( false !== strpos( $card, 'Sale' ), 'card sale badge stays' );
creature_fd_expect( false === strpos( $card, 'ajax_add_to_cart' ), 'card button is not ajax' );
creature_fd_expect( false === strpos( $card, 'add_to_cart_button' ), 'card button drops the add-to-cart class' );
creature_fd_expect( false === strpos( $card, 'data-product_id' ), 'card button drops the product id' );
creature_fd_expect( false === strpos( $card, 'add-to-cart' ), 'card button is not an add-to-cart url' );
creature_fd_expect( false !== strpos( $card, 'ast-on-card-button' ), 'card button keeps Astra placement class' );
creature_fd_expect( false !== strpos( $card, 'class="ast-on-card-button creature-fd-only-link"' ), 'card link omits the Woo button class' );
creature_fd_expect( false !== strpos( $card, '<span class="ast-card-action-tooltip">Open Frame Designer</span>' ), 'card link keeps the tooltip' );
creature_fd_expect( false !== strpos( $card, 'href="/apps/frame-designer.html"' ), 'card button links to Frame Designer' );

$card_soon_html = '<a href="/?add-to-cart=8636" class="ast-on-card-button ajax_add_to_cart" data-product_id="8636">Add</a>';
$card_soon = Creature_Fd_Only_Purchase::on_astra_card_buttons( $card_soon_html, $drop );
creature_fd_expect( false !== strpos( $card_soon, '>Coming soon<' ), 'dropouts card is Coming soon' );
creature_fd_expect( false === strpos( $card_soon, 'ajax_add_to_cart' ), 'dropouts card is not ajax' );
creature_fd_expect( false === strpos( $card_soon, 'href=' ), 'dropouts card is not a link' );
creature_fd_expect( false !== strpos( $card_soon, '<span class="ast-on-card-button button disabled creature-fd-only-soon"' ), 'coming soon card stays a span with the button class' );

$card_other = Creature_Fd_Only_Purchase::on_astra_card_buttons( $card_html, $other );
creature_fd_expect( $card_html === $card_other, 'other product cards keep Astra ajax button' );

creature_fd_expect(
	false === apply_filters( 'wcpay_payment_request_is_product_supported', true, $bb ),
	'WooPayments express checkout is off for 8634'
);
creature_fd_expect(
	false === apply_filters( 'wcpay_woopay_button_is_product_supported', true, $drop ),
	'WooPay express button is off for 8636'
);
creature_fd_expect(
	false === apply_filters( 'woocommerce_paypal_payments_product_supports_payment_request_button', true, $bb ),
	'PayPal smart buttons are off for 8634'
);
creature_fd_expect(
	true === apply_filters( 'woocommerce_paypal_payments_product_buttons_disabled', false, array( 'product' => $bb ) ),
	'PayPal product-page disable flag is set for 8634'
);
creature_fd_expect(
	true === apply_filters( 'wcpay_payment_request_is_product_supported', true, $other ),
	'WooPayments express checkout stays on for a normal product'
);
creature_fd_expect(
	true === apply_filters( 'woocommerce_paypal_payments_product_supports_payment_request_button', true, $other ),
	'PayPal smart buttons stay on for a normal product'
);
creature_fd_expect(
	false === apply_filters( 'woocommerce_paypal_payments_product_buttons_disabled', false, array( 'product' => $other ) ),
	'PayPal disable flag stays off for a normal product'
);
creature_fd_expect(
	true === apply_filters( 'wcpay_payment_request_is_product_supported', true, null ),
	'express filter does not hide buttons when no product is passed'
);

$block = Creature_Fd_Only_Purchase::on_render_block(
	'<form class="cart"><button class="ajax_add_to_cart">Add</button></form>',
	array( 'blockName' => 'woocommerce/add-to-cart-form' ),
	(object) array( 'context' => array( 'postId' => 8635 ) )
);
creature_fd_expect( false !== strpos( $block, 'Open Frame Designer' ), 'block form replaced' );
creature_fd_expect( false === strpos( $block, 'ajax_add_to_cart' ), 'block form has no ajax' );

$soon_block = Creature_Fd_Only_Purchase::on_render_block(
	'<a class="ajax_add_to_cart" data-product_id="8636" href="/?add-to-cart=8636">Add</a>',
	array( 'blockName' => 'woocommerce/product-button' ),
	null
);
creature_fd_expect( false !== strpos( $soon_block, 'Coming soon' ), 'product button block falls back to data-product_id' );
creature_fd_expect( false === strpos( $soon_block, 'add-to-cart' ), 'product button block is not an add' );

$options_block = Creature_Fd_Only_Purchase::on_render_block(
	'<div data-product_id="8634">qty</div>',
	array(
		'blockName' => 'woocommerce/add-to-cart-with-options',
		'attrs'     => array( 'productId' => 8634 ),
	),
	null
);
creature_fd_expect( false !== strpos( $options_block, 'frame-designer.html' ), 'add-to-cart-with-options block replaced' );
creature_fd_expect( false === strpos( $options_block, 'qty' ), 'options block quantity is not kept' );

$untouched = Creature_Fd_Only_Purchase::on_render_block(
	'<p>Price £58</p>',
	array( 'blockName' => 'woocommerce/product-price' ),
	(object) array( 'context' => array( 'postId' => 8634 ) )
);
creature_fd_expect( '<p>Price £58</p>' === $untouched, 'price block is not rewritten' );

$uuid = '550e8400-e29b-41d4-a716-446655440000';
creature_fd_expect( $uuid === Creature_Fd_Only_Purchase::normalize_design_id( '  ' . $uuid . '  ' ), 'uuid kept' );
creature_fd_expect( '' === Creature_Fd_Only_Purchase::normalize_design_id( '' ), 'empty rejected' );
creature_fd_expect( '' === Creature_Fd_Only_Purchase::normalize_design_id( '   ' ), 'blank rejected' );
creature_fd_expect( '' === Creature_Fd_Only_Purchase::normalize_design_id( true ), 'bool rejected' );
creature_fd_expect( '' === Creature_Fd_Only_Purchase::normalize_design_id( null ), 'null rejected' );
creature_fd_expect( $uuid === Creature_Fd_Only_Purchase::normalize_design_id( array( $uuid, 'other' ) ), 'array uses the first value' );
creature_fd_expect( '' === Creature_Fd_Only_Purchase::normalize_design_id( '<script>' ), 'markup rejected' );
creature_fd_expect( '' === Creature_Fd_Only_Purchase::normalize_design_id( str_repeat( 'a', 129 ) ), 'overlong rejected' );

creature_fd_expect(
	Creature_Fd_Only_Purchase::add_is_allowed( 100, 0, array(), '' ),
	'other products are always allowed'
);
creature_fd_expect(
	! Creature_Fd_Only_Purchase::add_is_allowed( 8634, 0, array(), '' ),
	'bare 8634 rejected'
);
creature_fd_expect(
	Creature_Fd_Only_Purchase::add_is_allowed( 8634, 0, array( 'design_id' => $uuid ), '' ),
	'cart item design_id allowed'
);
creature_fd_expect(
	Creature_Fd_Only_Purchase::add_is_allowed( 10, 8635, array(), $uuid ),
	'variation id plus request design_id allowed'
);
creature_fd_expect(
	! Creature_Fd_Only_Purchase::add_is_allowed( 8636, 0, array( 'design_id' => '  ' ), '' ),
	'blank design_id does not sneak 8636 through'
);

$stamped = Creature_Fd_Only_Purchase::stamp_design_id( array(), 8634, 0, $uuid );
creature_fd_expect( isset( $stamped['design_id'] ) && $stamped['design_id'] === $uuid, 'stamp copies design_id' );
$kept = Creature_Fd_Only_Purchase::stamp_design_id( array( 'design_id' => 'already' ), 8634, 0, $uuid );
creature_fd_expect( 'already' === $kept['design_id'], 'stamp does not overwrite' );
$other_stamp = Creature_Fd_Only_Purchase::stamp_design_id( array( 'note' => 'x' ), 100, 0, $uuid );
creature_fd_expect( ! isset( $other_stamp['design_id'] ), 'stamp ignores other products' );

$_REQUEST = array();
creature_fd_clear_notices();
$preview_calls = 0;
$inject_design = static function ( $data, $product_id ) use ( &$preview_calls ) {
	$preview_calls++;
	if ( 8634 === (int) $product_id ) {
		$data['design_id'] = 'from-filter';
	}
	return $data;
};
add_filter( 'woocommerce_add_cart_item_data', $inject_design, 30, 2 );

$classic_with_filter = apply_filters( 'woocommerce_add_to_cart_validation', true, 8634, 1 );
creature_fd_expect( true === $classic_with_filter, 'classic validation allows design_id added by woocommerce_add_cart_item_data' );
creature_fd_expect( $preview_calls >= 1, 'classic validation previews cart item data' );

creature_fd_clear_notices();
$classic_bare = apply_filters( 'woocommerce_add_to_cart_validation', true, 8635, 1 );
creature_fd_expect( false === $classic_bare, 'classic ?add-to-cart=8635 with no design_id is rejected' );
creature_fd_expect( 1 === count( $GLOBALS['creature_fd_notices'] ), 'classic rejection adds one notice' );
creature_fd_expect(
	false !== strpos( $GLOBALS['creature_fd_notices'][0][0], 'href="/apps/frame-designer.html"' ),
	'classic rejection notice links to Frame Designer'
);
$calls_after_classic = $preview_calls;

$store_api_pass = apply_filters(
	'woocommerce_add_to_cart_validation',
	true,
	8634,
	1,
	0,
	array(),
	array( 'design_id' => $uuid )
);
creature_fd_expect( true === $store_api_pass, 'Store API sixth arg design_id is allowed' );
creature_fd_expect( $preview_calls === $calls_after_classic, 'Store API validation does not run the cart item data filter again' );

creature_fd_clear_notices();
$store_api_bare = apply_filters(
	'woocommerce_add_to_cart_validation',
	true,
	8635,
	1,
	0,
	array(),
	array()
);
creature_fd_expect( false === $store_api_bare, 'Store API add with empty cart item data is rejected' );
creature_fd_expect( 1 === count( $GLOBALS['creature_fd_notices'] ), 'one notice for a rejected add' );
creature_fd_expect( 'error' === $GLOBALS['creature_fd_notices'][0][1], 'notice is an error' );
creature_fd_expect(
	false !== strpos( $GLOBALS['creature_fd_notices'][0][0], 'Design your part in Frame Designer first.' ),
	'notice names Frame Designer'
);
creature_fd_expect(
	false !== strpos( $GLOBALS['creature_fd_notices'][0][0], 'href="/apps/frame-designer.html"' ),
	'notice links to Frame Designer'
);

creature_fd_clear_notices();
$already_failed = apply_filters( 'woocommerce_add_to_cart_validation', false, 8634, 1 );
creature_fd_expect( false === $already_failed, 'an earlier validation failure stays failed' );
creature_fd_expect( array() === $GLOBALS['creature_fd_notices'], 'no second notice when validation already failed' );

remove_filter( 'woocommerce_add_cart_item_data', $inject_design, 30 );

$_REQUEST['design_id'] = $uuid;
creature_fd_clear_notices();
$query_add = apply_filters( 'woocommerce_add_to_cart_validation', true, 8634, 1 );
creature_fd_expect( true === $query_add, '?add-to-cart with design_id passes' );
$written = apply_filters( 'woocommerce_add_cart_item_data', array(), 8634, 0, 1 );
creature_fd_expect( isset( $written['design_id'] ) && $written['design_id'] === $uuid, 'request design_id is stored on the line' );

$_REQUEST['design_id'] = 'not a design';
$junk = apply_filters( 'woocommerce_add_cart_item_data', array(), 8634, 0, 1 );
creature_fd_expect( ! array_key_exists( 'design_id', $junk ), 'unsafe request design_id is not stamped' );

$_REQUEST = array();
$bare_keys = Creature_Fd_Only_Purchase::bare_line_keys(
	array(
		'keep-set-bb'   => array( 'product_id' => 8634, 'quantity' => 1, 'design_id' => 'same' ),
		'keep-set-ss'   => array( 'product_id' => 8635, 'quantity' => 1, 'design_id' => 'same' ),
		'keep-set-drop' => array(
			'product_id' => 8636,
			'quantity'   => 1,
			'meta_data'  => array( (object) array( 'key' => 'design_id', 'value' => 'same' ) ),
		),
		'bare'          => array( 'product_id' => 8634, 'quantity' => 1 ),
		'other'         => array( 'product_id' => 50, 'quantity' => 1 ),
	)
);
creature_fd_expect( array( 'bare' ) === $bare_keys, 'only the bare yoke line is removed' );

$kept_lines = array(
	array( 'product_id' => 8634, 'variation_id' => 0, 'qty' => 1, 'design_id' => 'same' ),
	array( 'product_id' => 8635, 'variation_id' => 0, 'qty' => 1, 'design_id' => 'same' ),
	array( 'product_id' => 8636, 'variation_id' => 0, 'qty' => 1, 'design_id' => 'same' ),
);
creature_fd_expect( 1 === Creature_Rear_End_Discount::count_sets( $kept_lines ), 'kept lines still form one rear-end set' );
creature_fd_expect(
	0 === Creature_Rear_End_Discount::count_sets(
		array(
			array( 'product_id' => 8634, 'qty' => 1, 'design_id' => '' ),
			array( 'product_id' => 8635, 'qty' => 1, 'design_id' => '' ),
			array( 'product_id' => 8636, 'qty' => 1, 'design_id' => '' ),
		)
	),
	'bare lines never earned the discount'
);

$cart = new Creature_Fd_Only_Test_Cart();
$cart->items = array(
	'old'   => array( 'product_id' => 8634, 'quantity' => 1 ),
	'old2'  => array( 'product_id' => 8635, 'quantity' => 2 ),
	'paid'  => array( 'product_id' => 8636, 'quantity' => 1, 'design_id' => 'same' ),
	'spare' => array( 'product_id' => 70, 'quantity' => 1 ),
);
$GLOBALS['creature_fd_wc'] = new Creature_Fd_Only_Test_WC( $cart );
creature_fd_clear_notices();
Creature_Fd_Only_Purchase::on_check_cart_items();
creature_fd_expect( array( 'old', 'old2' ) === $cart->removed, 'stale bare lines are removed together' );
creature_fd_expect( isset( $cart->items['paid'] ) && isset( $cart->items['spare'] ), 'designed and other lines stay' );
creature_fd_expect( 1 === count( $GLOBALS['creature_fd_notices'] ), 'one notice for the whole sweep' );

creature_fd_clear_notices();
Creature_Fd_Only_Purchase::on_check_cart_items();
creature_fd_expect( array() === $GLOBALS['creature_fd_notices'], 'a clean basket does not notice again' );

$product = new Creature_Fd_Only_Test_Product( 8634 );
$threw   = false;
try {
	Creature_Fd_Only_Purchase::on_store_api_validate( $product, array( 'id' => 8634, 'cart_item_data' => array() ) );
} catch ( Exception $error ) {
	$threw = false !== strpos( $error->getMessage(), 'Design your part in Frame Designer first.' );
}
creature_fd_expect( $threw, 'Store API action throws when design_id is missing' );

Creature_Fd_Only_Purchase::on_store_api_validate(
	$product,
	array(
		'id'             => 8634,
		'cart_item_data' => array( 'design_id' => $uuid ),
	)
);
creature_fd_expect( true, 'Store API action allows cart item design_id' );

$line = new Creature_Fd_Only_Test_Line();
Creature_Fd_Only_Purchase::on_checkout_line_item(
	$line,
	'key',
	array(
		'product_id' => 8634,
		'quantity'   => 1,
		'design_id'  => $uuid,
	),
	null
);
creature_fd_expect( isset( $line->meta['design_id'] ) && $line->meta['design_id'] === $uuid, 'checkout copies design_id onto the order line' );

$bare_line = new Creature_Fd_Only_Test_Line();
Creature_Fd_Only_Purchase::on_checkout_line_item(
	$bare_line,
	'key',
	array( 'product_id' => 8634, 'quantity' => 1 ),
	null
);
creature_fd_expect( array() === $bare_line->meta, 'checkout does not invent a design_id' );

$other_line = new Creature_Fd_Only_Test_Line();
Creature_Fd_Only_Purchase::on_checkout_line_item(
	$other_line,
	'key',
	array(
		'product_id' => 100,
		'design_id'  => $uuid,
	),
	null
);
creature_fd_expect( array() === $other_line->meta, 'checkout leaves other products alone' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failed, {$checks} checks\n" );
	exit( 1 );
}

echo "{$checks} checks passed\n";
exit( 0 );
