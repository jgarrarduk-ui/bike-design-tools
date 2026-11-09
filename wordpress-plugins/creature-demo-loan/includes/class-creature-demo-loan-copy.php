<?php
/**
 * Customer copy for the De-Reacher demo loan.
 *
 * The wording is Cadence's, per slot, from demo-loan-copy-v1. A slot she has
 * not written yet is an empty string and is never sent as a blank email (see
 * Creature_Demo_Loan_Mail). The consent slot names the maxima from the money
 * constants (£24, £212, £35) in the sprintf slots so those figures cannot drift
 * from the charge maths.
 *
 * Placeholders: {amount} {price} {reason} {link} {due} {coupon} {order_id}
 * {choice_links} {decision} {findings} {photos} {grace_end} {deemed_on}
 * {consent} {consent_version} {consent_at} {express_answer}. {price} is the
 * loan's keep price. Dates that reach a customer ({due}, {grace_end},
 * {deemed_on}) are shown as "30 November 2026"; stored dates stay Y-m-d. Email
 * bodies are templates filled at send time and are edited in place; only the
 * consent wording is versioned.
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

	/** The consent version stored on new orders: the terms' "version 5". */
	const CONSENT_CURRENT = 'version-5';

	/**
	 * @return array<string, array{subject: string, body: string}>
	 */
	public static function catalogue() {
		$catalogue = self::default_catalogue();
		if ( function_exists( 'apply_filters' ) ) {
			// Lets a site or a test change a slot without editing this file.
			$filtered = apply_filters( 'creature_demo_loan_copy_catalogue', $catalogue );
			if ( is_array( $filtered ) ) {
				$catalogue = $filtered;
			}
		}
		return $catalogue;
	}

	/**
	 * @return array<string, array{subject: string, body: string}>
	 */
	private static function default_catalogue() {
		$late_daily = Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan_Money::LATE_DAILY_PENCE );
		$late_cap   = Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan_Money::LATE_CAP_PENCE );
		$mount_kit  = Creature_Demo_Loan_Money::format_gbp( Creature_Demo_Loan_Money::MOUNT_KIT_PENCE );
		$consent    = self::consent_wording( class_exists( 'Creature_Demo_Loan' ) ? Creature_Demo_Loan::consent_version() : self::CONSENT_CURRENT );
		$grace      = sprintf(
			'Your De-Reacher demo (order {order_id}) was due back on {due}. Your 5 days’ grace end at midnight (UK time) on the 5th day after that date. If the kit is posted after that, a late fee of %s a day applies from the 6th day after the due date up to and including the day you post it, up to %s in total. The kit counts as posted on the date on your proof of posting or the date Royal Mail first scans it, whichever is earlier. Booking a return doesn’t count. Late fees are billed once, after the kit is back. Book your return in My account → Demo loan.',
			$late_daily,
			$late_cap
		);
		$damage = sprintf(
			"Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. When your demo kit came back, our inspection found damage beyond fair wear and tear, or parts missing.\nWhat we found and our photos:\n{findings}\n{photos}\n\nWe charge the lower of the reasonable cost of repair and our published spare-parts price (creaturecycles.co.uk/spare-parts/). A missing inverted mount kit is %s rather than the price of its separate parts. We don’t charge for fair wear and tear or for anything shown in our photos from before we sent the kit. The most you can be charged after the £25 demo fee is £212 in total.\n\nIf you think it’s wrong, reply to this email or email info@creaturecycles.co.uk within 7 days and tell us why. We won’t charge your card while we look at it. If it isn’t paid or disputed within 7 days, we’ll charge your saved card.",
			$mount_kit
		);

		return array(
			'consent_checkbox'              => array(
				'subject' => '',
				'body'    => $consent,
			),
			'consent_error'                 => array(
				'subject' => '',
				'body'    => 'To book a demo, tick the box agreeing that we can charge your saved card later under clause 4 of the demo terms. It’s separate from accepting the terms.',
			),
			'mixed_basket_error'            => array(
				'subject' => '',
				'body'    => 'A demo has to be ordered on its own. Remove the other items or the demo, then try again.',
			),
			'service_area_error'            => array(
				'subject' => '',
				'body'    => 'Demos are only available to addresses in the United Kingdom (England, Scotland, Wales and Northern Ireland). Jersey, Guernsey and the Isle of Man are outside the UK, so we can’t send a demo there.',
			),
			'my_account_heading'            => array(
				'subject' => '',
				'body'    => 'Demo loan',
			),
			'button_return'                 => array(
				'subject' => '',
				'body'    => 'Return it (£25 code once it’s back)',
			),
			'button_keep'                   => array(
				'subject' => '',
				'body'    => 'Keep it',
			),
			'reminder_choices_due_minus_5'  => array(
				'subject' => 'Your De-Reacher demo is due back on {due}: your choices',
				'body'    => "Your De-Reacher demo (order {order_id}) is due back on {due}. You have two choices.\n\nReturn it. Book your return using the link below and we’ll email you a Royal Mail Tracked 48 QR code within 1 working day. Postage is paid by us. Once the kit is back with us, we’ll email you a £25 code towards a new De-Reacher in the colour of your choice. It’s single use, tied to the email address you booked with, valid for 60 days and for De-Reacher products only.\n\nKeep it. Keep the kit for your keep price of {price}. No late fees are added. We’ll email you a payment link, and once it’s paid the kit is yours, sold as used with our standard 2-year warranty from the date of payment. You can choose this up to the end of the 13th day after the due date.\n\nIf you don’t choose, we treat it as a return, so please post the kit back by {due}. After that there are 5 days’ grace, then a late fee of £3 a day, up to £24.\n\n{choice_links}",
			),
			'reminder_due_day'              => array(
				'subject' => 'Your De-Reacher demo is due back today ({due})',
				'body'    => "Today is the due date for your De-Reacher demo (order {order_id}).\n\nReturn it: book your return below and we’ll email you a Royal Mail Tracked 48 QR code within 1 working day. Once the kit is back, we’ll email you a £25 code towards a new De-Reacher.\nKeep it: keep the kit for your keep price of {price}, with no late fees added.\n\nIf you don’t choose, we treat it as a return. You have 5 days’ grace from today, then a late fee of £3 a day, up to £24.\n\n{choice_links}",
			),
			'reminder_grace_end'            => array(
				'subject' => 'Your De-Reacher demo grace period is ending (due date {due})',
				'body'    => $grace,
			),
			'reminder_deemed_warning'       => array(
				'subject' => 'De-Reacher demo: it will be treated as bought unless it’s posted by day 13 (due date {due})',
				'body'    => 'Your De-Reacher demo (order {order_id}) was due back on {due}. If it hasn’t been posted back by the end of the 13th day after that date, it will be treated as bought on the 14th day, at your keep price of {price}. No late fees are added to that price. We’ll email you the amount and a payment link first. If it isn’t paid or disputed within 7 days, we’ll charge your saved card. To avoid this, book your return in My account → Demo loan and post the kit with the QR code we email you. If you’d rather keep it, you can choose that now for the same price.',
			),
			'charge_notice_keep'            => array(
				'subject' => 'Payment link to keep your De-Reacher demo kit ({amount})',
				'body'    => "Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. This is your keep price of {price}, less any late fees you’ve already paid on this loan. No late fees are added. Once it’s paid, the loan closes, you don’t need to send anything back, and the kit is yours. It’s sold as used: it has been ridden on earlier demos and inspected between loans, so cosmetic marks are expected. Our standard 2-year warranty applies from the date of payment.\n\nIf this isn’t paid within 7 days, the kit goes back on loan and the usual dates and fees apply. We won’t charge your saved card for this.",
			),
			'charge_notice_late_fee'        => array(
				'subject' => 'De-Reacher demo late fee: {amount} to pay',
				'body'    => "Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. Your due date was {due}. After 5 days’ grace, the late fee is £3 for each day from the 6th day after the due date up to and including the day you posted the kit, up to £24. This is one bill, not a daily charge. The most you can be charged after the £25 demo fee is £212 in total.\n\nIf you think it’s wrong, reply to this email or email info@creaturecycles.co.uk within 7 days and tell us why. We won’t charge your card while we look at it. If it isn’t paid or disputed within 7 days, we’ll charge your saved card.",
			),
			'charge_notice_deemed'          => array(
				'subject' => 'De-Reacher demo treated as bought: {amount} to pay',
				'body'    => "Amount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. The kit wasn’t posted back by the end of the 13th day after your due date ({due}), so under clause 8 of the demo terms it’s treated as bought, for your keep price of {price} less any late fees you’ve already paid. No late fees are added. The kit is yours once this is paid. It’s sold as used, with our standard 2-year warranty from the date of payment.\n\nIf you post it back before paying, we’ll cancel this and bill late fees of £24 instead, plus any charge for damage or missing parts. If you post it back within 30 days of paying, we’ll inspect it and refund the keep price less late fees of £24 and any damage charge.\n\nTo dispute this, reply to this email or email info@creaturecycles.co.uk within 7 days. If it isn’t paid or disputed within 7 days, we’ll charge your saved card.",
			),
			'charge_notice_damage'          => array(
				'subject' => 'De-Reacher demo damage or missing parts: {amount} to pay',
				'body'    => $damage,
			),
			'charge_authentication_required' => array(
				'subject' => 'Action needed: pay your De-Reacher demo charge ({amount})',
				'body'    => "Your bank asked to confirm this payment, so your saved card wasn’t charged.\nAmount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. The amount is still owed. Please pay it using the link.",
			),
			// Terms 4.5. No wording from Cadence yet, so this stays empty and is not sent.
			'charge_declined'               => array(
				'subject' => '',
				'body'    => '',
			),
			// Terms 3.2. No wording from Cadence yet.
			'dates_on_delivery'             => array(
				'subject' => '',
				'body'    => '',
			),
			// Terms 11.1 and Appendix B: extra content on the order confirmation. No wording from Cadence yet.
			'booking_confirmation'          => array(
				'subject' => '',
				'body'    => '',
			),
			'choice_return_recorded'        => array(
				'subject' => 'Return booked for your De-Reacher demo: £25 code once it’s back',
				'body'    => "You’ve chosen to return your De-Reacher demo kit (order {order_id}). We’ll email you a Royal Mail Tracked 48 QR code within 1 working day. Postage is paid by us. If we take longer, each extra day moves your grace period, late fees and deemed purchase date back by a day.\n\nPack the whole kit in the original box: the stem with its bar clamps, bolts, washers and top cap; the inverted mount kit with its insert, both steerer caps, three preload bolts and washer; and the torque card. Show the QR code at a Post Office, a Royal Mail Customer Service Point or a Royal Mail Parcelshop, and they’ll print the label. Parcel postboxes can’t be used. Keep your proof of posting.\n\nOnce the kit is back with us, we’ll email you a £25 code towards a new De-Reacher in the colour of your choice. Booking a return doesn’t count as returning the kit, so the loan stays open until it’s posted.",
			),
			'coupon_issued'                 => array(
				'subject' => 'Your £25 De-Reacher code: {coupon}',
				'body'    => "Your demo kit from order {order_id} is back with us. Here’s your code: {coupon}\n\nIt takes £25 off a new De-Reacher, in the colour of your choice. It’s single use, tied to the email address you booked with, and valid for 60 days from today, for De-Reacher products only. It can’t be exchanged for cash or transferred. You don’t have to use it.",
			),
			'keep_confirm'                  => array(
				'subject' => '',
				'body'    => 'Keep this demo kit for {amount}? We’ll email you a payment link. Once it’s paid, the loan closes and the kit is yours. It’s sold as used, with our standard 2-year warranty from the date of payment. If the link isn’t paid within 7 days, the kit goes back on loan and the usual dates and fees apply.',
			),
			'choice_recorded'               => array(
				'subject' => '',
				'body'    => 'Choice saved. Check your email for the next step.',
			),
			'keep_choice_lapsed'            => array(
				'subject' => 'Your De-Reacher demo keep link has lapsed',
				'body'    => 'The payment link to keep your demo kit (order {order_id}) wasn’t paid within 7 days, so that choice has lapsed. The kit is on loan again, and the usual dates and fees apply as if you hadn’t chosen to keep it. You can still choose to keep it up to the end of the 13th day after your due date. If you don’t choose, we treat it as a return. Book it in My account → Demo loan.',
			),
			'dispute_decision'              => array(
				'subject' => 'De-Reacher demo: our final decision on your disputed charge',
				'body'    => "Order {order_id}. We’ve looked again at the charge you disputed.\nDecision: charge {decision}.\nAmount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nIf the charge is waived, you have nothing to pay. If it’s upheld, you can pay using the link. If it isn’t paid, we’ll charge your saved card no sooner than 7 days after this email.\n\nThis is our final response. If you still disagree, you can raise it with your card issuer or bank, or take it to court.",
			),
			'admin_dispute_nearing'          => array(
				'subject' => 'Demo dispute: day {day} of 14 (order {order_id})',
				'body'    => 'Order {order_id} has a dispute open since {opened}. This is day {day} of 14. Record a decision (uphold or waive) and send it before day 14 (terms 4.4(b)). Don’t charge the saved card any sooner than 7 days after the decision email.',
			),
			'admin_return_booked'           => array(
				'subject' => 'Demo return booked: send the Royal Mail QR within 1 working day (order {order_id})',
				'body'    => 'The rider on order {order_id} has booked a return. Email them a Royal Mail Tracked 48 QR code within 1 working day, with a post-by date (terms 10.1 and 10.3). Each extra working day moves their grace period, late fees and deemed purchase date back by a day. This email goes to the shop, not the rider.',
			),
			// Internal alert to the shop, not Cadence's wording. Written to be plain and functional.
			'admin_bill_due'                => array(
				'subject' => 'Demo bill due by {deadline}: inspect and bill (order {order_id})',
				'body'    => 'The kit on order {order_id} arrived back on {received}. Terms 4.4 and 6.4(b) say the one bill for late fees, damage and missing parts goes out within 5 working days of that, so by {deadline}. Record the inspection and raise the bill. If day 5 passes without it, the plugin sends a late-fee-only bill and no damage or missing-parts charge can follow on this loan. This email goes to the shop, not the rider.',
			),
		);
	}

	/**
	 * Consent versions the plugin knows, newest first.
	 *
	 * @return string[]
	 */
	public static function consent_versions() {
		return array( self::CONSENT_CURRENT, 'v5b', 'v5', 'v4' );
	}

	/**
	 * Fixed wording for one terms version. `version-5` is terms v5 Appendix A,
	 * character for character. `v5b`, `v5` and `v4` stay exactly as they were so
	 * an older order can still be read back as the text that version stored.
	 * `v5` is an older draft (a fixed "£212") and is never used for a new order.
	 * A version string that matches nothing falls back to `version-5`.
	 *
	 * @param string $version
	 * @return string
	 */
	public static function consent_wording( $version = self::CONSENT_CURRENT ) {
		$v4         = 'I agree that Creature Cycles Ltd may charge this saved card later, without asking me again at the time, for late fees up to £40.00, a deemed purchase up to £212.00, and damage or missing parts, never more than the cost of repair or replacement (inverted mount kit £35.00). This box is separate from the general terms and is not ticked for me.';
		$v5         = 'I agree that Creature Cycles Ltd can charge this card later, without asking me again at the time, for: late fees (up to £24), the deemed purchase price if I don\'t send the kit back (£212), and damage or missing parts at published spare-parts prices, including £35 for a missing inverted mount kit. All of these together will never be more than £212. You\'ll email me the amount and the reason first, and I\'ll have 7 days to pay by link or dispute it. (Demo terms, clause 4.)';
		$v5b        = 'I agree that Creature Cycles Ltd can charge this card later, without asking me again at the time, for: late fees (up to £24), the deemed purchase price if I don\'t send the kit back (my keep price, capped at £212), and damage or missing parts at published spare-parts prices, including £35 for a missing inverted mount kit. All of these together will never be more than £212. You\'ll email me the amount and the reason first, and I\'ll have 7 days to pay by link or dispute it. (Demo terms, clause 4.)';
		$version_5  = 'I agree that Creature Cycles Ltd can charge this card later, without asking me again at the time, for: late fees (up to £24), the deemed purchase price if I don’t send the kit back (my keep price, capped at £212), and damage or missing parts at published spare-parts prices, including £35 for a missing inverted mount kit. All of these together will never be more than £212. You’ll email me the amount and the reason first, and I’ll have 7 days to pay by link or dispute it. (Demo terms, clause 4.)';
		$version    = (string) $version;
		if ( 'v4' === $version ) {
			return $v4;
		}
		if ( 'v5' === $version ) {
			return $v5;
		}
		if ( 'v5b' === $version ) {
			return $v5b;
		}
		// `version-5`, and any version string that matches nothing.
		return $version_5;
	}

	/**
	 * How a stored consent version reads in the admin and in the record sent to
	 * the rider. `version-5` and `v5b` are both the terms' version 5. `v5` is an
	 * older draft that was never the published text.
	 *
	 * @param string $version
	 * @return string
	 */
	public static function consent_version_label( $version ) {
		$version = (string) $version;
		if ( self::CONSENT_CURRENT === $version || 'v5b' === $version ) {
			return 'Terms version 5';
		}
		if ( 'v5' === $version ) {
			return 'Terms version 5 (earlier draft, stored as v5)';
		}
		if ( 'v4' === $version ) {
			return 'Terms version 4';
		}
		return '' === $version ? '' : $version;
	}

	/**
	 * @param string               $slot
	 * @param array<string, mixed> $args
	 * @return string
	 */
	public static function text( $slot, $args = array() ) {
		$all = self::catalogue();
		if ( ! isset( $all[ $slot ] ) ) {
			return '';
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
			return '';
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
		// A date that reaches a customer reads "30 November 2026". The stored
		// value stays Y-m-d and the caller passes it that way.
		$date = static function ( $key ) use ( $args ) {
			return isset( $args[ $key ] ) ? Creature_Demo_Loan_Calendar::format_uk( $args[ $key ] ) : '';
		};
		$map  = array(
			'{amount}'          => isset( $args['amount'] ) ? (string) $args['amount'] : '',
			'{price}'           => isset( $args['price'] ) ? (string) $args['price'] : '',
			'{reason}'          => isset( $args['reason'] ) ? (string) $args['reason'] : '',
			'{link}'            => isset( $args['link'] ) ? (string) $args['link'] : '',
			'{due}'             => $date( 'due' ),
			'{grace_end}'       => $date( 'grace_end' ),
			'{deemed_on}'       => $date( 'deemed_on' ),
			'{coupon}'          => isset( $args['coupon'] ) ? (string) $args['coupon'] : '',
			'{order_id}'        => isset( $args['order_id'] ) ? (string) $args['order_id'] : '',
			'{choice_links}'    => isset( $args['choice_links'] ) ? (string) $args['choice_links'] : '',
			'{decision}'        => isset( $args['decision'] ) ? (string) $args['decision'] : '',
			'{day}'             => isset( $args['day'] ) ? (string) $args['day'] : '',
			'{opened}'          => isset( $args['opened'] ) ? (string) $args['opened'] : '',
			'{findings}'        => isset( $args['findings'] ) ? (string) $args['findings'] : '',
			'{photos}'          => isset( $args['photos'] ) ? (string) $args['photos'] : '',
			'{received}'        => isset( $args['received'] ) ? (string) $args['received'] : '',
			'{deadline}'        => isset( $args['deadline'] ) ? (string) $args['deadline'] : '',
			'{consent}'         => isset( $args['consent'] ) ? (string) $args['consent'] : '',
			'{consent_version}' => isset( $args['consent_version'] ) ? (string) $args['consent_version'] : '',
			'{consent_at}'      => isset( $args['consent_at'] ) ? (string) $args['consent_at'] : '',
			'{express_answer}'  => isset( $args['express_answer'] ) ? (string) $args['express_answer'] : '',
		);
		return strtr( (string) $text, $map );
	}
}
