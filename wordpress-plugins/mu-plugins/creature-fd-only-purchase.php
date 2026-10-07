<?php
/**
 * Plugin Name: Creature Cycles Frame Designer Only Purchase
 * Description: Frame Designer is the only way to buy the BB yoke (8634), SS yoke (8635), and dropouts (8636). Hides the catalogue add-to-cart control and rejects basket lines with no design_id. Must-use plugin. Does not touch REST-created orders or order-pay.
 * Version: 1.0.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 *
 * Copy this file to wp-content/mu-plugins/ (not into a subfolder). WooCommerce
 * must already be active. Layout installs it. It does not publish products.
 *
 * Purchase path this file must leave alone
 * ---------------------------------------
 * Frame Designer does not add these parts to the basket. Save / Continue to
 * shop posts the design to the tools-api. The API creates a pending WooCommerce
 * order with POST /wp-json/wc/v3/orders (line meta design_id and
 * creature_design_id) and the browser is sent to that order's payment_url
 * (/checkout/order-pay/{id}/?pay_for_order=true&key=…). Order-pay never runs
 * cart validation. Nothing here hooks order creation, order totals, or
 * order-pay, so that checkout and the whole-rear-end discount fee on the
 * order are unchanged.
 *
 * A basket line is allowed only when it carries design_id. That keeps a
 * future send-to-cart, and the rear-end discount's cart fee, working: the
 * discount ignores lines with no design_id and counts lines that share one.
 *
 * Deep link
 * ---------
 * frame-designer.html only reads ?design= and ?resume= (an existing saved
 * design). It has no part or product query param, so the button is the plain
 * Frame Designer URL. Filter creature_fd_only_designer_url to change it.
 *
 * Dropouts (8636) stay Coming soon: the add-to-cart control becomes a
 * disabled "Coming soon" button, and the product's own Coming soon copy is
 * left as written. Filter creature_fd_only_coming_soon_ids to hand 8636 the
 * Frame Designer button without opening a bare catalogue add. Filter
 * creature_fd_only_product_ids to change which products are guarded.
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Catalogue guard for parts that must be bought from Frame Designer.
 */
final class Creature_Fd_Only_Purchase {

	const PRODUCT_IDS     = array( 8634, 8635, 8636 );
	const COMING_SOON_IDS = array( 8636 );
	const DESIGNER_URL    = '/apps/frame-designer.html';
	const NOTICE          = 'Design your part in Frame Designer first.';

	/** @var bool */
	private static $booted = false;

	/**
	 * True while validation is previewing woocommerce_add_cart_item_data.
	 * Stops a nested validation call from rejecting mid-preview.
	 *
	 * @var bool
	 */
	private static $previewing = false;

	public static function boot() {
		if ( self::$booted || ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		self::$booted = true;

		// Price stays on woocommerce_template_single_price (priority 10).
		// This only replaces the form, which is where the quantity box lives.
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'on_single_add_to_cart' ), 30 );

		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'on_loop_link' ), 99, 2 );
		add_filter( 'render_block', array( __CLASS__, 'on_render_block' ), 10, 3 );

		// Accepted args must be 6. The Store API passes cart item data in the
		// sixth argument; the classic form handler and ?add-to-cart= do not.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'on_add_to_cart_validation' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'on_add_cart_item_data' ), 20, 4 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'on_store_api_validate' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'on_check_cart_items' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'on_checkout_line_item' ), 10, 4 );
	}

	/**
	 * Product ids that cannot be bought from the catalogue.
	 *
	 * @return int[]
	 */
	public static function product_ids() {
		$ids = apply_filters( 'creature_fd_only_product_ids', self::PRODUCT_IDS );
		return self::normalize_ids( $ids );
	}

	/**
	 * Guarded ids whose button stays "Coming soon" instead of linking to
	 * Frame Designer. 8636 until dropouts are on sale.
	 *
	 * @return int[]
	 */
	public static function coming_soon_ids() {
		$ids = apply_filters( 'creature_fd_only_coming_soon_ids', self::COMING_SOON_IDS );
		return self::normalize_ids( $ids );
	}

	/**
	 * Frame Designer page. Root-relative on the shop, or an http(s) URL.
	 *
	 * @return string
	 */
	public static function designer_url() {
		$url = apply_filters( 'creature_fd_only_designer_url', self::DESIGNER_URL );
		if ( ! is_string( $url ) ) {
			return self::DESIGNER_URL;
		}
		$url = trim( $url );
		if ( '' === $url || preg_match( '/[\r\n]/', $url ) || preg_match( '#^\s*(javascript|data):#i', $url ) ) {
			return self::DESIGNER_URL;
		}
		$relative = isset( $url[0] ) && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] );
		$absolute = (bool) preg_match( '#^https?://#i', $url );
		if ( ! $relative && ! $absolute ) {
			return self::DESIGNER_URL;
		}
		return $url;
	}

	/**
	 * @param mixed $id
	 * @return bool
	 */
	public static function is_gated_id( $id ) {
		return in_array( (int) $id, self::product_ids(), true );
	}

	/**
	 * Coming soon only applies to a product that is also gated.
	 *
	 * @param mixed $id
	 * @return bool
	 */
	public static function is_coming_soon_id( $id ) {
		$id = (int) $id;
		return self::is_gated_id( $id ) && in_array( $id, self::coming_soon_ids(), true );
	}

	/**
	 * @param int $product_id
	 * @param int $variation_id
	 * @return bool
	 */
	public static function is_gated_line( $product_id, $variation_id ) {
		return self::is_gated_id( $product_id ) || self::is_gated_id( $variation_id );
	}

	/**
	 * Empty when the value cannot be stored as a design id.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function normalize_design_id( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		if ( is_bool( $value ) || is_object( $value ) || null === $value ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 128 ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]+$/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * design_id on a cart line. Same places as the rear-end discount: the
	 * cart item key, then meta_data. A line the discount would count is not
	 * a bare line.
	 *
	 * @param mixed $item
	 * @return string
	 */
	public static function item_design_id( $item ) {
		if ( ! is_array( $item ) ) {
			return '';
		}
		if ( array_key_exists( 'design_id', $item ) ) {
			return self::normalize_design_id( $item['design_id'] );
		}
		if ( empty( $item['meta_data'] ) || ! is_array( $item['meta_data'] ) ) {
			return '';
		}
		foreach ( $item['meta_data'] as $meta ) {
			$key = null;
			$val = null;
			if ( is_array( $meta ) ) {
				$key = isset( $meta['key'] ) ? $meta['key'] : null;
				$val = array_key_exists( 'value', $meta ) ? $meta['value'] : null;
			} elseif ( is_object( $meta ) ) {
				$key = isset( $meta->key ) ? $meta->key : null;
				$val = isset( $meta->value ) ? $meta->value : null;
			}
			if ( 'design_id' === $key ) {
				return self::normalize_design_id( $val );
			}
		}
		return '';
	}

	/**
	 * A gated add is allowed when design_id is already on the cart item data
	 * or is present on the request (the classic form copies it on during
	 * woocommerce_add_cart_item_data, which runs after validation).
	 *
	 * @param int    $product_id
	 * @param int    $variation_id
	 * @param array  $cart_item_data
	 * @param mixed  $request_design_id
	 * @return bool
	 */
	public static function add_is_allowed( $product_id, $variation_id, array $cart_item_data, $request_design_id ) {
		if ( ! self::is_gated_line( $product_id, $variation_id ) ) {
			return true;
		}
		if ( '' !== self::item_design_id( $cart_item_data ) ) {
			return true;
		}
		return '' !== self::normalize_design_id( $request_design_id );
	}

	/**
	 * Copy a request design_id onto a gated line that does not have one yet.
	 *
	 * @param array $cart_item_data
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @param mixed $request_design_id
	 * @return array
	 */
	public static function stamp_design_id( array $cart_item_data, $product_id, $variation_id, $request_design_id ) {
		if ( ! self::is_gated_line( $product_id, $variation_id ) ) {
			return $cart_item_data;
		}
		if ( '' !== self::item_design_id( $cart_item_data ) ) {
			return $cart_item_data;
		}
		$design_id = self::normalize_design_id( $request_design_id );
		if ( '' === $design_id ) {
			return $cart_item_data;
		}
		$cart_item_data['design_id'] = $design_id;
		return $cart_item_data;
	}

	/**
	 * Cart keys for guarded products that have no design_id.
	 *
	 * @param array $cart
	 * @return string[]
	 */
	public static function bare_line_keys( array $cart ) {
		$keys = array();
		foreach ( $cart as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id   = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			if ( ! self::is_gated_line( $product_id, $variation_id ) ) {
				continue;
			}
			if ( '' !== self::item_design_id( $item ) ) {
				continue;
			}
			$keys[] = (string) $key;
		}
		return $keys;
	}

	/**
	 * Replacement for the add-to-cart control. Null leaves Woo's markup alone.
	 *
	 * @param mixed $product Product, variation, or id.
	 * @param bool  $wrap    Block-level wrapper for the single product summary.
	 * @return string|null
	 */
	public static function purchase_markup( $product, $wrap = false ) {
		$gated = array();
		foreach ( self::ids_for_product( $product ) as $id ) {
			if ( self::is_gated_id( $id ) ) {
				$gated[] = $id;
			}
		}
		if ( ! $gated ) {
			return null;
		}
		foreach ( $gated as $id ) {
			if ( self::is_coming_soon_id( $id ) ) {
				$inner = '<span class="button disabled creature-fd-only-soon" aria-disabled="true">Coming soon</span>';
				return $wrap ? '<div class="creature-fd-only">' . $inner . '</div>' : $inner;
			}
		}
		$url   = self::escape_url( self::designer_url() );
		$inner = '<a href="' . $url . '" class="button creature-fd-only-link">Design yours in Frame Designer</a>';
		return $wrap ? '<div class="creature-fd-only">' . $inner . '</div>' : $inner;
	}

	/**
	 * Notice text. The URL is the link destination and the visible href so a
	 * channel that strips tags still shows where to go.
	 *
	 * @return string
	 */
	public static function notice_html() {
		$url = self::escape_url( self::designer_url() );
		return self::NOTICE . ' <a href="' . $url . '">' . self::escape_html( self::designer_url() ) . '</a>';
	}

	/**
	 * @return string
	 */
	public static function notice_plain() {
		return self::NOTICE . ' ' . self::designer_url();
	}

	public static function on_single_add_to_cart() {
		$markup = self::purchase_markup( isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null, true );
		if ( null === $markup ) {
			if ( function_exists( 'woocommerce_template_single_add_to_cart' ) ) {
				woocommerce_template_single_add_to_cart();
			}
			return;
		}
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped URL and fixed strings.
	}

	/**
	 * Shop loop. Replaces the whole anchor, including ajax_add_to_cart.
	 *
	 * @param string $html
	 * @param mixed  $product
	 * @return string
	 */
	public static function on_loop_link( $html, $product ) {
		$markup = self::purchase_markup( $product, false );
		return null === $markup ? $html : $markup;
	}

	/**
	 * Block themes: single add-to-cart form and the product-collection button.
	 *
	 * @param string   $content
	 * @param array    $block
	 * @param WP_Block $instance
	 * @return string
	 */
	public static function on_render_block( $content, $block, $instance = null ) {
		$name = is_array( $block ) && isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( ! in_array( $name, self::cart_block_names(), true ) ) {
			return $content;
		}
		$id = 0;
		if ( is_object( $instance ) && isset( $instance->context['postId'] ) ) {
			$id = (int) $instance->context['postId'];
		}
		if ( ! $id && is_array( $block ) && isset( $block['attrs']['productId'] ) ) {
			$id = (int) $block['attrs']['productId'];
		}
		if ( ! $id && is_string( $content ) && preg_match( '/data-product_id=["\'](\d+)/', $content, $match ) ) {
			$id = (int) $match[1];
		}
		$markup = self::purchase_markup( $id, true );
		return null === $markup ? $content : $markup;
	}

	/**
	 * Classic ?add-to-cart=, product form, and AJAX call this before cart
	 * item data exists. The Store API calls it after woocommerce_add_cart_item_data
	 * and passes that array as the sixth argument.
	 *
	 * When the sixth argument is absent, preview the cart-item-data filter so
	 * a design_id added there still counts. The preview is not the cart write;
	 * the real add runs the same filter again.
	 *
	 * @param bool  $passed
	 * @param int   $product_id
	 * @param int   $quantity
	 * @param int   $variation_id
	 * @param array $variations
	 * @param array $cart_item_data
	 * @return bool
	 */
	public static function on_add_to_cart_validation( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		unset( $variations );
		if ( self::$previewing ) {
			return $passed;
		}
		if ( ! $passed ) {
			return $passed;
		}

		$product_id   = (int) $product_id;
		$variation_id = is_numeric( $variation_id ) ? (int) $variation_id : 0;
		$data         = is_array( $cart_item_data ) ? $cart_item_data : array();

		// Store API passes six arguments, already run through add_cart_item_data.
		// Classic ?add-to-cart= and AJAX pass two or three. Variable forms pass five.
		if ( func_num_args() < 6 ) {
			self::$previewing = true;
			try {
				$preview = apply_filters(
					'woocommerce_add_cart_item_data',
					$data,
					$product_id,
					$variation_id,
					(int) $quantity
				);
			} finally {
				self::$previewing = false;
			}
			if ( is_array( $preview ) ) {
				$data = $preview;
			}
		}

		if ( self::add_is_allowed( $product_id, $variation_id, $data, self::request_design_id() ) ) {
			return $passed;
		}

		self::add_notice();
		return false;
	}

	/**
	 * @param array $cart_item_data
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @param int   $quantity
	 * @return array
	 */
	public static function on_add_cart_item_data( $cart_item_data, $product_id, $variation_id = 0, $quantity = 1 ) {
		unset( $quantity );
		if ( ! is_array( $cart_item_data ) ) {
			$cart_item_data = array();
		}
		return self::stamp_design_id(
			$cart_item_data,
			(int) $product_id,
			is_numeric( $variation_id ) ? (int) $variation_id : 0,
			self::request_design_id()
		);
	}

	/**
	 * Store API action. Current Woo also runs woocommerce_add_to_cart_validation
	 * first and stops when that returns false, so this throws only when the
	 * request still has no design_id. Throw RouteException when the class
	 * exists so the API returns 400 with this message.
	 *
	 * @param object $product
	 * @param array  $request
	 * @return void
	 * @throws Exception When the part has no design_id.
	 */
	public static function on_store_api_validate( $product, $request ) {
		$product_id   = 0;
		$variation_id = 0;
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$is_variation = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' );
			if ( $is_variation ) {
				$variation_id = (int) $product->get_id();
				$product_id   = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			} else {
				$product_id = (int) $product->get_id();
			}
		}

		$data = array();
		if ( is_array( $request ) && isset( $request['cart_item_data'] ) && is_array( $request['cart_item_data'] ) ) {
			$data = $request['cart_item_data'];
		}

		$request_id = self::request_design_id();
		if ( is_array( $request ) && array_key_exists( 'design_id', $request ) ) {
			$from_body = self::normalize_design_id( $request['design_id'] );
			if ( '' !== $from_body ) {
				$request_id = $from_body;
			}
		}

		if ( self::add_is_allowed( $product_id, $variation_id, $data, $request_id ) ) {
			return;
		}

		self::throw_store_api( self::notice_plain() );
	}

	/**
	 * Drops guarded lines that were already in the basket with no design_id
	 * (old sessions, or a Store API add that skipped validation). One notice.
	 */
	public static function on_check_cart_items() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return;
		}
		$cart = $wc->cart;
		if ( ! method_exists( $cart, 'get_cart' ) || ! method_exists( $cart, 'remove_cart_item' ) ) {
			return;
		}
		$contents = $cart->get_cart();
		if ( ! is_array( $contents ) ) {
			return;
		}
		$keys = self::bare_line_keys( $contents );
		if ( ! $keys ) {
			return;
		}
		foreach ( $keys as $key ) {
			$cart->remove_cart_item( $key );
		}
		self::add_notice();
	}

	/**
	 * Cart checkout copies design_id onto the order line so the rear-end
	 * discount can see it after the basket becomes an order. REST order
	 * creation does not fire this hook; the tools-api writes the meta itself.
	 *
	 * @param object $item
	 * @param string $cart_item_key
	 * @param array  $values
	 * @param object $order
	 */
	public static function on_checkout_line_item( $item, $cart_item_key, $values, $order ) {
		unset( $cart_item_key, $order );
		if ( ! is_array( $values ) || ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}
		$product_id   = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
		$variation_id = isset( $values['variation_id'] ) ? (int) $values['variation_id'] : 0;
		if ( ! self::is_gated_line( $product_id, $variation_id ) ) {
			return;
		}
		$design_id = self::item_design_id( $values );
		if ( '' === $design_id ) {
			return;
		}
		$item->add_meta_data( 'design_id', $design_id, true );
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
	 * @param mixed $product
	 * @return int[]
	 */
	private static function ids_for_product( $product ) {
		if ( is_numeric( $product ) ) {
			return array( (int) $product );
		}
		$ids = array();
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$ids[] = (int) $product->get_id();
		}
		if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) ) {
			$parent = (int) $product->get_parent_id();
			if ( $parent > 0 ) {
				$ids[] = $parent;
			}
		}
		return $ids;
	}

	/**
	 * @return string[]
	 */
	private static function cart_block_names() {
		return array(
			'woocommerce/add-to-cart-form',
			'woocommerce/add-to-cart-with-options',
			'woocommerce/product-button',
		);
	}

	/**
	 * @return string
	 */
	private static function request_design_id() {
		if ( ! isset( $_REQUEST['design_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$raw = $_REQUEST['design_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( function_exists( 'wp_unslash' ) ) {
			$raw = wp_unslash( $raw );
		}
		return self::normalize_design_id( $raw );
	}

	private static function add_notice() {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::notice_html(), 'error' );
		}
	}

	/**
	 * @param string $message
	 * @return void
	 * @throws Exception
	 */
	private static function throw_store_api( $message ) {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'creature_fd_only', $message, 400 );
		}
		if ( class_exists( '\Automattic\WooCommerce\Blocks\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\Blocks\StoreApi\Exceptions\RouteException( 'creature_fd_only', $message, 400 );
		}
		throw new Exception( $message );
	}

	/**
	 * @param string $url
	 * @return string
	 */
	private static function escape_url( $url ) {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function escape_html( $text ) {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

add_action( 'plugins_loaded', array( 'Creature_Fd_Only_Purchase', 'boot' ), 20 );
