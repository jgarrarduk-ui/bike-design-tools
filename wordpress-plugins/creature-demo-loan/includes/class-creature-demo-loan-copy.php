<?php
/**
 * Placeholder customer copy for the De-Reacher demo loan.
 *
 * Every slot is marked [COPY: Cadence]. Cadence replaces the wording.
 * The consent slot names the maxima from the money constants (£40, £212, £35)
 * so those figures cannot drift from the charge maths.
 *
 * Placeholders: {amount} {reason} {link} {due} {coupon} {order_id}
 * {choice_links} {decision}.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Copy catalogue.
 */
final class Creature_Demo_Loan_Copy {

	/**
	 * @return array<string, array{subject: string, body: string}>
	 */
	public static function catalogue() {
		$consent = sprintf(
			'[COPY: Cadence] I agree that Creature Cycles Ltd may charge this saved card later, without asking me again at the time, for late fees up to %s, a deemed purchase up to %s, and missing parts at the published spare-parts prices (inverted mount kit %s). This box is separate from the general terms and is not ticked for me.',
			Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan_Money::LATE_CAP_PENCE ),
			Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan_Money::KEEP_PENCE ),
			Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan_Money::MOUNT_KIT_PENCE )
		);

		return array(
			'consent_checkbox'              => array(
				'subject' => '',
				'body'    => $consent,
			),
			'consent_error'                 => array(
				'subject' => '',
				'body'    => '[COPY: Cadence] Tick the demo-loan charge box to place this order. It is separate from the general terms.',
			),
			'mixed_basket_error'            => array(
				'subject' => '',
				'body'    => '[COPY: Cadence] A demo loan has to be ordered on its own. Remove the other items, or remove the demo, and try again.',
			),
			'my_account_heading'            => array(
				'subject' => '',
				'body'    => '[COPY: Cadence] Demo loan choices',
			),
			'button_buy'                    => array(
				'subject' => '',
				'body'    => '[COPY: Cadence] Buy a new De-Reacher (£25 off once the kit is back)',
			),
			'button_return'                 => array(
				'subject' => '',
				'body'    => '[COPY: Cadence] Return it, and don’t buy',
			),
			'button_keep'                   => array(
				'subject' => '',
				'body'    => '[COPY: Cadence] Keep this demo kit',
			),
			'reminder_choices_due_minus_5'  => array(
				'subject' => '[COPY: Cadence] Your De-Reacher demo: three choices, due {due}',
				'body'    => "[COPY: Cadence] Your demo is due on {due} (order {order_id}).\n\nYou can:\n(a) Buy a new De-Reacher. Return this kit. Once it is back we email a £25 code, single use, 60 days, your booking email, De-Reacher only. You choose the colour on the product page.\n(b) Return it and don’t buy. Nothing more to pay if it is back on time and complete.\n(c) Keep this kit. The price is in the link we send, and the kit is yours when that is paid.\n\nIf you don’t choose, we treat it as (b): return the kit by the due date.\n\n{choice_links}",
			),
			'reminder_due_day'              => array(
				'subject' => '[COPY: Cadence] Your De-Reacher demo is due today ({due})',
				'body'    => "[COPY: Cadence] Today is the due date for order {order_id}.\n\nThe same three choices are still open: buy a new one (the kit still comes back), return it and don’t buy, or keep this kit.\n\n{choice_links}",
			),
			'reminder_grace_end'            => array(
				'subject' => '[COPY: Cadence] De-Reacher demo grace period ends {due}',
				'body'    => '[COPY: Cadence] The 5-day grace period on order {order_id} ends on {due}. After that, a single late-fee total of £5 a day applies until the kit is scanned back, capped at £40. It is not charged day by day. Booking a return is not the same as a scan.',
			),
			'reminder_deemed_warning'       => array(
				'subject' => '[COPY: Cadence] De-Reacher demo: deemed purchase warning (due {due})',
				'body'    => '[COPY: Cadence] This is the warning about 10 days after the due date ({due}) on order {order_id}. If the kit has not been scanned back by 14 days after the due date, you will be treated as having bought it. The price is £212 less any late fees you have already paid, so the total beyond the £25 demo fee does not exceed £212. We will email a payment link before any charge to the saved card.',
			),
			'charge_notice_keep'            => array(
				'subject' => '[COPY: Cadence] Payment link to keep your De-Reacher demo ({amount})',
				'body'    => "[COPY: Cadence] Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. If this is not paid within 3 working days the keep choice lapses and the loan carries on. We will not charge the saved card for a lapsed keep choice.",
			),
			'charge_notice_late_fee'        => array(
				'subject' => '[COPY: Cadence] De-Reacher demo late fees ({amount})',
				'body'    => "[COPY: Cadence] Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. This is one total, not a daily charge. If you think it is wrong, reply within 3 working days to dispute it. If it is still unpaid and not disputed after 3 working days, we may charge the saved card.",
			),
			'charge_notice_deemed'          => array(
				'subject' => '[COPY: Cadence] De-Reacher deemed purchase ({amount})',
				'body'    => "[COPY: Cadence] Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. This is £212 less late fees already paid. Reply within 3 working days to dispute it. If it is still unpaid and not disputed after 3 working days, we may charge the saved card.",
			),
			'charge_notice_damage'          => array(
				'subject' => '[COPY: Cadence] De-Reacher demo damage or missing parts ({amount})',
				'body'    => "[COPY: Cadence] Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. Missing parts are charged at published spare-parts prices (inverted mount kit £35). Reply within 3 working days to dispute it. If it is still unpaid and not disputed after 3 working days, we may charge the saved card.",
			),
			'charge_authentication_required' => array(
				'subject' => '[COPY: Cadence] Please authenticate the De-Reacher demo charge ({amount})',
				'body'    => "[COPY: Cadence] The saved card needs authentication, so it was not charged.\nAmount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. The amount is still owed.",
			),
			'choice_buy_recorded'           => array(
				'subject' => '[COPY: Cadence] We’ll send your £25 De-Reacher code once the kit is back',
				'body'    => '[COPY: Cadence] You chose to buy a new De-Reacher (order {order_id}). The kit still has to come back. When it has been inspected we will email a £25 code: single use, 60 days, your booking email, De-Reacher only. Choose the colour on the product page. You can switch to a plain return until the kit is scanned in the post.',
			),
			'choice_return_recorded'        => array(
				'subject' => '[COPY: Cadence] Return booked for your De-Reacher demo',
				'body'    => '[COPY: Cadence] You chose to return the kit and not buy (order {order_id}). We will email a Royal Mail Tracked 48 QR code within 1 working day. Postage is paid by us. The loan stays open until the kit is scanned back. You can switch to buying a new one until that scan.',
			),
			'coupon_issued'                 => array(
				'subject' => '[COPY: Cadence] Your £25 De-Reacher code is {coupon}',
				'body'    => '[COPY: Cadence] The demo kit from order {order_id} is back. Your code is {coupon}. It is £25 off, single use, individual use, restricted to this email address, and valid for 60 days. It applies to the De-Reacher only. Choose the colour on the product page. It cannot be exchanged for cash.',
			),
			'keep_choice_lapsed'            => array(
				'subject' => '[COPY: Cadence] The link to keep your De-Reacher demo has lapsed',
				'body'    => '[COPY: Cadence] The keep payment link for order {order_id} was not paid within 3 working days, so that choice has lapsed. The kit is on loan again and the usual dates and fees apply. If you don’t choose again, we treat it as a return.',
			),
			'dispute_decision'              => array(
				'subject' => '[COPY: Cadence] Our decision on the disputed De-Reacher demo charge',
				'body'    => "[COPY: Cadence] Order {order_id}. Decision: {decision}.\nAmount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nYou can still raise this with your card issuer or take it to court. If the decision upholds a charge and it stays unpaid, we may charge the saved card.",
			),
			'admin_return_booked'           => array(
				'subject' => '[COPY: Cadence] Demo return booked — send the Royal Mail QR (order {order_id})',
				'body'    => '[COPY: Cadence] The rider on order {order_id} booked a return and chose not to buy, or booked the return that a new purchase still needs. Forward the Royal Mail Tracked 48 QR within 1 working day. This email is to the shop, not the rider.',
			),
		);
	}

	/**
	 * @param string               $slot
	 * @param array<string, mixed> $args
	 * @return string
	 */
	public static function text( $slot, $args = array() ) {
		$all = self::catalogue();
		if ( ! isset( $all[ $slot ] ) ) {
			return '[COPY: Cadence]';
		}
		return self::fill( $all[ $slot ]['body'], $args );
	}

	/**
	 * @param string               $slot
	 * @param array<string, mixed> $args
	 * @return string
	 */
	public static function subject( $slot, $args = array() ) {
		$all = self::catalogue();
		if ( ! isset( $all[ $slot ] ) ) {
			return '[COPY: Cadence]';
		}
		return self::fill( $all[ $slot ]['subject'], $args );
	}

	/**
	 * @return string[]
	 */
	public static function slots() {
		return array_keys( self::catalogue() );
	}

	/**
	 * @param string               $text
	 * @param array<string, mixed> $args
	 * @return string
	 */
	public static function fill( $text, $args ) {
		$map = array(
			'{amount}'       => isset( $args['amount'] ) ? (string) $args['amount'] : '',
			'{reason}'       => isset( $args['reason'] ) ? (string) $args['reason'] : '',
			'{link}'         => isset( $args['link'] ) ? (string) $args['link'] : '',
			'{due}'          => isset( $args['due'] ) ? (string) $args['due'] : '',
			'{coupon}'       => isset( $args['coupon'] ) ? (string) $args['coupon'] : '',
			'{order_id}'     => isset( $args['order_id'] ) ? (string) $args['order_id'] : '',
			'{choice_links}' => isset( $args['choice_links'] ) ? (string) $args['choice_links'] : '',
			'{decision}'     => isset( $args['decision'] ) ? (string) $args['decision'] : '',
		);
		return strtr( (string) $text, $map );
	}
}
