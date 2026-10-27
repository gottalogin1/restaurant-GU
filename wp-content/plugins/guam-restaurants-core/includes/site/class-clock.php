<?php
/**
 * Guam local time for "open now".
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Every restaurant is in Guam, so opening hours are read in Guam time
 * (UTC+10, no daylight saving) whatever the site's own time zone setting.
 */
final class Clock {

	public const TIME_ZONE = 'Pacific/Guam';

	public const MINUTES_PER_DAY  = 1440;
	public const MINUTES_PER_WEEK = 10080;

	/**
	 * "Open late" means open at this hour tonight.
	 */
	public const LATE_HOUR = 22;

	/**
	 * Before this hour, "tonight" is still last night.
	 */
	public const NIGHT_ENDS_HOUR = 4;

	/**
	 * Current Unix time. Tests set it with the `guam_restaurants_now` filter.
	 *
	 * @return int
	 */
	public static function now(): int {
		/**
		 * Filters the current time used for "open now".
		 *
		 * @param int $timestamp Unix time.
		 */
		return (int) apply_filters( 'guam_restaurants_now', time() );
	}

	/**
	 * Guam time zone.
	 *
	 * @return \DateTimeZone
	 */
	public static function zone(): \DateTimeZone {
		return new \DateTimeZone( self::TIME_ZONE );
	}

	/**
	 * Today's date in Guam.
	 *
	 * @param int|null $timestamp Unix time; defaults to now().
	 * @return string Y-m-d.
	 */
	public static function today( ?int $timestamp = null ): string {
		return ( new \DateTimeImmutable( '@' . ( $timestamp ?? self::now() ) ) )->setTimezone( self::zone() )->format( 'Y-m-d' );
	}

	/**
	 * The moment a minute of the week next falls, from the start of today
	 * (Guam): today's minutes stay today, even when already past.
	 *
	 * @param int      $minute_of_week Minute of the week.
	 * @param int|null $timestamp      Unix time; defaults to now().
	 * @return int Unix time.
	 */
	public static function instant( int $minute_of_week, ?int $timestamp = null ): int {
		$now      = $timestamp ?? self::now();
		$midnight = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( self::zone() )->setTime( 0, 0 );
		$today    = (int) $midnight->format( 'N' ) - 1;
		$ahead    = ( intdiv( $minute_of_week, self::MINUTES_PER_DAY ) - $today + 7 ) % 7;
		return $midnight->getTimestamp() + $ahead * DAY_IN_SECONDS + ( $minute_of_week % self::MINUTES_PER_DAY ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Minutes since Monday 00:00, Guam time.
	 *
	 * @param int|null $timestamp Unix time; defaults to now().
	 * @return int 0 to 10079.
	 */
	public static function minute_of_week( ?int $timestamp = null ): int {
		$date = ( new \DateTimeImmutable( '@' . ( $timestamp ?? self::now() ) ) )->setTimezone( self::zone() );
		return ( (int) $date->format( 'N' ) - 1 ) * self::MINUTES_PER_DAY + (int) $date->format( 'G' ) * 60 + (int) $date->format( 'i' );
	}

	/**
	 * The minute of the week that "open late" checks: 10 pm tonight, Guam
	 * time, or now once it is past 10 pm (until 4 am, when the night ends).
	 *
	 * @param int|null $timestamp Unix time; defaults to now().
	 * @return int 0 to 10079.
	 */
	public static function late_minute( ?int $timestamp = null ): int {
		$timestamp = $timestamp ?? self::now();
		$hour      = (int) ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( self::zone() )->format( 'G' );
		$now       = self::minute_of_week( $timestamp );
		if ( $hour >= self::LATE_HOUR || $hour < self::NIGHT_ENDS_HOUR ) {
			return $now;
		}
		return intdiv( $now, self::MINUTES_PER_DAY ) * self::MINUTES_PER_DAY + self::LATE_HOUR * 60;
	}

	/**
	 * Formats a UTC MySQL datetime as a Guam date.
	 *
	 * @param string $utc    Y-m-d H:i:s in UTC.
	 * @param string $format PHP date format; defaults to the site's date format.
	 * @return string
	 */
	public static function local_date( string $utc, string $format = '' ): string {
		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		return wp_date( '' === $format ? (string) get_option( 'date_format', 'F j, Y' ) : $format, $timestamp, self::zone() );
	}
}
