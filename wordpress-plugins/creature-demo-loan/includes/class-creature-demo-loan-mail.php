<?php
/**
 * Customer and shop email for the demo loan.
 *
 * Customer mail is redirected unless CREATURE_DEMO_LOAN_ALLOW_CUSTOMER_EMAIL
 * is exactly true. The default redirect is demo-loan-test@creaturecycles.co.uk.
 * CREATURE_DEMO_LOAN_REDIRECT_EMAIL wins when it is defined. Otherwise the
 * creature_demo_loan_redirect_email filter can change the address. The subject
 * is prefixed [TEST]. Shop alerts are not redirected.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Sends placeholder copy and keeps an in-memory log for tests.
 */
final class Creature_Demo_Loan_Mail {

	/**
	 * Messages handed to wp_mail on this request.
	 *
	 * @var array<int, array<string, string>>
	 */
	public static $sent = array();

	public static function reset() {
		self::$sent = array();
	}

	/**
	 * @param bool   $allow_real
	 * @param string $billing
	 * @param string $redirect
	 * @return array{to: string, prefix: bool, suppressed: bool}
	 */
	public static function route( $allow_real, $billing, $redirect ) {
		if ( $allow_real ) {
			return array(
				'to'         => (string) $billing,
				'prefix'     => false,
				'suppressed' => '' === (string) $billing,
			);
		}
		$redirect = (string) $redirect;
		return array(
			'to'         => $redirect,
			'prefix'     => true,
			'suppressed' => '' === $redirect,
		);
	}

	/**
	 * @return string
	 */
	public static function redirect_address() {
		if ( defined( 'CREATURE_DEMO_LOAN_REDIRECT_EMAIL' ) ) {
			$address = CREATURE_DEMO_LOAN_REDIRECT_EMAIL;
		} else {
			$address = 'demo-loan-test@creaturecycles.co.uk';
			if ( function_exists( 'apply_filters' ) ) {
				$address = apply_filters( 'creature_demo_loan_redirect_email', $address );
			}
		}
		if ( ! is_string( $address ) || ! preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $address ) ) {
			return '';
		}
		return $address;
	}

	/**
	 * @return string
	 */
	public static function admin_address() {
		$address = 'info@creaturecycles.co.uk';
		if ( function_exists( 'apply_filters' ) ) {
			$address = apply_filters( 'creature_demo_loan_admin_email', $address );
		}
		if ( ! is_string( $address ) || ! preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $address ) ) {
			return '';
		}
		return $address;
	}

	/**
	 * @param object               $order
	 * @param string               $slot
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	public static function send_customer( $order, $slot, $args = array() ) {
		$billing = '';
		if ( is_object( $order ) && method_exists( $order, 'get_billing_email' ) ) {
			$billing = (string) $order->get_billing_email();
		}
		$allow = Creature_Demo_Loan::customer_email_allowed();
		$route = self::route( $allow, $billing, self::redirect_address() );
		return self::deliver( 'customer', $route, $order, $slot, $args );
	}

	/**
	 * Shop alert. Not prefixed and not sent to the rider.
	 *
	 * @param object               $order
	 * @param string               $slot
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	public static function send_admin( $order, $slot, $args = array() ) {
		$route = array(
			'to'         => self::admin_address(),
			'prefix'     => false,
			'suppressed' => '' === self::admin_address(),
		);
		return self::deliver( 'admin', $route, $order, $slot, $args );
	}

	/**
	 * @param string               $kind
	 * @param array<string, mixed> $route
	 * @param object               $order
	 * @param string               $slot
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	private static function deliver( $kind, $route, $order, $slot, $args ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) && ! isset( $args['order_id'] ) ) {
			$args['order_id'] = (string) $order->get_id();
		}
		$subject = Creature_Demo_Loan_Copy::subject( $slot, $args );
		$body    = Creature_Demo_Loan_Copy::text( $slot, $args );
		if ( ! empty( $route['prefix'] ) ) {
			$subject = '[TEST] ' . $subject;
		}
		$subject = Creature_Demo_Loan::redact( $subject );
		$body    = Creature_Demo_Loan::redact( $body );
		$row     = array(
			'kind'    => (string) $kind,
			'slot'    => (string) $slot,
			'to'      => (string) $route['to'],
			'subject' => $subject,
			'body'    => $body,
		);
		if ( empty( $route['suppressed'] ) && function_exists( 'wp_mail' ) ) {
			wp_mail( $row['to'], $row['subject'], $row['body'] );
		}
		self::$sent[] = $row;
		return $row;
	}
}
