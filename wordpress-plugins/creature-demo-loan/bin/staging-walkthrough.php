<?php
/**
 * Staging walkthrough for the De-Reacher demo loan.
 *
 *   wp eval-file wordpress-plugins/creature-demo-loan/bin/staging-walkthrough.php
 *
 * Creates test orders and moves them by passing dates into the plugin. It does
 * not change the server clock. It does not call Stripe unless the environment
 * variable CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE is exactly 1, and even then
 * only while the gateway is in test mode.
 *
 * Refuses the live host creaturecycles.co.uk. Billing and shop mail for this
 * request are forced to the demo-loan redirect address. That address
 * (demo-loan-test@creaturecycles.co.uk, or whatever
 * creature_demo_loan_redirect_email returns) has to be a real inbox before
 * this script is run.
 *
 * @package Creature_Demo_Loan
 */

if ( PHP_SAPI !== 'cli' ) {
	return;
}

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "Run this inside WP-CLI on staging:\n";
	echo "  wp eval-file wordpress-plugins/creature-demo-loan/bin/staging-walkthrough.php\n";
	echo "It creates test orders. It does not charge Stripe unless CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE=1.\n";
	exit( 0 );
}

if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'Creature_Demo_Loan' ) ) {
	echo "WooCommerce and the De-Reacher demo loan plugin must be loaded.\n";
	exit( 1 );
}

/**
 * @param string $url
 * @return string
 */
function creature_demo_walkthrough_host( $url ) {
	$host = wp_parse_url( (string) $url, PHP_URL_HOST );
	return is_string( $host ) ? strtolower( $host ) : '';
}

$site = function_exists( 'home_url' ) ? home_url( '/' ) : '';
$host = creature_demo_walkthrough_host( $site );
if ( 'creaturecycles.co.uk' === $host || 'www.creaturecycles.co.uk' === $host ) {
	echo "Refusing the live shop host. Run this on staging.\n";
	exit( 1 );
}

$mode = Creature_Demo_Loan::stripe_mode_flags();
if ( empty( $mode['test_mode'] ) || empty( $mode['test_key'] ) ) {
	echo "Refusing because Stripe is not in test mode with a test secret key.\n";
	exit( 1 );
}

$redirect = Creature_Demo_Loan_Mail::redirect_address();
if ( '' === $redirect ) {
	echo "Refusing because the demo-loan redirect address is empty.\n";
	exit( 1 );
}

add_filter(
	'creature_demo_loan_admin_email',
	static function () use ( $redirect ) {
		return $redirect;
	}
);

$charge = getenv( 'CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE' );
$may_charge = ( '1' === $charge );

/**
 * @param string $label
 * @param mixed  $value
 */
function creature_demo_walkthrough_line( $label, $value ) {
	if ( is_bool( $value ) ) {
		$value = $value ? 'yes' : 'no';
	} elseif ( is_array( $value ) ) {
		$value = wp_json_encode( $value );
	}
	echo $label . ': ' . (string) $value . "\n";
}

/**
 * @param string $email_suffix
 * @return WC_Order|object
 */
function creature_demo_walkthrough_order( $email_suffix ) {
	$redirect = Creature_Demo_Loan_Mail::redirect_address();
	$order    = wc_create_order(
		array(
			'status' => 'processing',
		)
	);
	if ( ! is_object( $order ) ) {
		echo "wc_create_order did not return an order.\n";
		exit( 1 );
	}
	if ( method_exists( $order, 'set_billing_email' ) ) {
		$order->set_billing_email( $redirect );
	}
	if ( method_exists( $order, 'set_payment_method' ) ) {
		$order->set_payment_method( 'stripe' );
	}
	$order->update_meta_data( '_creature_demo_walkthrough', (string) $email_suffix );
	$order->update_meta_data( '_stripe_customer_id', 'cus_walkthrough' );
	$order->update_meta_data( '_stripe_source_id', 'pm_walkthrough' );
	if ( method_exists( $order, 'set_total' ) ) {
		$order->set_total( '25.00' );
	}
	$order->save();
	$started = Creature_Demo_Loan::start_loan( $order, '2026-03-02', '2026-03-04' );
	creature_demo_walkthrough_line( 'order', $order->get_id() );
	creature_demo_walkthrough_line( 'scenario', $email_suffix );
	creature_demo_walkthrough_line( 'due', isset( $started['due'] ) ? $started['due'] : '' );
	creature_demo_walkthrough_line( 'status', $order->get_status() );
	return $order;
}

echo "De-Reacher demo loan walkthrough\n";
creature_demo_walkthrough_line( 'site', $site );
creature_demo_walkthrough_line( 'mail', $redirect );
creature_demo_walkthrough_line( 'stripe charge enabled', $may_charge );
echo "Dates are passed in. The shop clock is not changed.\n\n";

echo "== Buy a new one ==\n";
$buy = creature_demo_walkthrough_order( 'buy' );
$choice = Creature_Demo_Loan::choose( $buy, 'buy', '2026-03-10' );
creature_demo_walkthrough_line( 'choice', isset( $choice['choice'] ) ? $choice['choice'] : $choice['reason'] );
creature_demo_walkthrough_line( 'status', $buy->get_status() );
$scan = Creature_Demo_Loan::record_return_scan( $buy, '2026-03-24', '2026-03-24' );
creature_demo_walkthrough_line( 'scan late fee pence', isset( $scan['late_fee'] ) ? $scan['late_fee'] : 0 );
$done = Creature_Demo_Loan::complete_inspection( $buy, '2026-03-24' );
$code = ( is_array( $done ) && isset( $done['coupon']['code'] ) ) ? $done['coupon']['code'] : '';
creature_demo_walkthrough_line( 'coupon', '' !== $code ? $code : '(none)' );
creature_demo_walkthrough_line( 'status', $buy->get_status() );
echo "\n";

echo "== Return, don't buy ==\n";
$back = creature_demo_walkthrough_order( 'return' );
Creature_Demo_Loan::choose( $back, 'return', '2026-03-10' );
creature_demo_walkthrough_line( 'status', $back->get_status() );
creature_demo_walkthrough_line( 'choice', $back->get_meta( '_creature_demo_choice' ) );
echo "Shop mail for the Royal Mail QR was redirected to the test inbox.\n\n";

echo "== Keep the demo unit ==\n";
$keep = creature_demo_walkthrough_order( 'keep' );
$kept = Creature_Demo_Loan::choose( $keep, 'keep', '2026-03-10' );
creature_demo_walkthrough_line( 'keep pence', isset( $kept['amount'] ) ? $kept['amount'] : 0 );
creature_demo_walkthrough_line( 'charge order', isset( $kept['order_id'] ) ? $kept['order_id'] : 0 );
$gate = array( 'code' => 'no-charge-order' );
if ( ! empty( $kept['order_id'] ) ) {
	$charge_order = wc_get_order( $kept['order_id'] );
	$gate         = Creature_Demo_Loan::evaluate_charge( $keep, $charge_order, '2026-03-13' );
}
$gate_code = isset( $gate['code'] ) ? (string) $gate['code'] : '';
creature_demo_walkthrough_line( 'gate on 2026-03-13', '' === $gate_code ? 'clear' : $gate_code );
if ( $may_charge && '' === $gate['code'] ) {
	$result = Creature_Demo_Loan::charge_saved_card( $keep, '2026-03-13' );
	creature_demo_walkthrough_line( 'charge', isset( $result['code'] ) ? $result['code'] : '' );
} else {
	echo "Saved card not charged. The keep link is the pending order's pay URL.\n";
}
creature_demo_walkthrough_line( 'status', $keep->get_status() );
echo "\n";

echo "== Disputed late fee ==\n";
$late = creature_demo_walkthrough_order( 'late' );
Creature_Demo_Loan::record_return_scan( $late, '2026-03-30', '2026-03-30' );
$late_charge_id = (int) $late->get_meta( '_creature_demo_late_fee_order_id' );
$late_charge    = $late_charge_id ? wc_get_order( $late_charge_id ) : null;
creature_demo_walkthrough_line( 'late fee order', $late_charge_id );
if ( $late_charge ) {
	Creature_Demo_Loan::mark_disputed( $late_charge, '2026-03-30' );
	$blocked = Creature_Demo_Loan::evaluate_charge( $late, $late_charge, '2026-04-02' );
	creature_demo_walkthrough_line( 'gate while disputed', $blocked['code'] );
	$decision = Creature_Demo_Loan::record_dispute_decision( $late_charge, 'upheld', '2026-04-02', null );
	creature_demo_walkthrough_line( 'decision within 14 days', ! empty( $decision['timely'] ) );
	$open = Creature_Demo_Loan::evaluate_charge( $late, $late_charge, '2026-04-02' );
	creature_demo_walkthrough_line( 'gate after uphold', '' === $open['code'] ? 'clear' : $open['code'] );
	if ( $may_charge && '' === $open['code'] ) {
		$result = Creature_Demo_Loan::charge_saved_card( $late, '2026-04-02' );
		creature_demo_walkthrough_line( 'charge', isset( $result['code'] ) ? $result['code'] : '' );
	} else {
		echo "Saved card not charged.\n";
	}
}
echo "\n";

echo "== Deemed purchase ==\n";
$deemed = creature_demo_walkthrough_order( 'deemed' );
$raised = Creature_Demo_Loan::maybe_raise_deemed( $deemed, '2026-04-07' );
creature_demo_walkthrough_line( 'deemed pence', isset( $raised['amount'] ) ? $raised['amount'] : 0 );
creature_demo_walkthrough_line( 'status', $deemed->get_status() );
creature_demo_walkthrough_line( 'warning day', '2026-04-03' );
echo "Day 10 after the due date is 2026-04-03. The daily job sends the warning. This script does not send it twice if the job already did.\n";
echo "Saved card not charged for the deemed purchase.\n";

if ( $may_charge ) {
	echo "\nDone. Stripe was called only where a gate was clear, and only because CREATURE_DEMO_LOAN_WALKTHROUGH_CHARGE=1.\n";
} else {
	echo "\nDone. Orders are test loans on this site. Stripe was not called.\n";
}
exit( 0 );
