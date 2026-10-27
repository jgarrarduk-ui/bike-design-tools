<?php
/**
 * Off-session PaymentIntents through the WooCommerce Stripe Gateway API helper.
 *
 * Uses WC_Stripe_API::request when that class exists (woocommerce-gateway-stripe 11.x).
 * The idempotency key is a header via woocommerce_stripe_request_headers.
 * Card numbers, secret keys, and raw error bodies are not returned.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Stripe gateway calls.
 */
final class Creature_Demo_Loan_Stripe {

	/**
	 * Last idempotency key sent. Not a secret. Useful to tests and order notes.
	 *
	 * @var string
	 */
	public static $last_idempotency_key = '';

	/**
	 * @param int                  $amount_pence
	 * @param string               $customer
	 * @param string               $payment_method
	 * @param string               $idempotency_key
	 * @param array<string, string> $metadata
	 * @return array{ok: bool, code: string, id: string}
	 */
	public static function off_session_intent( $amount_pence, $customer, $payment_method, $idempotency_key, $metadata ) {
		self::$last_idempotency_key = (string) $idempotency_key;
		if ( ! class_exists( 'WC_Stripe_API' ) || ! is_callable( array( 'WC_Stripe_API', 'request' ) ) ) {
			return array(
				'ok'   => false,
				'code' => 'stripe_api_missing',
				'id'   => '',
			);
		}

		$body = array(
			'amount'         => (int) $amount_pence,
			'currency'       => 'gbp',
			'customer'       => (string) $customer,
			'payment_method' => (string) $payment_method,
			'off_session'    => 'true',
			'confirm'        => 'true',
			'metadata'       => self::safe_metadata( $metadata ),
		);

		$GLOBALS['creature_demo_loan_stripe_idempotency'] = (string) $idempotency_key;
		$filter = static function ( $headers ) use ( $idempotency_key ) {
			if ( ! is_array( $headers ) ) {
				$headers = array();
			}
			$headers['Idempotency-Key'] = (string) $idempotency_key;
			return $headers;
		};
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'woocommerce_stripe_request_headers', $filter );
		}
		$response = WC_Stripe_API::request( $body, 'payment_intents', 'POST' );
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'woocommerce_stripe_request_headers', $filter );
		}
		unset( $GLOBALS['creature_demo_loan_stripe_idempotency'] );

		return self::interpret( $response );
	}

	/**
	 * @param string $payment_intent_id
	 * @param int    $amount_pence
	 * @param string $idempotency_key
	 * @return array{ok: bool, code: string, id: string}
	 */
	public static function refund( $payment_intent_id, $amount_pence, $idempotency_key ) {
		self::$last_idempotency_key = (string) $idempotency_key;
		if ( ! self::safe_id( $payment_intent_id ) ) {
			return array(
				'ok'   => false,
				'code' => 'stripe_error',
				'id'   => '',
			);
		}
		if ( ! class_exists( 'WC_Stripe_API' ) || ! is_callable( array( 'WC_Stripe_API', 'request' ) ) ) {
			return array(
				'ok'   => false,
				'code' => 'stripe_api_missing',
				'id'   => '',
			);
		}
		$body   = array(
			'payment_intent' => self::safe_id( $payment_intent_id ),
			'amount'         => (int) $amount_pence,
		);
		$filter = static function ( $headers ) use ( $idempotency_key ) {
			if ( ! is_array( $headers ) ) {
				$headers = array();
			}
			$headers['Idempotency-Key'] = (string) $idempotency_key;
			return $headers;
		};
		$GLOBALS['creature_demo_loan_stripe_idempotency'] = (string) $idempotency_key;
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'woocommerce_stripe_request_headers', $filter );
		}
		$response = WC_Stripe_API::request( $body, 'refunds', 'POST' );
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'woocommerce_stripe_request_headers', $filter );
		}
		unset( $GLOBALS['creature_demo_loan_stripe_idempotency'] );
		$read = self::interpret( $response );
		if ( $read['ok'] ) {
			$read['code'] = 'refunded';
		}
		return $read;
	}

	/**
	 * @param mixed $response
	 * @return array{ok: bool, code: string, id: string}
	 */
	public static function interpret( $response ) {
		if ( is_object( $response ) && is_a( $response, 'WP_Error' ) ) {
			$code = (string) $response->get_error_code();
			$msg  = (string) $response->get_error_message();
			if ( false !== strpos( $code . ' ' . $msg, 'authentication_required' ) ) {
				return array(
					'ok'   => false,
					'code' => 'authentication_required',
					'id'   => '',
				);
			}
			return array(
				'ok'   => false,
				'code' => 'stripe_error',
				'id'   => '',
			);
		}

		$error_code = '';
		$decline    = '';
		$status     = '';
		$id         = '';
		if ( is_object( $response ) && isset( $response->error ) ) {
			$err        = $response->error;
			$error_code = is_object( $err ) && isset( $err->code ) ? (string) $err->code : '';
			$decline    = is_object( $err ) && isset( $err->decline_code ) ? (string) $err->decline_code : '';
		} elseif ( is_array( $response ) && isset( $response['error'] ) ) {
			$err        = $response['error'];
			$error_code = is_array( $err ) && isset( $err['code'] ) ? (string) $err['code'] : '';
			$decline    = is_array( $err ) && isset( $err['decline_code'] ) ? (string) $err['decline_code'] : '';
		}
		if ( '' !== $error_code || '' !== $decline ) {
			if ( 'authentication_required' === $error_code || 'authentication_required' === $decline ) {
				return array(
					'ok'   => false,
					'code' => 'authentication_required',
					'id'   => '',
				);
			}
			return array(
				'ok'   => false,
				'code' => 'stripe_error',
				'id'   => '',
			);
		}

		if ( is_object( $response ) ) {
			$status = isset( $response->status ) ? (string) $response->status : '';
			$id     = isset( $response->id ) ? (string) $response->id : '';
		} elseif ( is_array( $response ) ) {
			$status = isset( $response['status'] ) ? (string) $response['status'] : '';
			$id     = isset( $response['id'] ) ? (string) $response['id'] : '';
		}

		if ( 'requires_action' === $status || 'requires_source_action' === $status ) {
			return array(
				'ok'   => false,
				'code' => 'authentication_required',
				'id'   => self::safe_id( $id ),
			);
		}
		if ( 'succeeded' === $status || 'processing' === $status ) {
			return array(
				'ok'   => true,
				'code' => 'succeeded',
				'id'   => self::safe_id( $id ),
			);
		}
		return array(
			'ok'   => false,
			'code' => 'stripe_error',
			'id'   => '',
		);
	}

	/**
	 * @param string $id
	 * @return string
	 */
	public static function safe_id( $id ) {
		if ( ! is_string( $id ) ) {
			return '';
		}
		if ( 0 === strpos( $id, 'pi_' ) || 0 === strpos( $id, 're_' ) ) {
			return $id;
		}
		return '';
	}

	/**
	 * @param mixed $metadata
	 * @return array<string, string>
	 */
	private static function safe_metadata( $metadata ) {
		$out = array();
		if ( ! is_array( $metadata ) ) {
			return $out;
		}
		foreach ( $metadata as $key => $value ) {
			$key = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = Creature_Demo_Loan::redact( (string) $value );
		}
		return $out;
	}
}
