<?php
/**
 * Pounds and pence for the De-Reacher demo loan.
 *
 * Keep and deemed purchase are £212 (bundle £249 − £25 demo fee − £12 used).
 * Late fees are £5 a day from day 6 after the due date, capped at £40, and
 * charged once. Prices are constants. The two James flags are filters.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Amounts in pence.
 */
final class Creature_Demo_Loan_Money {

	const KEEP_PENCE       = 21200;
	const LATE_DAILY_PENCE = 500;
	const LATE_CAP_PENCE   = 4000;
	const MOUNT_KIT_PENCE  = 3500;
	const COUPON_PENCE     = 2500;
	const DEMO_FEE_PENCE   = 2500;

	/**
	 * @param int $days_after_due
	 * @return int
	 */
	public static function late_fee_pence_for_offset( $days_after_due ) {
		$days = Creature_Demo_Loan_Calendar::late_fee_days( $days_after_due );
		$raw  = $days * self::LATE_DAILY_PENCE;
		if ( $raw > self::LATE_CAP_PENCE ) {
			return self::LATE_CAP_PENCE;
		}
		return $raw;
	}

	/**
	 * @param string $due Y-m-d.
	 * @param string $end Return scan, or the as-of date when nothing is scanned.
	 * @return int
	 */
	public static function late_fee_pence_for_dates( $due, $end ) {
		if ( ! Creature_Demo_Loan_Calendar::is_ymd( $due ) || ! Creature_Demo_Loan_Calendar::is_ymd( $end ) ) {
			return 0;
		}
		return self::late_fee_pence_for_offset( Creature_Demo_Loan_Calendar::days_between( $due, $end ) );
	}

	/**
	 * Keep-link amount.
	 *
	 * Included (default, terms 3A.1(c)): late fees sit inside £212. Fees
	 * already paid are taken off so the total beyond the £25 does not pass
	 * £212. Fees that are only accrued are not added.
	 *
	 * Not included: unpaid late fees are added on top of £212.
	 *
	 * @param int  $late_paid Pence already paid.
	 * @param int  $late_due  Pence accrued, paid and unpaid.
	 * @param bool $included  creature_demo_loan_late_fees_included_in_keep.
	 * @return int
	 */
	public static function keep_amount_pence( $late_paid, $late_due, $included ) {
		$paid  = max( 0, (int) $late_paid );
		$due   = max( 0, (int) $late_due );
		$price = self::KEEP_PENCE;
		if ( $included ) {
			$credit = min( $paid, $price );
			return $price - $credit;
		}
		$unpaid = max( 0, $due - $paid );
		return $price + $unpaid;
	}

	/**
	 * Deemed purchase is always £212 less late fees already paid (terms 7.4 and 8.2).
	 * The keep-price flag does not change this.
	 *
	 * @param int $late_paid
	 * @return int
	 */
	public static function deemed_amount_pence( $late_paid ) {
		$paid = max( 0, (int) $late_paid );
		return max( 0, self::KEEP_PENCE - $paid );
	}

	/**
	 * Refund of a paid deemed purchase after the kit comes back (terms 8.5).
	 *
	 * Retain the £40 late-fee cap, counting fees already paid towards that
	 * cap, then retain the recorded damage. Never refund more than was paid.
	 *
	 * @param int $deemed_paid
	 * @param int $late_already_paid
	 * @param int $damage_pence
	 * @return int
	 */
	public static function post_deemed_refund_pence( $deemed_paid, $late_already_paid, $damage_pence ) {
		$paid    = max( 0, (int) $deemed_paid );
		$already = max( 0, (int) $late_already_paid );
		$damage  = max( 0, (int) $damage_pence );
		$retain  = max( 0, self::LATE_CAP_PENCE - $already );
		return max( 0, $paid - $retain - $damage );
	}

	/**
	 * @param bool $ever_chose_keep
	 * @param bool $include_keep_choosers Flag default true (terms 8.5 for anyone).
	 * @return bool
	 */
	public static function refund_applies( $ever_chose_keep, $include_keep_choosers ) {
		if ( $include_keep_choosers ) {
			return true;
		}
		return ! $ever_chose_keep;
	}

	/**
	 * @param int $pence
	 * @return string
	 */
	public static function format_gbp( $pence ) {
		$pence = (int) $pence;
		$sign  = $pence < 0 ? '-' : '';
		$pence = abs( $pence );
		return $sign . '£' . intdiv( $pence, 100 ) . '.' . str_pad( (string) ( $pence % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Decimal string for Woo totals. Integer maths, no float drift.
	 *
	 * @param int $pence
	 * @return string
	 */
	public static function pence_to_decimal( $pence ) {
		$pence = (int) $pence;
		$sign  = $pence < 0 ? '-' : '';
		$pence = abs( $pence );
		return $sign . intdiv( $pence, 100 ) . '.' . str_pad( (string) ( $pence % 100 ), 2, '0', STR_PAD_LEFT );
	}
}
