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

	/** Every customer email carries this Reply-To so a reply reaches the shop. */
	const REPLY_TO = 'info@creaturecycles.co.uk';

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
		// A test-flagged order (or a charge order of a test-flagged loan) always goes to the redirect.
		if ( Creature_Demo_Loan::is_test_or_test_child( $order ) ) {
			$allow = false;
		}
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
		// A slot with no wording yet is never sent as a blank email. The row is
		// still recorded and the send hook still fires, so the event is not lost.
		// This is decided before the [TEST] prefix, which would make it look filled.
		$empty = '' === trim( $subject ) && '' === trim( $body );
		$heading = self::heading( Creature_Demo_Loan::redact( $subject ) );
		if ( ! empty( $route['prefix'] ) ) {
			$subject = '[TEST] ' . $subject;
		}
		$subject = Creature_Demo_Loan::redact( $subject );
		$body    = Creature_Demo_Loan::redact( $body );
		$html    = self::text_to_html( $body );
		$plain   = $body;
		// Every rider email ends with the sign-in link (never the shop's own alerts,
		// and never on a slot with no wording, which is not sent). The row's 'body'
		// stays the copy as written; 'plain' is the plain text that is sent.
		if ( 'customer' === $kind && ! $empty ) {
			$plain .= "\n\n" . self::signin_text();
			$html  .= self::signin_html();
		}
		$headers = 'customer' === $kind ? self::customer_headers() : self::admin_headers();
		$row     = array(
			'kind'    => (string) $kind,
			'slot'    => (string) $slot,
			'to'      => (string) $route['to'],
			'subject' => $subject,
			'body'    => $body,
			'plain'   => $plain,
			'headers' => $headers,
			'empty'   => $empty,
			'heading' => $heading,
			'mailer'  => '',
			'html'    => '',
		);
		if ( empty( $route['suppressed'] ) && ! $empty ) {
			$result              = self::dispatch( $row['to'], $row['subject'], $plain, $html, $heading, $headers );
			$row['mailer']       = $result['mailer'];
			$row['html']         = $result['html'];
			$row['sent_headers'] = $result['headers'];
		}
		self::$sent[] = $row;
		if ( function_exists( 'do_action' ) ) {
			/**
			 * Fires once for each email the plugin sends, one hook per slot, for
			 * example creature_demo_loan_mail_dates_on_delivery.
			 *
			 * @param array<string, mixed> $row   What was sent (or would have been).
			 * @param object               $order The order the email is about.
			 * @param array<string, mixed> $args  The merge values.
			 */
			do_action( 'creature_demo_loan_mail_' . $slot, $row, $order, $args );
		}
		return $row;
	}

	/** Label of the sign-in button / link in every rider-facing email. */
	const SIGNIN_LABEL = 'Sign in to manage your demo loan';

	/** Text under the sign-in link. "Reset it here" is a link to the lost-password page. */
	const SIGNIN_NOTE_BEFORE = 'Your account was created at checkout. Sign in with the email you booked with. Forgot your password? ';

	/** The words of the lost-password link. */
	const SIGNIN_RESET_LABEL = 'Reset it here';

	/**
	 * The My account Demo loan tab. Raw URL (escape at output).
	 *
	 * @return string
	 */
	public static function account_url() {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( Creature_Demo_Loan::ACCOUNT_ENDPOINT );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		$path = '/my-account/' . Creature_Demo_Loan::ACCOUNT_ENDPOINT . '/';
		return function_exists( 'home_url' ) ? (string) home_url( $path ) : $path;
	}

	/**
	 * The lost-password page. Raw URL (escape at output).
	 *
	 * @return string
	 */
	public static function lost_password_url() {
		if ( function_exists( 'wp_lostpassword_url' ) ) {
			$url = wp_lostpassword_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( 'lost-password' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return function_exists( 'home_url' ) ? (string) home_url( '/my-account/lost-password/' ) : '/my-account/lost-password/';
	}

	/**
	 * The sign-in block as plain text, with full URLs.
	 *
	 * @return string
	 */
	public static function signin_text() {
		return self::SIGNIN_LABEL . ': ' . self::account_url() . "\n\n" . self::SIGNIN_NOTE_BEFORE . self::SIGNIN_RESET_LABEL . ': ' . self::lost_password_url();
	}

	/**
	 * The sign-in block as HTML: a button-style link, then the note with the
	 * lost-password link. URLs are escaped here, once.
	 *
	 * @return string
	 */
	public static function signin_html() {
		$style = 'display:inline-block;padding:10px 18px;background-color:#222222;color:#ffffff;text-decoration:none;font-weight:bold;';
		return '<p><a href="' . self::esc_url_attr( self::account_url() ) . '" style="' . $style . '">' . self::esc_text( self::SIGNIN_LABEL ) . '</a></p>'
			. '<p>' . self::esc_text( self::SIGNIN_NOTE_BEFORE ) . '<a href="' . self::esc_url_attr( self::lost_password_url() ) . '">' . self::esc_text( self::SIGNIN_RESET_LABEL ) . '</a>.</p>';
	}

	/**
	 * Plain text to HTML for the WooCommerce template. Everything is escaped
	 * (placeholder values and customer-supplied text included); blank lines make
	 * paragraphs, single newlines become <br>, and a bare http(s) URL becomes a
	 * link whose href is escaped once and whose text is the URL. No raw newline is
	 * left in the output, because the WooCommerce wrapper runs wpautop() over it.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function text_to_html( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$out   = '';
		$paras = preg_split( '/\n{2,}/', trim( $text ) );
		foreach ( (array) $paras as $para ) {
			if ( '' === trim( $para ) ) {
				continue;
			}
			$lines = array_map( array( __CLASS__, 'linkify_line' ), explode( "\n", $para ) );
			$out  .= '<p>' . implode( '<br>', $lines ) . '</p>';
		}
		return $out;
	}

	/**
	 * @param string $line
	 * @return string
	 */
	private static function linkify_line( $line ) {
		// A full http(s) URL, the bare user-guide address, or the shop's info@ address.
		// HTML only: the plain-text body is never changed.
		$parts = preg_split( '~(https?://[^\s<>"\']+|(?<![\w./@-])creaturecycles\.co\.uk/de-reacher-user-guide/?|(?<![\w.@-])info@creaturecycles\.co\.uk(?![\w-]|\.\w))~iu', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return self::esc_text( $line );
		}
		$html = '';
		foreach ( $parts as $i => $part ) {
			if ( 1 !== $i % 2 ) {
				$html .= self::esc_text( $part );
				continue;
			}
			$trail = '';
			if ( preg_match( '~^https?://~i', $part ) ) {
				if ( preg_match( '~[.,;:!?)\]]+$~', $part, $m ) ) {
					$trail = $m[0];
					$part  = substr( $part, 0, -strlen( $trail ) );
				}
				$target = $part;
			} elseif ( false !== strpos( $part, '@' ) ) {
				$target = 'mailto:' . $part;
			} else {
				$target = 'https://' . $part;
			}
			// The href is escaped once, here; the visible text is the words as written.
			$href  = 'mailto:' === substr( $target, 0, 7 ) ? self::esc_attr_mailto( $target ) : self::esc_url_attr( $target );
			$html .= ( '' === $href ? self::esc_text( $part ) : '<a href="' . $href . '">' . self::esc_text( $part ) . '</a>' ) . self::esc_text( $trail );
		}
		return $html;
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private static function esc_text( $text ) {
		if ( function_exists( 'esc_html' ) ) {
			return esc_html( $text );
		}
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * A mailto: href. esc_url() allows the mailto protocol; the fallback escapes
	 * for an attribute.
	 *
	 * @param string $url
	 * @return string
	 */
	private static function esc_attr_mailto( $url ) {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url, array( 'mailto' ) );
		}
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * @param string $url
	 * @return string
	 */
	private static function esc_url_attr( $url ) {
		if ( function_exists( 'esc_url' ) ) {
			return esc_url( $url );
		}
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Short heading for the WooCommerce template: the subject without the
	 * "De-Reacher demo:" prefix. Called before the [TEST] prefix is added.
	 *
	 * @param string $subject
	 * @return string
	 */
	private static function heading( $subject ) {
		$heading = trim( (string) preg_replace( '/^\s*De-Reacher demo\s*:\s*/iu', '', (string) $subject ) );
		if ( '' === $heading ) {
			$heading = trim( (string) $subject );
		}
		return '' === $heading ? 'De-Reacher demo' : $heading;
	}

	/**
	 * The WooCommerce mailer, or null when it is not available.
	 *
	 * @return object|null
	 */
	private static function wc_mailer() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! method_exists( $wc, 'mailer' ) ) {
			return null;
		}
		$mailer = $wc->mailer();
		return is_object( $mailer ) && method_exists( $mailer, 'wrap_message' ) && method_exists( $mailer, 'send' ) ? $mailer : null;
	}

	/**
	 * The final send, and only the final send. Through WooCommerce's mailer
	 * (wrap_message for the shop template, then send) when it is there; otherwise
	 * plain text through wp_mail as before. The recipient, subject and headers are
	 * decided by the caller. No wp_mail_content_type filter is added here:
	 * WC_Email::send adds and removes its own around the call, and the headers
	 * carry the Content-Type.
	 *
	 * @param string   $to
	 * @param string   $subject
	 * @param string   $plain    Plain-text body (the fallback).
	 * @param string   $html     HTML body (before the WooCommerce wrapper).
	 * @param string   $heading
	 * @param string[] $headers
	 * @return array{mailer: string, html: string, headers: string[]}
	 */
	private static function dispatch( $to, $subject, $plain, $html, $heading, $headers ) {
		$mailer  = self::wc_mailer();
		$wrapped = '';
		if ( $mailer ) {
			try {
				$wrapped = (string) $mailer->wrap_message( $heading, $html );
			} catch ( \Throwable $e ) {
				$wrapped = '';
			}
		}
		if ( $mailer && '' !== $wrapped ) {
			$sent_headers = array_merge( $headers, array( 'Content-Type: text/html; charset=UTF-8' ) );
			$mailer->send( $to, $subject, $wrapped, $sent_headers, array() );
			return array( 'mailer' => 'woocommerce', 'html' => $wrapped, 'headers' => $sent_headers );
		}
		if ( function_exists( 'wp_mail' ) ) {
			// The plain wp_mail path has no WC_Email to set the sender, so the From
			// header is built here and only here. On the WooCommerce path Woo sets its
			// own sender (and its From filters get a WC_Email, not null).
			$headers = array_merge( self::from_headers(), $headers );
			wp_mail( $to, $subject, $plain, $headers );
			return array( 'mailer' => 'wp_mail', 'html' => '', 'headers' => $headers );
		}
		return array( 'mailer' => 'none', 'html' => '', 'headers' => $headers );
	}

	/**
	 * Headers for a customer email: Reply-To. The From header is added by
	 * dispatch() on the plain wp_mail path only.
	 *
	 * @return string[]
	 */
	public static function customer_headers() {
		return array( 'Reply-To: ' . self::REPLY_TO );
	}

	/**
	 * Headers for a shop alert: none (the From header is added by dispatch() on
	 * the plain wp_mail path only).
	 *
	 * @return string[]
	 */
	public static function admin_headers() {
		return array();
	}

	/**
	 * A From header for the plain wp_mail fallback, built from the WooCommerce
	 * sender settings the way WC_Email builds it: the options
	 * woocommerce_email_from_name and woocommerce_email_from_address, each passed
	 * through the filter of the same name. Only called when there is no WooCommerce
	 * mailer, so there is no WC_Email object and the filters get null as their
	 * second argument (the WooCommerce path never calls them from here). An empty
	 * or invalid address gives no From header, so WordPress's own default applies.
	 * This is not a global wp_mail_from filter: only the plugin's own wp_mail
	 * calls carry it.
	 *
	 * @return string[] Zero or one header line.
	 */
	public static function from_headers() {
		$name    = function_exists( 'get_option' ) ? get_option( 'woocommerce_email_from_name', '' ) : '';
		$address = function_exists( 'get_option' ) ? get_option( 'woocommerce_email_from_address', '' ) : '';
		if ( function_exists( 'apply_filters' ) ) {
			$name    = apply_filters( 'woocommerce_email_from_name', $name, null, $name );
			$address = apply_filters( 'woocommerce_email_from_address', $address, null, $address );
		}
		$address = is_string( $address ) ? trim( preg_replace( '/[\r\n\0]+/', '', $address ) ) : '';
		if ( function_exists( 'sanitize_email' ) ) {
			$address = sanitize_email( $address );
		}
		$valid = function_exists( 'is_email' ) ? (bool) is_email( $address ) : (bool) preg_match( '/^[^\s@<>",;]+@[^\s@<>",;]+\.[^\s@<>",;]+$/', $address );
		if ( '' === $address || ! $valid || preg_match( '/[\s<>",;]/', $address ) ) {
			return array();
		}
		$name = is_scalar( $name ) ? (string) $name : '';
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		// No line break or control character can end the header and start another.
		$name = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $name ) );
		if ( '' === $name ) {
			return array( 'From: ' . $address );
		}
		$name = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $name );
		return array( 'From: "' . $name . '" <' . $address . '>' );
	}
}
