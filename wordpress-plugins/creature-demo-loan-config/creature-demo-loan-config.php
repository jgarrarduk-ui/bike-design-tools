<?php
/**
 * Plugin Name: Creature Cycles Demo Loan Config
 * Description: Staging settings for demo product ids, coupon product ids, and the test redirect address. Cannot enable live charges or real customer email.
 * Version: 1.1.0
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
 * WooCommerce → Demo loan can run the staging walkthrough in-process. That
 * runner refuses creaturecycles.co.uk and any subdomain of it before the
 * script is included. The charge box stays off unless it is ticked.
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
define( 'CREATURE_DEMO_LOAN_CONFIG_VERSION', '1.1.0' );

/**
 * Option-backed filters for the demo loan plugin.
 */
final class Creature_Demo_Loan_Config {

	const OPTION = 'creature_demo_loan_config';

	private static $walkthrough_notice = '';

	private static $walkthrough_included = false;

	private static $walkthrough_running = false;

	private static $walkthrough_finished = false;

	private static $walkthrough_ob_level = 0;

	private static $charge_env_pushed = false;

	private static $charge_env_previous = false;

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_walkthrough' ) );
		add_action( 'admin_post_creature_demo_loan_walkthrough', array( __CLASS__, 'handle_walkthrough_admin_post' ) );
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
		self::load_walkthrough_notice();
		echo '<div class="wrap">';
		echo '<h1>Demo loan</h1>';
		if ( '' !== self::$walkthrough_notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html( self::$walkthrough_notice ) . '</p></div>';
		}
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
		echo '</form>';
		echo '<h2>Staging walkthrough</h2>';
		echo '<p>Runs the staging walkthrough in this request. Stripe is called only if the box below is ticked, and only for this run. The live shop cannot run it.</p>';
		echo '<form method="post">';
		if ( function_exists( 'wp_nonce_field' ) ) {
			wp_nonce_field( 'creature_demo_loan_walkthrough' );
		}
		echo '<input type="hidden" name="creature_demo_loan_walkthrough_run" value="1" />';
		echo '<p><label><input name="creature_demo_loan_walkthrough_charge" type="checkbox" value="1" /> Charge Stripe where a gate is clear</label></p>';
		echo '<p><button type="submit" class="button">Run staging walkthrough</button></p>';
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

	public static function handle_walkthrough() {
		$posted  = ! empty( $_POST['creature_demo_loan_walkthrough_run'] );
		$fetched = ! empty( $_GET['creature_demo_loan_walkthrough_run'] );
		if ( ! $posted && ! $fetched ) {
			return;
		}
		self::run_walkthrough();
	}

	public static function handle_walkthrough_admin_post() {
		self::run_walkthrough();
	}

	/**
	 * @return string
	 */
	public static function walkthrough_notice() {
		return self::$walkthrough_notice;
	}

	/**
	 * @return bool
	 */
	public static function walkthrough_included() {
		return self::$walkthrough_included;
	}

	/**
	 * creaturecycles.co.uk, www, and any other subdomain.
	 *
	 * @param mixed $url
	 * @return bool
	 */
	public static function walkthrough_host_is_live( $url ) {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parsed = wp_parse_url( (string) $url, PHP_URL_HOST );
		} else {
			$parsed = parse_url( (string) $url, PHP_URL_HOST );
		}
		$host = is_string( $parsed ) ? strtolower( rtrim( $parsed, '.' ) ) : '';
		if ( 'creaturecycles.co.uk' === $host ) {
			return true;
		}
		$suffix = '.creaturecycles.co.uk';
		$length = strlen( $suffix );
		return strlen( $host ) > $length && $suffix === substr( $host, -1 * $length );
	}

	/**
	 * Captured walkthrough output, escaped as one blob.
	 *
	 * @param string $blob
	 */
	public static function finish_walkthrough_output() {
		if ( self::$walkthrough_finished ) {
			return;
		}
		if ( ! self::$walkthrough_running && ! self::$charge_env_pushed ) {
			return;
		}
		self::$walkthrough_finished = true;
		self::$walkthrough_running  = false;
		$blob  = '';
		$floor = (int) self::$walkthrough_ob_level;
		while ( ob_get_level() > $floor ) {
			$piece = ob_get_clean();
			if ( is_string( $piece ) ) {
				$blob = $piece . $blob;
			}
		}
		self::pop_charge_env();
		if ( ! self::$walkthrough_included ) {
			return;
		}
		$safe = function_exists( 'esc_html' ) ? esc_html( $blob ) : htmlspecialchars( $blob, ENT_QUOTES, 'UTF-8' );
		echo '<pre>' . $safe . '</pre>';
	}

	private static function run_walkthrough() {
		self::$walkthrough_included = false;
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! self::walkthrough_nonce_ok() ) {
			return;
		}
		if ( self::walkthrough_home_is_live() ) {
			self::present_walkthrough_refusal( 'Refusing the live shop. The walkthrough does not run on creaturecycles.co.uk or any subdomain of it.' );
			return;
		}
		$missing = self::walkthrough_guard_errors();
		if ( $missing ) {
			self::present_walkthrough_refusal( implode( ' ', $missing ) );
			return;
		}
		$creature_demo_walkthrough_script = self::walkthrough_script_path();
		if ( ! is_readable( $creature_demo_walkthrough_script ) ) {
			self::present_walkthrough_refusal( 'The staging walkthrough script is not installed.' );
			return;
		}
		self::push_charge_env( self::walkthrough_charge_requested() );
		if ( ! defined( 'CREATURE_DEMO_LOAN_WALKTHROUGH_RUNNER' ) ) {
			define( 'CREATURE_DEMO_LOAN_WALKTHROUGH_RUNNER', true );
		}
		self::$walkthrough_included = true;
		self::$walkthrough_finished = false;
		self::$walkthrough_running  = true;
		self::$walkthrough_ob_level = ob_get_level();
		ob_start();
		register_shutdown_function( array( __CLASS__, 'finish_walkthrough_output' ) );
		require $creature_demo_walkthrough_script;
		self::finish_walkthrough_output();
	}

	/**
	 * @return bool
	 */
	private static function walkthrough_home_is_live() {
		$url = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		return self::walkthrough_host_is_live( $url );
	}

	/**
	 * @return string[]
	 */
	private static function walkthrough_guard_errors() {
		$errors = array();
		if ( ! class_exists( 'Creature_Demo_Loan' ) || ! class_exists( 'Creature_Demo_Loan_Mail' ) ) {
			$errors[] = 'The De-Reacher demo loan plugin must be active before the walkthrough can run.';
			return $errors;
		}
		$mode = Creature_Demo_Loan::stripe_mode_flags();
		if ( empty( $mode['test_mode'] ) ) {
			$errors[] = 'Refusing the walkthrough because Stripe is not in test mode.';
		}
		if ( empty( $mode['test_key'] ) ) {
			$errors[] = 'Refusing the walkthrough because the Stripe secret key does not start with sk_test_.';
		}
		if ( '' === Creature_Demo_Loan_Mail::redirect_address() ) {
			$errors[] = 'Refusing the walkthrough because the demo-loan redirect address is empty.';
		}
		return $errors;
	}

	/**
	 * @return bool
	 */
	private static function walkthrough_nonce_ok() {
		if ( function_exists( 'check_admin_referer' ) ) {
			return (bool) check_admin_referer( 'creature_demo_loan_walkthrough' );
		}
		$nonce = '';
		if ( isset( $_POST['_wpnonce'] ) ) {
			$nonce = wp_unslash( $_POST['_wpnonce'] );
		} elseif ( isset( $_GET['_wpnonce'] ) ) {
			$nonce = wp_unslash( $_GET['_wpnonce'] );
		}
		return function_exists( 'wp_verify_nonce' ) && (bool) wp_verify_nonce( $nonce, 'creature_demo_loan_walkthrough' );
	}

	/**
	 * @return bool
	 */
	private static function walkthrough_charge_requested() {
		return ! empty( $_POST['creature_demo_loan_walkthrough_run'] )
			&& ! empty( $_POST['creature_demo_loan_walkthrough_charge'] );
	}

	/**
	 * @return string
	 */
	private static function walkthrough_script_path() {
		$path = dirname( __FILE__ ) . '/../creature-demo-loan/bin/staging-walkthrough.php';
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'creature_demo_loan_config_walkthrough_script', $path );
			if ( is_string( $filtered ) && '' !== $filtered ) {
				$path = $filtered;
			}
		}
		return $path;
	}

	/**
	 * @param string $message
	 */
	private static function present_walkthrough_refusal( $message ) {
		self::$walkthrough_notice = $message;
		$action = isset( $_REQUEST['action'] ) ? (string) wp_unslash( $_REQUEST['action'] ) : '';
		if ( 'creature_demo_loan_walkthrough' !== $action ) {
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'creature_demo_loan_config_walkthrough_notice', $message, 60 );
		}
		if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'admin_url' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=creature-demo-loan-config' ) );
			exit;
		}
	}

	private static function load_walkthrough_notice() {
		if ( '' !== self::$walkthrough_notice || ! function_exists( 'get_transient' ) ) {
			return;
		}
		$stored = get_transient( 'creature_demo_loan_config_walkthrough_notice' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return;
		}
		self::$walkthrough_notice = $stored;
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'creature_demo_loan_config_walkthrough_notice' );
		}
	}

	/**
	 * @param bool $enabled
	 */
	private static function push_charge_env( $enabled ) {
		$current = getenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' );
		self::$charge_env_previous = ( false === $current ) ? false : (string) $current;
		self::$charge_env_pushed   = true;
		$value                     = $enabled ? '1' : '0';
		putenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE=' . $value );
		$_ENV['CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE']    = $value;
		$_SERVER['CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE'] = $value;
	}

	private static function pop_charge_env() {
		if ( ! self::$charge_env_pushed ) {
			return;
		}
		self::$charge_env_pushed = false;
		if ( false === self::$charge_env_previous || '' === self::$charge_env_previous ) {
			putenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' );
			unset( $_ENV['CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE'], $_SERVER['CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE'] );
			return;
		}
		putenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE=' . self::$charge_env_previous );
		$_ENV['CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE']    = self::$charge_env_previous;
		$_SERVER['CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE'] = self::$charge_env_previous;
	}
}

add_action( 'plugins_loaded', array( 'Creature_Demo_Loan_Config', 'boot' ) );
