<?php
/**
 * Plugin Name: Creature Cycles De-Reacher Demo Loan Loader
 * Description: Optional must-use loader. Loads the De-Reacher demo loan plugin from wp-content/plugins/creature-demo-loan/ when this file sits in wp-content/mu-plugins/. If the plugin is activated normally as well, it loads only once.
 * Version: 1.0.0
 * Author: Creature Cycles
 * License: GPL-2.0-or-later
 *
 * Copy this file alone into wp-content/mu-plugins/ (not into a subfolder).
 * Leave the plugin folder in wp-content/plugins/creature-demo-loan/.
 * The same file is stored in the plugin at optional-mu-loader/creature-demo-loan-loader.php.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'CREATURE_DEMO_LOAN_LOADED' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
	return;
}

$creature_demo_loan_file = WP_PLUGIN_DIR . '/creature-demo-loan/creature-demo-loan.php';
if ( is_readable( $creature_demo_loan_file ) ) {
	require_once $creature_demo_loan_file;
}
