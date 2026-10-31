<?php
/**
 * Pounds and pence for the De-Reacher demo loan.
 *
 * The keep price is worked out at booking: the bundle price that day, less the
 * £25 demo fee and £12 for used stock, never more than £212, floored at 0.
 * £212 is the ceiling, not the price. This class takes the keep price as an
 * argument and never reads orders or post meta. Late fees are £3 a day from
 * day 6 after the due date, capped at £24, and charged once. Unpaid late fees
 * are never added to the keep price. The refund flag is a filter.
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

	const KEEP_CEILING_PENCE = 21200;
	const USED_STOCK_PENCE   = 1200;
	const LATE_DAILY_PENCE   = 300;
	const LATE_CAP_PENCE     = 2400;
	const MOUNT_KIT_PENCE    = 3500;
	const COUPON_PENCE       = 2500;
	const DEMO_FEE_PENCE     = 2500;

	/**
	 * Keep price from the bundle price at booking: less the demo fee and the
	 * used-stock allowance, never more than the ceiling, never below zero.
	 *
	 * @param int $bundle_pence
	 * @return int
	 */
	public static function keep_price_pence( $bundle_pence ) {
		return max( 0, min( self::KEEP_CEILING_PENCE, (int) $bundle_pence - self::DEMO_FEE_PENCE - self::USED_STOCK_PENCE ) );
	}

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
	 * Keep-link amount. Unpaid late fees are never added (terms 3A.1(b)).
	 * Fees already paid come off the keep price so a rider who has paid them
	 * stays inside the cap in 4.2.
	 *
	 * @param int $keep_price This loan's keep price in pence.
	 * @param int $late_paid  Pence already paid.
	 * @return int
	 */
	public static function keep_amount_pence( $keep_price, $late_paid ) {
		$price = max( 0, (int) $keep_price );
		$paid  = max( 0, (int) $late_paid );
		return $price - min( $paid, $price );
	}

	/**
	 * Deemed purchase is always the keep price less late fees already paid
	 * (terms 7.4 and 8.2). The keep-price flag does not change this.
	 *
	 * @param int $keep_price This loan's keep price in pence.
	 * @param int $late_paid
	 * @return int
	 */
	public static function deemed_amount_pence( $keep_price, $late_paid ) {
		$price = max( 0, (int) $keep_price );
		$paid  = max( 0, (int) $late_paid );
		return max( 0, $price - $paid );
	}

	/**
	 * Refund of a paid deemed purchase after the kit comes back (terms 8.5).
	 *
	 * Retain the rest of the £24 late-fee cap plus recorded damage, but never
	 * more than the keep price together (terms 8.5(b)). The refund is what was
	 * paid minus that retention, and it does not go below zero.
	 *
	 * @param int $keep_price This loan's keep price in pence.
	 * @param int $deemed_paid
	 * @param int $late_already_paid
	 * @param int $damage_pence
	 * @return int
	 */
	public static function post_deemed_refund_pence( $keep_price, $deemed_paid, $late_already_paid, $damage_pence ) {
		$price       = max( 0, (int) $keep_price );
		$paid        = max( 0, (int) $deemed_paid );
		$already     = max( 0, (int) $late_already_paid );
		$damage      = max( 0, (int) $damage_pence );
		$late_retain = max( 0, self::LATE_CAP_PENCE - $already );
		$retain      = min( $price, $late_retain + $damage );
		return max( 0, $paid - $retain );
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
