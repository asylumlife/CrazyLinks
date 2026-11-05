<?php
/**
 * Block schedule evaluation: a one-off range plus an optional weekly window.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over a block's `schedule` object:
 *
 * - `start` / `end`: ISO 8601 instants (one-off range, either end optional).
 * - `weekly`: `{days: [1..7 ISO weekdays], start: "HH:MM", end: "HH:MM"}` in the site timezone.
 *   A window belongs to the day it starts on; an end at or before the start wraps past midnight
 *   (22:00–01:00 on Monday covers Monday 22:00 to Tuesday 01:00); equal times mean a full day.
 *
 * Both parts must be satisfied when both are set. Wall-clock times are resolved with
 * DateTimeImmutable in the given zone, so daylight-saving changes follow the site zone: a start that
 * falls in the spring-forward gap moves to the first valid minute after it, and an ambiguous
 * fall-back time resolves to its first occurrence.
 */
final class Schedule {

	/**
	 * The furthest ahead a cache lifetime is computed (the render and page caches are capped here).
	 */
	public const HORIZON = DAY_IN_SECONDS;

	/**
	 * Minutes after midnight for "HH:MM", or null.
	 *
	 * @param mixed $value Time string.
	 */
	public static function minutes( $value ): ?int {
		if ( ! is_string( $value ) || ! preg_match( '/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $value, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/**
	 * The weekly window of a schedule in normalised form, or null when absent or unusable.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 * @return array{days:int[], start:int, end:int}|null
	 */
	public static function weekly( array $schedule ): ?array {
		$w = $schedule['weekly'] ?? null;
		if ( ! is_array( $w ) ) {
			return null;
		}
		$days  = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $w['days'] ?? [] ) ), static fn( int $d ): bool => $d >= 1 && $d <= 7 ) ) );
		$start = self::minutes( $w['start'] ?? null );
		$end   = self::minutes( $w['end'] ?? null );
		if ( ! $days || null === $start || null === $end ) {
			return null;
		}
		sort( $days );
		return [
			'days'  => $days,
			'start' => $start,
			'end'   => $end,
		];
	}

	/**
	 * Whether a schedule has any constraint at all.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 */
	public static function is_set( array $schedule ): bool {
		return self::instant( $schedule['start'] ?? null ) !== null || self::instant( $schedule['end'] ?? null ) !== null || null !== self::weekly( $schedule );
	}

	/**
	 * Unix time of an ISO string, or null.
	 *
	 * @param mixed $value Value.
	 */
	private static function instant( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : $ts;
	}

	/**
	 * Occurrences [start, end) of a weekly window whose start day lies between the local day
	 * before $from and the local day of $to.
	 *
	 * @param array{days:int[], start:int, end:int} $w    Normalised window.
	 * @param int                                   $from Unix time.
	 * @param int                                   $to   Unix time.
	 * @param \DateTimeZone                         $tz   Zone.
	 * @return array<int, array{0:int, 1:int}>
	 */
	public static function occurrences( array $w, int $from, int $to, \DateTimeZone $tz ): array {
		$day  = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $tz )->setTime( 0, 0 )->modify( '-1 day' );
		$last = ( new \DateTimeImmutable( '@' . $to ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$out  = [];
		$days = 3 + (int) ceil( max( 0, $to - $from ) / DAY_IN_SECONDS ); // Upper bound on local days to visit, from the day before $from.
		for ( $i = 0; $i < $days && $i < 400; $i++ ) {
			if ( $day->format( 'Y-m-d' ) > $last ) {
				break;
			}
			if ( in_array( (int) $day->format( 'N' ), $w['days'], true ) ) {
				$start   = $day->setTime( intdiv( $w['start'], 60 ), $w['start'] % 60 );
				$end_day = $w['end'] > $w['start'] ? $day : $day->modify( '+1 day' );
				$end     = $end_day->setTime( intdiv( $w['end'], 60 ), $w['end'] % 60 );
				if ( $end->getTimestamp() > $start->getTimestamp() ) {
					$out[] = [ $start->getTimestamp(), $end->getTimestamp() ];
				}
			}
			$day = $day->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * Whether a schedule allows showing the block at $now.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 * @param int                  $now      Unix time.
	 * @param \DateTimeZone        $tz       Site zone.
	 */
	public static function is_active( array $schedule, int $now, \DateTimeZone $tz ): bool {
		$start = self::instant( $schedule['start'] ?? null );
		$end   = self::instant( $schedule['end'] ?? null );
		if ( ( null !== $start && $start > $now ) || ( null !== $end && $end <= $now ) ) {
			return false;
		}
		$w = self::weekly( $schedule );
		if ( null === $w ) {
			return true;
		}
		foreach ( self::occurrences( $w, $now, $now, $tz ) as [ $a, $b ] ) {
			if ( $a <= $now && $now < $b ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The next moment after $now at which the schedule's state may change (a one-off start or end,
	 * or a weekly window opening or closing), or null when it never changes again.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 * @param int                  $now      Unix time.
	 * @param \DateTimeZone        $tz       Site zone.
	 */
	public static function next_boundary( array $schedule, int $now, \DateTimeZone $tz ): ?int {
		$start = self::instant( $schedule['start'] ?? null );
		$end   = self::instant( $schedule['end'] ?? null );
		$next  = null;
		$note  = static function ( ?int $ts ) use ( &$next, $now ): void {
			if ( null !== $ts && $ts > $now && ( null === $next || $ts < $next ) ) {
				$next = $ts;
			}
		};
		$note( $start );
		$note( $end );
		$w = self::weekly( $schedule );
		if ( null === $w || ( null !== $end && $end <= $now ) ) {
			return $next;
		}
		// From the later of now and the one-off start, the first weekly transition (8 days covers a full week).
		$from = max( $now, $start ?? $now );
		foreach ( self::occurrences( $w, $from, $from + 8 * DAY_IN_SECONDS, $tz ) as [ $a, $b ] ) {
			$note( $a >= $from ? $a : null );
			$note( $b >= $from ? $b : null );
		}
		return $next;
	}

	/**
	 * For a schedule that is active at $now: when it next closes (the one-off end or the end of the
	 * current weekly window, following back-to-back windows), or null when it stays open.
	 *
	 * @param array<string, mixed> $schedule Schedule.
	 * @param int                  $now      Unix time.
	 * @param \DateTimeZone        $tz       Site zone.
	 */
	public static function closes_at( array $schedule, int $now, \DateTimeZone $tz ): ?int {
		$end = self::instant( $schedule['end'] ?? null );
		$w   = self::weekly( $schedule );
		if ( null === $w ) {
			return $end;
		}
		$close = null;
		$list  = self::occurrences( $w, $now, $now + 8 * DAY_IN_SECONDS, $tz );
		foreach ( $list as [ $a, $b ] ) {
			if ( null === $close && $a <= $now && $now < $b ) {
				$close = $b;
			} elseif ( null !== $close && $a <= $close && $b > $close ) {
				$close = $b; // Contiguous windows (for example full days) stay open.
			}
		}
		if ( null === $close ) {
			return $end;
		}
		return null === $end ? $close : min( $end, $close );
	}

	/**
	 * Seconds until the earliest schedule boundary among a document's visible blocks, capped at
	 * HORIZON and at least 60, or null when no visible block has a schedule that can still change.
	 * Drives the page-cache policy: such pages must not be frozen by a full-page cache.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @param int                  $now Unix time.
	 * @param \DateTimeZone        $tz  Site zone.
	 */
	public static function page_ttl( array $doc, int $now, \DateTimeZone $tz ): ?int {
		$next = null;
		foreach ( (array) ( $doc['blocks'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) || empty( $block['visible'] ) || ! is_array( $block['schedule'] ?? null ) ) {
				continue;
			}
			$ts = self::next_boundary( $block['schedule'], $now, $tz );
			if ( null !== $ts && ( null === $next || $ts < $next ) ) {
				$next = $ts;
			}
		}
		return null === $next ? null : max( 60, min( self::HORIZON, $next - $now ) );
	}
}
