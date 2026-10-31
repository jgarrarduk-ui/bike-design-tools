<?php
/**
 * Plugin Name: Creature Cycles De-Reacher Demo Loan
 * Description: Postal demo loan for the De-Reacher. Consent, saved card, loan statuses, reminders, and charge-later on the WooCommerce Stripe Gateway. Test mode unless live charges are explicitly enabled.
 * Version: 1.2.1
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 * Requires Plugins: woocommerce
 * Text Domain: creature-demo-loan
 *
 * Install from Plugins → Add New → Upload the zip built by build-zip.sh.
 * WooCommerce and WooCommerce Stripe Gateway 11.x must already be active.
 * Copy optional-mu-loader/creature-demo-loan-loader.php into wp-content/mu-plugins/
 * only if the plugin should load without being activated in the plugins screen.
 *
 * Live charges stay off unless wp-config.php defines
 * CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES as boolean true before plugins load.
 * Customer email stays redirected unless CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL
 * is boolean true. Undefined and any other value mean off.
 *
 * Product ids, coupon product ids, and the test redirect address can be set
 * from the companion Demo Loan Config plugin. A wp-config constant for the
 * same value wins. That screen cannot turn live charges or real customer
 * email on. Those stay these two constants.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

if ( defined( 'CREATURE_DEMO_LOAN_LOADED' ) ) {
	return;
}

define( 'CREATURE_DEMO_LOAN_LOADED', true );
define( 'CREATURE_DEMO_LOAN_FILE', __FILE__ );
define( 'CREATURE_DEMO_LOAN_DIR', __DIR__ );
define( 'CREATURE_DEMO_LOAN_VERSION', '1.2.1' );

if ( ! defined( 'CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES' ) ) {
	define( 'CREATURE_DEMO_LOAN_ALLOW_LIVE_CHARGES', false );
}

if ( ! defined( 'CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL' ) ) {
	define( 'CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL', false );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once CREATURE_DEMO_LOAN_DIR . '/includes/class-creature-demo-loan-calendar.php';
require_once CREATURE_DEMO_LOAN_DIR . '/includes/class-creature-demo-loan-money.php';
require_once CREATURE_DEMO_LOAN_DIR . '/includes/class-creature-demo-loan-copy.php';
require_once CREATURE_DEMO_LOAN_DIR . '/includes/class-creature-demo-loan-mail.php';
require_once CREATURE_DEMO_LOAN_DIR . '/includes/class-creature-demo-loan-stripe.php';
require_once CREATURE_DEMO_LOAN_DIR . '/includes/class-creature-demo-loan.php';

add_action( 'plugins_loaded', array( 'Creature_Demo_Loan', 'boot' ), 20 );

/**
 * Drop the daily Action Scheduler job when the plugin is deactivated.
 * Orders and their meta stay in place.
 */
function creature_demo_loan_deactivate() {
	if ( class_exists( 'Creature_Demo_Loan' ) ) {
		Creature_Demo_Loan::unschedule_daily();
	}
}

if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook( CREATURE_DEMO_LOAN_FILE, 'creature_demo_loan_deactivate' );
}

/**
 * Drop charge-lock options. Orders and their meta stay in place.
 */
function creature_demo_loan_uninstall() {
	if ( class_exists( 'Creature_Demo_Loan' ) ) {
		Creature_Demo_Loan::delete_all_charge_locks();
	}
}

if ( function_exists( 'register_uninstall_hook' ) ) {
	register_uninstall_hook( CREATURE_DEMO_LOAN_FILE, 'creature_demo_loan_uninstall' );
}
