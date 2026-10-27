<?php
/**
 * Plugin Name: Creature Cycles Demo Loan Config
 * Description: Staging settings for demo product ids, coupon product ids, and the test redirect address. Cannot enable live charges or real customer email.
 * Version: 1.0.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 * Requires Plugins: woocommerce
 * Text Domain: creature-demo-loan-config
 *
 * Install from Plugins → Add New → Upload the zip built by build-zip.sh.
 * WooCommerce must already be active. The De-Reacher demo loan plugin reads
 * the filters this screen sets.
 *
 * Live charges stay off unless wp-config.php defines
 * CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES as boolean true.
 * Customer email stays redirected unless CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL
 * is boolean true. This screen has no control for either constant.
 * CREATURE_DEMO_LOAN_PRODUCT_IDS, CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS, and
 * CREATURE_DEMO_LOAN_REDIRECT_EMAIL win when they are defined.
 *
 * @package Creature_Demo_Loan_Config
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

if ( defined( 'CREATURE_DEMO_LOAN_CONFIG_LOADED' ) ) {
	return;
}

define( 'CREATURE_DEMO_LOAN_CONFIG_LOADED', true );

/**
 * Option-backed filters for the demo loan plugin.
 */
final class Creature_Demo_Loan_Config {

	const OPTION = 'creature_demo_loan_config';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_filter( 'creature_demo_loan_product_ids', array( __CLASS__, 'filter_product_ids' ) );
		add_filter( 'creature_demo_loan_coupon_product_ids', array( __CLASS__, 'filter_coupon_ids' ) );
		add_filter( 'creature_demo_loan_redirect_email', array( __CLASS__, 'filter_redirect_email' ) );
	}

	public static function register_menu() {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			'Demo loan',
			'Demo loan',
			'manage_woocommerce',
			'creature-demo-loan-config',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post() {
		if ( empty( $_POST['creature_demo_loan_config_save'] ) ) {
			return;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
		if ( ! function_exists( 'wp_verify_nonce' ) || ! wp_verify_nonce( $nonce, 'creature_demo_loan_config' ) ) {
			return;
		}
		$clean = self::sanitize_settings( wp_unslash( $_POST ) );
		update_option( self::OPTION, $clean );
	}

	/**
	 * @param mixed $input
	 * @return array{product_ids: string, coupon_product_ids: string, redirect_email: string}
	 */
	public static function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		return array(
			'product_ids'         => self::sanitize_id_list( isset( $input['product_ids'] ) ? $input['product_ids'] : '' ),
			'coupon_product_ids'  => self::sanitize_id_list( isset( $input['coupon_product_ids'] ) ? $input['coupon_product_ids'] : '' ),
			'redirect_email'      => self::sanitize_redirect( isset( $input['redirect_email'] ) ? $input['redirect_email'] : '' ),
		);
	}

	/**
	 * @param mixed $ids
	 * @return mixed
	 */
	public static function filter_product_ids( $ids ) {
		if ( defined( 'CREATURE_DEMO_LOAN_PRODUCT_IDS' ) ) {
			return $ids;
		}
		$stored = self::ids_from_option( 'product_ids' );
		return $stored ? $stored : $ids;
	}

	/**
	 * @param mixed $ids
	 * @return mixed
	 */
	public static function filter_coupon_ids( $ids ) {
		if ( defined( 'CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS' ) ) {
			return $ids;
		}
		$stored = self::ids_from_option( 'coupon_product_ids' );
		return $stored ? $stored : $ids;
	}

	/**
	 * @param mixed $address
	 * @return mixed
	 */
	public static function filter_redirect_email( $address ) {
		if ( defined( 'CREATURE_DEMO_LOAN_REDIRECT_EMAIL' ) ) {
			return $address;
		}
		$stored = self::setting( 'redirect_email' );
		if ( '' !== $stored && self::valid_email( $stored ) ) {
			return $stored;
		}
		return $address;
	}

	public static function render_page() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = self::get_settings();
		echo '<div class="wrap">';
		echo '<h1>Demo loan</h1>';
		echo '<p>Live charges and real customer email stay off unless the wp-config constants are true. This screen cannot change them.</p>';
		echo '<form method="post">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'creature_demo_loan_config' );
		}
		echo '<input type="hidden" name="creature_demo_loan_config_save" value="1" />';
		echo '<table class="form-table"><tbody>';
		self::render_text_row( 'product_ids', 'Product IDs', $settings['product_ids'] );
		self::render_text_row( 'coupon_product_ids', 'Coupon product IDs', $settings['coupon_product_ids'] );
		self::render_text_row( 'redirect_email', 'Test redirect address', $settings['redirect_email'] );
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">Save</button></p>';
		echo '</form></div>';
	}

	/**
	 * @return array{product_ids: string, coupon_product_ids: string, redirect_email: string}
	 */
	public static function get_settings() {
		$stored = array();
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( self::OPTION, array() );
			if ( is_array( $raw ) ) {
				$stored = $raw;
			}
		}
		return self::sanitize_settings( $stored );
	}

	/**
	 * @param string $name
	 * @param string $label
	 * @param string $value
	 */
	private static function render_text_row( $name, $label, $value ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><input name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" type="text" class="regular-text" value="' . esc_attr( $value ) . '" /></td></tr>';
	}

	/**
	 * @param mixed $raw
	 * @return string
	 */
	private static function sanitize_id_list( $raw ) {
		if ( is_array( $raw ) ) {
			$raw = implode( ',', $raw );
		}
		$parts = preg_split( '/[\s,]+/', trim( (string) $raw ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$ids = array();
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/^[0-9]+$/', $part ) ) {
				continue;
			}
			$id = (int) $part;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return implode( ',', array_values( array_unique( $ids ) ) );
	}

	/**
	 * @param mixed $raw
	 * @return string
	 */
	private static function sanitize_redirect( $raw ) {
		$email = trim( (string) $raw );
		if ( function_exists( 'sanitize_email' ) ) {
			$email = sanitize_email( $email );
		}
		return self::valid_email( $email ) ? $email : '';
	}

	/**
	 * @param string $key
	 * @return int[]
	 */
	private static function ids_from_option( $key ) {
		$list = self::setting( $key );
		if ( '' === $list ) {
			return array();
		}
		$ids = array();
		foreach ( explode( ',', $list ) as $part ) {
			$id = (int) $part;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private static function setting( $key ) {
		$settings = self::get_settings();
		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}

	/**
	 * @param string $email
	 * @return bool
	 */
	private static function valid_email( $email ) {
		return is_string( $email ) && 1 === preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email );
	}
}

add_action( 'plugins_loaded', array( 'Creature_Demo_Loan_Config', 'boot' ) );
