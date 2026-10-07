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
		$GLOBALS['creature_fd_filters'][ $hook ][ (int) $priority ] = $kept;
		return true;
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

if ( ! function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
	function woocommerce_template_single_add_to_cart() {
		$GLOBALS['creature_fd_template_calls'] = isset( $GLOBALS['creature_fd_template_calls'] )
			? $GLOBALS['creature_fd_template_calls'] + 1
			: 1;
		echo 'WOO_ADD_TO_CART_FORM';
	}
}

add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
Creature_Fd_Only_Purchase::boot();

$hooks = creature_fd_hooks_for( 'Creature_Fd_Only_Purchase' );

foreach (
	array(
		'woocommerce_single_product_summary',
		'woocommerce_loop_add_to_cart_link',
		'render_block',
		'woocommerce_add_to_cart_validation',
		'woocommerce_add_cart_item_data',
		'woocommerce_store_api_validate_add_to_cart',
		'woocommerce_check_cart_items',
		'woocommerce_checkout_create_order_line_item',
	) as $required
) {
	creature_fd_expect( in_array( $required, $hooks, true ), "missing hook {$required}" );
}

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

$template_still_hooked = false;
foreach ( $GLOBALS['creature_fd_filters']['woocommerce_single_product_summary'][30] as $row ) {
	if ( 'woocommerce_template_single_add_to_cart' === $row[0] ) {
		$template_still_hooked = true;
	}
}
creature_fd_expect( ! $template_still_hooked, 'default single add-to-cart template should be unhooked' );

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
creature_fd_expect( is_string( $bb_html ) && false !== strpos( $bb_html, 'Design yours in Frame Designer' ), 'BB button label' );
creature_fd_expect( false !== strpos( (string) $bb_html, 'href="/apps/frame-designer.html"' ), 'BB button href is the plain FD url' );
creature_fd_expect( false === strpos( (string) $bb_html, 'frame-designer.html?' ), 'no invented query param' );
creature_fd_expect( false === strpos( (string) $bb_html, 'ajax_add_to_cart' ), 'no ajax class' );
creature_fd_expect( false === strpos( (string) $bb_html, 'data-product_id' ), 'no ajax product id attr' );
creature_fd_expect( false === strpos( (string) $bb_html, 'add-to-cart' ), 'no add-to-cart url' );
creature_fd_expect( false === strpos( (string) $bb_html, 'quantity' ), 'no quantity box' );

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

$loop_other = Creature_Fd_Only_Purchase::on_loop_link( '<a href="/?add-to-cart=100">Add</a>', $other );
creature_fd_expect( '<a href="/?add-to-cart=100">Add</a>' === $loop_other, 'other loop buttons stay' );

$GLOBALS['product'] = $bb;
$GLOBALS['creature_fd_template_calls'] = 0;
ob_start();
Creature_Fd_Only_Purchase::on_single_add_to_cart();
$single = ob_get_clean();
creature_fd_expect( false !== strpos( $single, 'Design yours in Frame Designer' ), 'single swaps the form' );
creature_fd_expect( false === strpos( $single, 'WOO_ADD_TO_CART_FORM' ), 'single does not render the Woo form' );
creature_fd_expect( 0 === $GLOBALS['creature_fd_template_calls'], 'template not called for a gated product' );

$GLOBALS['product'] = $other;
ob_start();
Creature_Fd_Only_Purchase::on_single_add_to_cart();
$single_other = ob_get_clean();
creature_fd_expect( false !== strpos( $single_other, 'WOO_ADD_TO_CART_FORM' ), 'other single products still use the Woo form' );

$block = Creature_Fd_Only_Purchase::on_render_block(
	'<form class="cart"><button class="ajax_add_to_cart">Add</button></form>',
	array( 'blockName' => 'woocommerce/add-to-cart-form' ),
	(object) array( 'context' => array( 'postId' => 8635 ) )
);
creature_fd_expect( false !== strpos( $block, 'Design yours in Frame Designer' ), 'block form replaced' );
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
