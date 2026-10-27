<?php
/**
 * UK calendar maths for the De-Reacher demo loan.
 *
 * Dates are Y-m-d strings. Working days are Monday to Friday in England and
 * Wales, minus the filterable bank-holiday list. The list is not Scotland or
 * Northern Ireland.
 *
 * @package Creature_Demo_Loan
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Date helpers. No WooCommerce and no clock except today().
 */
final class Creature_Demo_Loan_Calendar {

	/**
	 * England and Wales bank holidays, 2026–2028. Filter
	 * creature_demo_loan_bank_holidays replaces the whole list.
	 *
	 * @return string[]
	 */
	public static function default_bank_holidays() {
		return array(
			'2026-01-01',
			'2026-04-03',
			'2026-04-06',
			'2026-05-04',
			'2026-05-25',
			'2026-08-31',
			'2026-12-25',
			'2026-12-28',
			'2027-01-01',
			'2027-03-26',
			'2027-03-29',
			'2027-05-03',
			'2027-05-31',
			'2027-08-30',
			'2027-12-27',
			'2027-12-28',
			'2028-01-03',
			'2028-04-14',
			'2028-04-17',
			'2028-05-01',
			'2028-05-29',
			'2028-08-28',
			'2028-12-25',
			'2028-12-26',
		);
	}

	/**
	 * @param mixed $as_of Y-m-d, or null for today in Europe/London.
	 * @return string
	 */
	public static function today( $as_of = null ) {
		if ( is_string( $as_of ) && self::is_ymd( $as_of ) ) {
			return $as_of;
		}
		try {
			$now = new DateTime( 'now', new DateTimeZone( 'Europe/London' ) );
		} catch ( Exception $e ) {
			$now = new DateTime( 'now' );
		}
		return $now->format( 'Y-m-d' );
	}

	/**
	 * @param mixed $ymd
	 * @return bool
	 */
	public static function is_ymd( $ymd ) {
		return is_string( $ymd ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd );
	}

	/**
	 * @param string $ymd
	 * @param int    $days Negative moves backward.
	 * @return string
	 */
	public static function add_days( $ymd, $days ) {
		$dt = self::at_noon( $ymd );
		if ( ! $dt ) {
			return is_string( $ymd ) ? $ymd : '';
		}
		$delta = (int) $days;
		if ( 0 !== $delta ) {
			$dt->modify( ( $delta > 0 ? '+' : '' ) . $delta . ' days' );
		}
		return $dt->format( 'Y-m-d' );
	}

	/**
	 * Signed whole days from $from to $to. Negative when $to is earlier.
	 *
	 * @param string $from
	 * @param string $to
	 * @return int
	 */
	public static function days_between( $from, $to ) {
		$a = self::at_noon( $from );
		$b = self::at_noon( $to );
		if ( ! $a || ! $b ) {
			return 0;
		}
		return (int) $a->diff( $b )->format( '%r%a' );
	}

	/**
	 * @param string   $ymd
	 * @param string[] $holidays
	 * @return bool
	 */
	public static function is_working_day( $ymd, array $holidays ) {
		$dt = self::at_noon( $ymd );
		if ( ! $dt ) {
			return false;
		}
		$n = (int) $dt->format( 'N' );
		if ( $n >= 6 ) {
			return false;
		}
		return ! in_array( $ymd, $holidays, true );
	}

	/**
	 * The date that is $n working days after $ymd. $n of 0 returns $ymd.
	 *
	 * @param string   $ymd
	 * @param int      $n
	 * @param string[] $holidays
	 * @return string
	 */
	public static function add_working_days( $ymd, $n, array $holidays ) {
		$left   = (int) $n;
		$cursor = $ymd;
		$guard  = 0;
		while ( $left > 0 && $guard < 400 ) {
			$cursor = self::add_days( $cursor, 1 );
			if ( self::is_working_day( $cursor, $holidays ) ) {
				$left--;
			}
			$guard++;
		}
		return $cursor;
	}

	/**
	 * Working days strictly after $from, up to and including $to.
	 * A weekend or bank holiday adds nothing. $to on or before $from is 0.
	 *
	 * @param string   $from Notice date.
	 * @param string   $to   As-of date.
	 * @param string[] $holidays
	 * @return int
	 */
	public static function working_days_since( $from, $to, array $holidays ) {
		if ( ! self::is_ymd( $from ) || ! self::is_ymd( $to ) || $to <= $from ) {
			return 0;
		}
		$count  = 0;
		$cursor = $from;
		$guard  = 0;
		while ( $cursor < $to && $guard < 400 ) {
			$cursor = self::add_days( $cursor, 1 );
			if ( self::is_working_day( $cursor, $holidays ) ) {
				$count++;
			}
			$guard++;
		}
		return $count;
	}

	/**
	 * Dispatch plus the configured working days. Default is 2.
	 *
	 * @param string        $dispatch
	 * @param string[]|null $holidays
	 * @return string
	 */
	public static function default_delivery_date( $dispatch, $holidays = null ) {
		if ( ! is_array( $holidays ) ) {
			$holidays = self::default_bank_holidays();
		}
		$days = 2;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'creature_demo_loan_dispatch_working_days', 2 );
		}
		if ( $days < 0 ) {
			$days = 2;
		}
		return self::add_working_days( $dispatch, $days, $holidays );
	}

	/**
	 * Inclusive loan. Day 1 is the delivery date, so a 21-day loan is due
	 * on delivery + 20 days (terms clause 3.1).
	 *
	 * @param string   $delivery
	 * @param int|null $loan_days
	 * @return string
	 */
	public static function due_date( $delivery, $loan_days = null ) {
		$days = null === $loan_days ? 21 : (int) $loan_days;
		if ( $days < 1 ) {
			$days = 21;
		}
		return self::add_days( $delivery, $days - 1 );
	}

	/**
	 * Last day of the fee-free grace period (due + 5 by default).
	 *
	 * @param string   $due
	 * @param int|null $grace_days
	 * @return string
	 */
	public static function grace_end( $due, $grace_days = null ) {
		$days = null === $grace_days ? 5 : (int) $grace_days;
		if ( $days < 0 ) {
			$days = 5;
		}
		return self::add_days( $due, $days );
	}

	/**
	 * First day the deemed purchase can be raised (due + 14). A scan on or
	 * before the day before this avoids it. Late-fee days are 6 through 13.
	 *
	 * @param string   $due
	 * @param int|null $after
	 * @return string
	 */
	public static function deemed_on( $due, $after = null ) {
		$days = null === $after ? 14 : (int) $after;
		if ( $days < 1 ) {
			$days = 14;
		}
		return self::add_days( $due, $days );
	}

	/**
	 * Billable late days for a return that many days after the due date.
	 * Days 0–5 are grace. Days 6–13 are £5 each (8 days). Later offsets stay
	 * at 8 because the cap is reached at the deemed-purchase point.
	 *
	 * @param int $days_after_due
	 * @return int
	 */
	public static function late_fee_days( $days_after_due ) {
		$d = (int) $days_after_due;
		if ( $d <= 5 ) {
			return 0;
		}
		$last = min( $d, 13 );
		return $last - 5;
	}

	/**
	 * @param string $ymd
	 * @return DateTime|null
	 */
	private static function at_noon( $ymd ) {
		if ( ! self::is_ymd( $ymd ) ) {
			return null;
		}
		$dt = DateTime::createFromFormat( '!Y-m-d', $ymd, new DateTimeZone( 'UTC' ) );
		if ( ! $dt ) {
			return null;
		}
		$dt->setTime( 12, 0, 0 );
		return $dt;
	}
}
