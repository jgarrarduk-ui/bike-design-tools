<?php
/**
 * Settings screen for the demo loan companion plugin.
 *
 *   php wordpress-plugins/mu-plugins/tests/creature-demo-loan-config-test.php
 *
 * No WooCommerce install. The screen can set product ids, coupon product ids,
 * and the test redirect address. It cannot turn on live charges or real
 * customer email. A constant wins over the saved option.
 */

if ( PHP_SAPI !== 'cli' || ( isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) !== realpath( __FILE__ ) ) ) {
	return;
}

$GLOBALS['creature_demo_filters'] = array();
$GLOBALS['creature_demo_hooks']   = array();
$GLOBALS['creature_demo_options'] = array();
$GLOBALS['creature_demo_caps']    = array();
$GLOBALS['creature_demo_menus']   = array();

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

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['creature_demo_options'] ) ? $GLOBALS['creature_demo_options'][ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['creature_demo_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return (string) $nonce === (string) $action;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {
		unset( $referer );
		$html = '<input type="hidden" name="' . $name . '" value="' . $action . '" />';
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['creature_demo_caps'][ $cap ] );
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent, $title, $menu, $cap, $slug, $callback ) {
		$GLOBALS['creature_demo_menus'][] = array( $parent, $title, $menu, $cap, $slug, $callback );
		return $slug;
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		unset( $path );
		return isset( $GLOBALS['creature_demo_home_url'] ) ? (string) $GLOBALS['creature_demo_home_url'] : 'https://staging.example.test/';
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		$nonce = '';
		if ( isset( $_POST[ $query_arg ] ) ) {
			$nonce = $_POST[ $query_arg ];
		} elseif ( isset( $_GET[ $query_arg ] ) ) {
			$nonce = $_GET[ $query_arg ];
		} elseif ( isset( $_REQUEST[ $query_arg ] ) ) {
			$nonce = $_REQUEST[ $query_arg ];
		}
		return wp_verify_nonce( $nonce, $action );
	}
}

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

require dirname( __DIR__, 2 ) . '/creature-demo-loan/creature-demo-loan.php';
require dirname( __DIR__, 2 ) . '/creature-demo-loan-config/creature-demo-loan-config.php';

do_action( 'plugins_loaded' );

$failures = 0;
$checks   = 0;

function creature_demo_config_expect( $condition, $message ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		return;
	}
	$failures++;
	fwrite( STDERR, "FAIL {$message}\n" );
}

$option = Creature_Demo_Loan_Config::OPTION;
$_POST  = array(
	'creature_demo_loan_config_save' => '1',
	'_wpnonce'                       => 'creature_demo_loan_config',
	'product_ids'                    => '9001, abc, 9002, -3, 0',
	'coupon_product_ids'             => '6688, no',
	'bundle_product_id'              => ' 9100 ',
	'redirect_email'                 => 'staging-redirect@creaturecycles.co.uk',
	'allow_live_charges'             => '1',
	'allow_customer_email'           => '1',
);
$GLOBALS['creature_demo_caps']['manage_woocommerce'] = false;
Creature_Demo_Loan_Config::handle_post();
creature_demo_config_expect( ! isset( $GLOBALS['creature_demo_options'][ $option ] ), 'a user without manage_woocommerce cannot save' );

$GLOBALS['creature_demo_caps']['manage_woocommerce'] = true;
$_POST['_wpnonce'] = 'wrong';
Creature_Demo_Loan_Config::handle_post();
creature_demo_config_expect( ! isset( $GLOBALS['creature_demo_options'][ $option ] ), 'a bad nonce cannot save' );

$_POST['_wpnonce'] = 'creature_demo_loan_config';
do_action( 'admin_init' );
$stored = get_option( $option );
creature_demo_config_expect(
	array( 'product_ids', 'coupon_product_ids', 'bundle_product_id', 'redirect_email' ) === array_keys( $stored ),
	'the option keeps only the four settings'
);
creature_demo_config_expect( '9100' === $stored['bundle_product_id'], 'the bundle product id is stored as one positive integer' );
creature_demo_config_expect( '' === Creature_Demo_Loan_Config::sanitize_settings( array( 'bundle_product_id' => '12,13' ) )['bundle_product_id'], 'a list is not a bundle product id' );
creature_demo_config_expect( '' === Creature_Demo_Loan_Config::sanitize_settings( array( 'bundle_product_id' => '0' ) )['bundle_product_id'], 'a zero bundle product id is discarded' );
creature_demo_config_expect( '' === Creature_Demo_Loan_Config::sanitize_settings( array( 'bundle_product_id' => 'abc' ) )['bundle_product_id'], 'a non-numeric bundle product id is discarded' );
creature_demo_config_expect( 9100 === Creature_Demo_Loan::bundle_product_id(), 'the bundle-id filter reads the option' );
creature_demo_config_expect( 9100 === Creature_Demo_Loan_Config::filter_bundle_id( 8425 ), 'the stored bundle id replaces the default' );
creature_demo_config_expect( '9001,9002' === $stored['product_ids'], 'product ids are sanitised to positive integers' );
creature_demo_config_expect( '6688' === $stored['coupon_product_ids'], 'coupon ids drop anything that is not an id' );
creature_demo_config_expect( 'staging-redirect@creaturecycles.co.uk' === $stored['redirect_email'], 'a valid redirect address is stored' );
creature_demo_config_expect( ! array_key_exists( 'allow_live_charges', $stored ), 'the option cannot store a live-charge switch' );
creature_demo_config_expect( ! array_key_exists( 'allow_customer_email', $stored ), 'the option cannot store a real-email switch' );
creature_demo_config_expect( false === Creature_Demo_Loan::live_charges_allowed(), 'saving the screen leaves live charges off' );
creature_demo_config_expect( false === Creature_Demo_Loan::customer_email_allowed(), 'saving the screen leaves customer email redirected' );

creature_demo_config_expect( array( 9001, 9002 ) === Creature_Demo_Loan::demo_product_ids(), 'the product-id filter reads the option' );
creature_demo_config_expect( array( 6688 ) === Creature_Demo_Loan::coupon_product_ids(), 'the coupon-id filter reads the option' );
creature_demo_config_expect( 'staging-redirect@creaturecycles.co.uk' === Creature_Demo_Loan_Mail::redirect_address(), 'the redirect filter reads the option' );

$_POST['redirect_email'] = 'not an email';
$_POST['product_ids']    = '9001,9002';
Creature_Demo_Loan_Config::handle_post();
creature_demo_config_expect( '' === get_option( $option )['redirect_email'], 'an invalid redirect address is discarded' );
creature_demo_config_expect( 'demo-loan-test@creaturecycles.co.uk' === Creature_Demo_Loan_Mail::redirect_address(), 'an empty redirect option keeps the default inbox' );
$_POST['redirect_email'] = 'staging-redirect@creaturecycles.co.uk';
Creature_Demo_Loan_Config::handle_post();

do_action( 'admin_menu' );
creature_demo_config_expect( 1 === count( $GLOBALS['creature_demo_menus'] ), 'the settings page is registered once' );
$menu = $GLOBALS['creature_demo_menus'][0];
creature_demo_config_expect( 'woocommerce' === $menu[0] && 'manage_woocommerce' === $menu[3] && 'creature-demo-loan-config' === $menu[4], 'the page sits under WooCommerce and requires manage_woocommerce' );

$GLOBALS['creature_demo_caps']['manage_woocommerce'] = false;
ob_start();
Creature_Demo_Loan_Config::render_page();
$denied = ob_get_clean();
creature_demo_config_expect( '' === $denied, 'the settings page is blank without manage_woocommerce' );

$GLOBALS['creature_demo_caps']['manage_woocommerce'] = true;
ob_start();
Creature_Demo_Loan_Config::render_page();
$html = ob_get_clean();
creature_demo_config_expect( false !== strpos( $html, 'name="product_ids"' ), 'the page edits product ids' );
creature_demo_config_expect( false !== strpos( $html, 'name="coupon_product_ids"' ), 'the page edits coupon ids' );
creature_demo_config_expect( false !== strpos( $html, 'name="bundle_product_id"' ), 'the page edits the bundle product id' );
creature_demo_config_expect( false !== strpos( $html, 'name="redirect_email"' ), 'the page edits the redirect address' );
creature_demo_config_expect( false !== strpos( $html, 'value="creature_demo_loan_config"' ), 'the page includes the nonce' );
creature_demo_config_expect( false === strpos( $html, 'allow_live' ), 'the page has no live-charge control' );
creature_demo_config_expect( false === strpos( $html, 'customer_email' ), 'the page has no real-email control' );
creature_demo_config_expect( false === strpos( $html, 'ALLOW_LIVE' ), 'the page does not expose the live-charge constant' );
creature_demo_config_expect( false !== strpos( $html, 'Staging walkthrough' ), 'the page has a staging walkthrough section' );
creature_demo_config_expect( false !== strpos( $html, 'name="creature_demo_loan_walkthrough_run"' ), 'the page posts a walkthrough run' );
creature_demo_config_expect( 1 === preg_match( '/<input name="creature_demo_loan_walkthrough_charge" type="checkbox" value="1" \/>/', $html ), 'the charge tick box is on the page' );
creature_demo_config_expect( 0 === preg_match( '/creature_demo_loan_walkthrough_charge"[^>]*checked/', $html ), 'the charge tick box is unticked' );
creature_demo_config_expect( false !== strpos( $html, 'value="creature_demo_loan_walkthrough"' ), 'the walkthrough form has its own nonce' );
$_POST = array();

function creature_demo_config_blank_redirect( $address ) {
	if ( ! empty( $GLOBALS['creature_demo_blank_redirect'] ) ) {
		return '';
	}
	return $address;
}

function creature_demo_config_walkthrough_script( $path ) {
	if ( ! empty( $GLOBALS['creature_demo_walkthrough_script'] ) ) {
		return $GLOBALS['creature_demo_walkthrough_script'];
	}
	return $path;
}

add_filter( 'creature_demo_loan_redirect_email', 'creature_demo_config_blank_redirect', 99 );
add_filter( 'creature_demo_loan_config_walkthrough_script', 'creature_demo_config_walkthrough_script' );

$GLOBALS['creature_demo_home_url'] = 'https://staging.example.test/';
$_POST = array(
	'creature_demo_loan_walkthrough_run' => '1',
	'_wpnonce'                           => 'creature_demo_loan_walkthrough',
);
$_GET  = array();
$GLOBALS['creature_demo_caps']['manage_woocommerce'] = false;
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'a user without manage_woocommerce cannot run the walkthrough' );
creature_demo_config_expect( ! defined( 'CREATURE_DEMO_LOAN_WALKTHROUGH_RUNNER' ), 'a refused walkthrough does not open the runner' );

$GLOBALS['creature_demo_caps']['manage_woocommerce'] = true;
$_POST['_wpnonce'] = 'wrong';
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'a bad nonce cannot run the walkthrough' );
Creature_Demo_Loan_Config::handle_walkthrough_admin_post();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'admin-post with a bad nonce cannot run the walkthrough' );

$_POST['_wpnonce'] = 'creature_demo_loan_walkthrough';
update_option(
	'woocommerce_stripe_settings',
	array(
		'testmode'        => 'yes',
		'test_secret_key' => 'sk_test_' . 'placeholder',
	)
);
foreach ( array(
	'https://creaturecycles.co.uk/',
	'https://www.creaturecycles.co.uk/shop',
	'https://CREATURECYCLES.CO.UK/',
) as $live_url ) {
	$GLOBALS['creature_demo_home_url'] = $live_url;
	Creature_Demo_Loan_Config::handle_walkthrough();
	creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'the live host is refused before the walkthrough is included: ' . $live_url );
	creature_demo_config_expect( false !== strpos( Creature_Demo_Loan_Config::walkthrough_notice(), 'creaturecycles.co.uk' ), 'the live-host refusal names the shop: ' . $live_url );
}
$GLOBALS['creature_demo_home_url'] = 'https://example.com/';
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'a host that is not staging or local is refused before the walkthrough is included' );
creature_demo_config_expect( false !== strpos( Creature_Demo_Loan_Config::walkthrough_notice(), 'staging or a local site' ), 'a non-staging host is told to use staging or local' );
creature_demo_config_expect( ! defined( 'CREATURE_DEMO_LOAN_WALKTHROUGH_RUNNER' ), 'a live-host refusal does not define the runner' );
creature_demo_config_expect( Creature_Demo_Loan_Config::walkthrough_host_is_live( 'https://user:pass@www.creaturecycles.co.uk:443/path' ), 'www with a port is still the live shop' );
creature_demo_config_expect( Creature_Demo_Loan_Config::walkthrough_host_is_live( 'https://a.b.creaturecycles.co.uk/apps' ), 'a production subdomain is not a staging host' );
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_host_is_live( 'https://staging.creaturecycles.co.uk/' ), 'staging.creaturecycles.co.uk can run the walkthrough' );
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_host_is_live( 'https://creaturecycles.co.uk.example.test/' ), 'a local .test host is not the live shop' );
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_host_is_live( 'https://staging.example.test/' ), 'staging.example.test can run the walkthrough' );
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_host_is_live( 'http://localhost/' ), 'localhost can run the walkthrough' );
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_host_is_live( 'http://127.0.0.1:8080/' ), '127.0.0.1 can run the walkthrough' );

$GLOBALS['creature_demo_home_url'] = 'https://staging.example.test/';
update_option(
	'woocommerce_stripe_settings',
	array(
		'testmode'   => 'no',
		'secret_key' => 'sk_test_' . 'placeholder',
	)
);
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'Stripe test mode is required before the walkthrough is included' );
creature_demo_config_expect( false !== strpos( Creature_Demo_Loan_Config::walkthrough_notice(), 'not in test mode' ), 'the test-mode refusal is an admin notice' );

update_option(
	'woocommerce_stripe_settings',
	array(
		'testmode'        => 'yes',
		'test_secret_key' => 'sk_live_' . 'placeholder',
	)
);
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'a key that does not start with sk_test_ is refused' );
creature_demo_config_expect( false !== strpos( Creature_Demo_Loan_Config::walkthrough_notice(), 'sk_test_' ), 'the key refusal names sk_test_' );

update_option(
	'woocommerce_stripe_settings',
	array(
		'testmode'        => 'yes',
		'test_secret_key' => 'sk_test_' . 'placeholder',
	)
);
$GLOBALS['creature_demo_blank_redirect'] = true;
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'an empty redirect address is refused' );
creature_demo_config_expect( false !== strpos( Creature_Demo_Loan_Config::walkthrough_notice(), 'redirect address is empty' ), 'the redirect refusal is an admin notice' );
$GLOBALS['creature_demo_blank_redirect'] = false;
ob_start();
Creature_Demo_Loan_Config::render_page();
$refused_html = ob_get_clean();
creature_demo_config_expect( false !== strpos( $refused_html, 'redirect address is empty' ), 'the settings page shows the walkthrough refusal' );

$GLOBALS['creature_demo_walkthrough_script'] = '/tmp/creature-demo-walkthrough-missing.php';
Creature_Demo_Loan_Config::handle_walkthrough();
creature_demo_config_expect( ! Creature_Demo_Loan_Config::walkthrough_included(), 'a missing walkthrough file is not included' );
creature_demo_config_expect( false !== strpos( Creature_Demo_Loan_Config::walkthrough_notice(), 'not installed' ), 'a missing walkthrough file is a plain admin notice' );
creature_demo_config_expect( ! defined( 'CREATURE_DEMO_LOAN_WALKTHROUGH_RUNNER' ), 'a missing file does not open the runner' );

$charge_before = getenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' );
$stub          = tempnam( sys_get_temp_dir(), 'cdw' );
file_put_contents(
	$stub,
	"<?php\necho 'charge=' . getenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' ) . \"\\n\";\necho \"<tag>\\nline<two>\";\nreturn 0;\n"
);
$GLOBALS['creature_demo_walkthrough_script'] = $stub;
$_POST['creature_demo_loan_walkthrough_charge'] = '';
Creature_Demo_Loan_Config::handle_walkthrough();
ob_start();
Creature_Demo_Loan_Config::render_page();
$unticked = ob_get_clean();
creature_demo_config_expect( Creature_Demo_Loan_Config::walkthrough_included(), 'a staging request with the guards clear includes the walkthrough' );
creature_demo_config_expect( false !== strpos( $unticked, 'The staging walkthrough finished.' ), 'a finished walkthrough stays on the settings page' );
creature_demo_config_expect( false !== strpos( $unticked, 'Run staging walkthrough' ), 'a finished walkthrough keeps the page controls' );
creature_demo_config_expect( false !== strpos( $unticked, '<pre>' ) && false !== strpos( $unticked, 'charge=0' ), 'an unticked box does not enable the charge' );
creature_demo_config_expect( false !== strpos( $unticked, "&lt;tag&gt;\nline&lt;two&gt;" ), 'the captured output is escaped as one blob' );
creature_demo_config_expect( false === strpos( $unticked, '<tag>' ), 'the captured output is not written raw' );
creature_demo_config_expect( $charge_before === getenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' ), 'an unticked run does not leave the charge variable set' );

$_POST['creature_demo_loan_walkthrough_charge'] = '1';
Creature_Demo_Loan_Config::handle_walkthrough();
ob_start();
Creature_Demo_Loan_Config::render_page();
$ticked = ob_get_clean();
creature_demo_config_expect( false !== strpos( $ticked, 'charge=1' ), 'a ticked box enables the charge for that run' );
creature_demo_config_expect( $charge_before === getenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' ), 'a ticked run does not leave the charge variable set' );

$refuse = tempnam( sys_get_temp_dir(), 'cdw' );
file_put_contents( $refuse, "<?php\necho \"Refusing because the demo-loan redirect address is empty.\\n<nope>\";\nreturn 1;\n" );
$GLOBALS['creature_demo_walkthrough_script'] = $refuse;
$_POST['creature_demo_loan_walkthrough_charge'] = '';
Creature_Demo_Loan_Config::handle_walkthrough();
ob_start();
Creature_Demo_Loan_Config::render_page();
$refused_run = ob_get_clean();
creature_demo_config_expect( false !== strpos( $refused_run, 'The staging walkthrough refused to run.' ), 'a script refusal stays on the settings page' );
creature_demo_config_expect( false !== strpos( $refused_run, 'Run staging walkthrough' ), 'a script refusal keeps the page controls' );
creature_demo_config_expect( false !== strpos( $refused_run, '&lt;nope&gt;' ), 'a script refusal escapes its output' );
unlink( $refuse );

$_POST = array();
$_GET  = array(
	'creature_demo_loan_walkthrough_run'     => '1',
	'creature_demo_loan_walkthrough_charge'  => '1',
	'_wpnonce'                               => 'creature_demo_loan_walkthrough',
);
$GLOBALS['creature_demo_walkthrough_script'] = $stub;
Creature_Demo_Loan_Config::handle_walkthrough();
ob_start();
Creature_Demo_Loan_Config::render_page();
$fetched = ob_get_clean();
creature_demo_config_expect( false !== strpos( $fetched, 'charge=0' ), 'a direct URL cannot tick the charge box' );
$_GET  = array();
$_POST = array();
$GLOBALS['creature_demo_walkthrough_script'] = '';
unlink( $stub );
unset( $GLOBALS['creature_demo_options']['woocommerce_stripe_settings'] );

$config_src = file_get_contents( dirname( __DIR__, 2 ) . '/creature-demo-loan-config/creature-demo-loan-config.php' );
$host_pos    = strpos( $config_src, 'walkthrough_home_is_live' );
$require_pos = strpos( $config_src, 'require $creature_demo_walkthrough_script' );
creature_demo_config_expect( false !== $host_pos && false !== $require_pos && $host_pos < $require_pos, 'the live-host check is before the walkthrough is included' );
creature_demo_config_expect( '1.2.2' === CREATURE_DEMO_LOAN_CONFIG_VERSION, 'the config plugin version constant is 1.2.2' );
creature_demo_config_expect( false !== strpos( $config_src, 'Version: 1.2.2' ), 'the plugin header version is 1.2.2' );
creature_demo_config_expect( false !== strpos( $config_src, 'WP_PLUGIN_DIR' ), 'the walkthrough path is resolved from the demo loan plugin directory' );
$guard_pos = strpos( $config_src, 'walkthrough_guard_errors()' );
creature_demo_config_expect( false !== $guard_pos && $guard_pos < $require_pos, 'an inactive demo loan plugin is refused before the script is included' );
creature_demo_config_expect( false !== strpos( $config_src, "class_exists( 'Creature_Demo_Loan' )" ), 'the walkthrough guard checks that the demo loan plugin is active' );
$walk_src = file_get_contents( dirname( __DIR__, 2 ) . '/creature-demo-loan/bin/staging-walkthrough.php' );
creature_demo_config_expect( false !== strpos( $walk_src, 'CREATURE_DEMO_LOAN_WALKTHROUGH_RUNNER' ), 'the walkthrough script accepts the admin runner' );
creature_demo_config_expect( false !== strpos( $walk_src, "PHP_SAPI !== 'cli'" ), 'the walkthrough script keeps its CLI guard' );

define( 'CREATURE_DEMO_LOAN_PRODUCT_IDS', '111,222' );
define( 'CREATURE_DEMO_LOAN_COUPON_PRODUCT_IDS', '333' );
define( 'CREATURE_DEMO_LOAN_BUNDLE_PRODUCT_ID', 7777 );
define( 'CREATURE_DEMO_LOAN_REDIRECT_EMAIL', 'constant@creaturecycles.co.uk' );
creature_demo_config_expect( 7777 === Creature_Demo_Loan::bundle_product_id(), 'the bundle-id constant wins over the option' );
creature_demo_config_expect( 5 === Creature_Demo_Loan_Config::filter_bundle_id( 5 ), 'the bundle filter does not replace a constant value' );
creature_demo_config_expect( array( 111, 222 ) === Creature_Demo_Loan::demo_product_ids(), 'the product-id constant wins over the option' );
creature_demo_config_expect( array( 333 ) === Creature_Demo_Loan::coupon_product_ids(), 'the coupon-id constant wins over the option' );
creature_demo_config_expect( 'constant@creaturecycles.co.uk' === Creature_Demo_Loan_Mail::redirect_address(), 'the redirect constant wins over the option' );
creature_demo_config_expect( array( 5 ) === Creature_Demo_Loan_Config::filter_product_ids( array( 5 ) ), 'the product filter does not replace a constant value' );
creature_demo_config_expect( array( 6 ) === Creature_Demo_Loan_Config::filter_coupon_ids( array( 6 ) ), 'the coupon filter does not replace a constant value' );
creature_demo_config_expect( 'kept@creaturecycles.co.uk' === Creature_Demo_Loan_Config::filter_redirect_email( 'kept@creaturecycles.co.uk' ), 'the redirect filter does not replace a constant value' );
creature_demo_config_expect( false === Creature_Demo_Loan::live_charges_allowed(), 'the config plugin still cannot allow live charges' );
creature_demo_config_expect( false === Creature_Demo_Loan::customer_email_allowed(), 'the config plugin still cannot allow real customer email' );

$passed = $checks - $failures;
echo "{$passed} passed, {$failures} failed\n";
if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} failed, {$checks} checks\n" );
	exit( 1 );
}

echo "{$checks} checks passed\n";
exit( 0 );
