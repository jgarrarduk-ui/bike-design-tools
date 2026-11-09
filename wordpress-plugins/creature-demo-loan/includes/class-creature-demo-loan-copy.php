<?php
/**
 * Customer copy for the De-Reacher demo loan.
 *
 * The wording is Cadence's, per slot, from demo-loan-copy-v2. A slot she has
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
			"Your demo kit (order {order_id}) is back with us and has been inspected. This is the only bill for this loan.\n\nWhat’s owed: {reason}.\nTotal: {amount}.\nPay here: {link}\n\nDamage or missing parts. What we found:\n{findings}\nOur photos:\n{photos}\nWe charge the lower of the reasonable cost of repair and the price on our spare-parts list (creaturecycles.co.uk/spare-parts/). A missing inverted mount kit is %s rather than the price of its separate parts. We don’t charge for fair wear and tear, or for anything shown in our photos from before we sent the kit.\n\nIf a late fee is listed above: your due date was {due}. After 5 days’ grace, it’s £3 for each day from the 6th day after the due date up to and including the day you posted the kit (the earlier of your proof of posting and Royal Mail’s first scan), up to £24.\n\nThe most you can be charged after the £25 demo fee is £212 in total.\n\nIf you think it’s wrong, reply to this email or email info@creaturecycles.co.uk within 7 days and tell us why. We won’t charge your card while we look at it. If it isn’t paid or disputed within 7 days, we’ll charge your saved card.",
			$mount_kit
		);

		return array(
			'consent_checkbox'              => array(
				'subject' => '',
				'body'    => $consent,
			),
			// Terms 11.3 and Appendix B: the "Send my kit now" checkbox. Cadence's v2 has
			// no label for it, so this stays empty. While it is empty the checkbox is
			// not shown and no answer is stored.
			'express_request_checkbox'      => array(
				'subject' => '',
				'body'    => '',
			),
			// Terms 3A.3: shown when the keep payment is refused after a reported crash. No wording in v2, so empty.
			'crash_hold_notice'             => array(
				'subject' => '',
				'body'    => '',
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
				'subject' => 'De-Reacher demo bill: {amount} to pay (order {order_id})',
				'body'    => $damage,
			),
			'charge_authentication_required' => array(
				'subject' => 'Action needed: pay your De-Reacher demo charge ({amount})',
				'body'    => "Your bank asked to confirm this payment, so your saved card wasn’t charged.\nAmount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nOrder {order_id}. The amount is still owed. Please pay it using the link.",
			),
			// Terms 4.5. Cadence v2.
			'charge_declined'               => array(
				'subject' => 'Your card was declined: De-Reacher demo charge ({amount})',
				'body'    => "We tried to charge your saved card for your De-Reacher demo (order {order_id}), and the payment was declined.\nAmount: {amount}.\nReason: {reason}.\nPayment link: {link}\n\nThe amount is still owed. Please pay it using the link.",
			),
			// Terms 3.2. Cadence v2.
			'dates_on_delivery'             => array(
				'subject' => 'Your De-Reacher demo dates: due back {due}',
				'body'    => "Your De-Reacher demo kit (order {order_id}) has been delivered. The loan lasts 21 days, and the delivery day is day 1.\n\nDue date: {due}. Post the kit back by then, or choose to keep it.\nLast day of grace: {grace_end}. No fee if the kit is posted by the end of this day.\nLate fee: £3 a day from the day after {grace_end}, up to £24 in total.\nDeemed purchase date: {deemed_on}. If the kit hasn’t been posted back by the end of the day before, it’s treated as bought at your keep price of {price}.\n\nEach day ends at midnight, UK time. The kit counts as posted on the date on your proof of posting or the date Royal Mail first scans it, whichever is earlier. Booking a return doesn’t count. These dates are also in My account → Demo loan. If you need longer, ask us before the due date.\n\nBefore you ride: fit it following our user guide (creaturecycles.co.uk/de-reacher-user-guide/), the inverted mount kit instructions and the torque card, using a calibrated torque wrench. Please don’t cut your steerer. Check the bolts before every ride. If you crash or have an impact, stop using it and tell us at info@creaturecycles.co.uk. Keep the original box, because the whole kit goes back in it.",
			),
			// Terms 11.1 and Appendix B: body only, added to the end of the Woo order email. Cadence v2. The subject is not used.
			'booking_confirmation'          => array(
				'subject' => '',
				'body'    => "De-Reacher demo booking (order {order_id})\n\nYour demo terms (version 5) and our model cancellation form are attached to this email. We’ll email your due date, the last day of your grace period and your deemed purchase date once the kit is delivered.\n\nYour keep price: {price}\n\nYour consent to later charges ({consent_version}, agreed on {consent_at}):\n“{consent}”\n\nYour answer to “Send my kit now”: {express_answer}. If you answered Yes, we’ll send the kit within your 14-day cancellation period. If you answered No, we’ll hold it until that period ends.\n\nCancelling: you can cancel this demo booking within 14 days of the day we confirm it, without giving a reason. Email info@creaturecycles.co.uk or use the attached model cancellation form. If you cancel before we’ve dispatched the kit, we’ll refund the full £25 within 14 days. If the kit has already been sent, book your return in My account → Demo loan and send it back. We’ll refund the £25 within 14 days of your cancellation, less £25 ÷ 21 for each day of the loan up to and including the day you post it.",
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
			// Shop alert, not rider copy. Plain wording, not Cadence's.
			'admin_attachments_missing'     => array(
				'subject' => 'Demo booking confirmation sent without attachments (order {order_id})',
				'body'    => 'The booking confirmation for order {order_id} went out without the attachment wording because no file can be attached for: {missing}. Set the files under WooCommerce → Demo loan. This email goes to the shop, not the rider.',
			),
			// Shop alert, not rider copy. Plain wording, not Cadence's.
			'admin_received_date_missing'   => array(
				'subject' => 'Demo return scanned, no Kit received date (order {order_id})',
				'body'    => 'A return scan is recorded on order {order_id}, but no Kit received date is entered. The 5 working day bill clock starts only from that date, so until it is entered there is no day-4 alert and no late-fee-only fallback bill. Enter the date the kit arrived back on the order and update it. This email goes to the shop, not the rider.',
			),
			'admin_dispute_nearing'          => array(
				'subject' => 'Demo dispute: day {day} of 14 (order {order_id})',
				'body'    => 'Order {order_id} has a dispute open since {opened}. This is day {day} of 14. Record a decision (uphold or waive) and send it before day 14 (terms 4.4(b)). Don’t charge the saved card any sooner than 7 days after the decision email.',
			),
			'admin_return_booked'           => array(
				'subject' => 'Demo return booked: send the Royal Mail QR within 1 working day (order {order_id})',
				'body'    => 'The rider on order {order_id} has booked a return. Email them a Royal Mail Tracked 48 QR code within 1 working day, with a post-by date (terms 10.1 and 10.3). Each extra working day moves their grace period, late fees and deemed purchase date back by a day. This email goes to the shop, not the rider.',
			),
			// Shop alert. Cadence v2, plus one plain sentence ({deadline_day}) for the day-4 warning.
			'admin_bill_due'                => array(
				'subject' => 'Demo kit back: inspect and bill by {deadline} (order {order_id})',
				'body'    => "The demo kit on order {order_id} was received back on {received}. Under terms 4.4 and 6.4(b), the one bill for late fees, damage and missing parts must go out within 5 working days of that, so by {deadline}. Bank holidays aren’t counted, so the real deadline may be later. Work to this date.\n\nThe bill must go out by the end of {deadline_day} ({deadline}).\n\nRecord the inspection on the order now, even if nothing is owed. The rider’s £25 code is only issued once it’s recorded. If you’re charging for damage or missing parts, enter what you found and add the photos before saving. Without the findings the bill won’t send, and the terms require the photos in it. Recording the inspection raises the bill.\n\nIf the deadline passes with no inspection recorded, the plugin sends a late-fee-only bill (if a late fee is due), and no damage or missing-parts charge can be made on this loan. This email goes to the shop, not the rider.",
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
			'{opened}'          => $date( 'opened' ),
			'{findings}'        => isset( $args['findings'] ) ? (string) $args['findings'] : '',
			'{photos}'          => isset( $args['photos'] ) ? (string) $args['photos'] : '',
			'{received}'        => $date( 'received' ),
			'{deadline}'        => $date( 'deadline' ),
			'{missing}'         => isset( $args['missing'] ) ? (string) $args['missing'] : '',
			'{deadline_day}'    => isset( $args['deadline_day'] ) ? (string) $args['deadline_day'] : '',
			'{consent}'         => isset( $args['consent'] ) ? (string) $args['consent'] : '',
			'{consent_version}' => isset( $args['consent_version'] ) ? (string) $args['consent_version'] : '',
			'{consent_at}'      => isset( $args['consent_at'] ) ? (string) $args['consent_at'] : '',
			'{express_answer}'  => isset( $args['express_answer'] ) ? (string) $args['express_answer'] : '',
		);
		return strtr( (string) $text, $map );
	}
}
