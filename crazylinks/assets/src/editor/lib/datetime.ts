/**
 * Converts between schema date-times (RFC 3339, UTC "Z") and <input type="datetime-local"> values,
 * and edits weekly schedule windows.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { Schedule, WeeklyWindow } from '../types';

const pad = ( n: number ) => String( n ).padStart( 2, '0' );

/**
 * Local input value (YYYY-MM-DDTHH:MM) for an ISO date-time, or ''.
 *
 * @param iso ISO string or null.
 */
export function isoToLocalInput( iso: string | null | undefined ): string {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
}

/**
 * ISO date-time (UTC, no milliseconds) for a local input value, or null when empty/invalid.
 *
 * @param value datetime-local value.
 */
export function localInputToIso( value: string ): string | null {
	if ( ! value ) {
		return null;
	}
	const d = new Date( value );
	if ( Number.isNaN( d.getTime() ) ) {
		return null;
	}
	return d.toISOString().replace( /\.\d{3}Z$/, 'Z' );
}

/* Weekly windows (schema `schedule.weekly`): ISO weekdays and "HH:MM" times in the site timezone. */

/** ISO weekday numbers, Monday first. */
export const WEEKDAYS = [ 1, 2, 3, 4, 5, 6, 7 ] as const;

/** The window offered when "Repeat weekly" is switched on. */
export const DEFAULT_WEEKLY: WeeklyWindow = {
	days: [ 1, 2, 3, 4, 5 ],
	start: '09:00',
	end: '17:00',
};

const TIME = /^(?:[01]\d|2[0-3]):[0-5]\d$/;

/**
 * Whether a value is a 24-hour "HH:MM" time.
 *
 * @param value Value.
 */
export function isTime( value: unknown ): value is string {
	return typeof value === 'string' && TIME.test( value );
}

/**
 * Short weekday name for an ISO weekday (1 = Monday).
 *
 * @param day ISO weekday.
 */
export function weekdayShort( day: number ): string {
	return (
		[
			__( 'Mon', 'crazylinks' ),
			__( 'Tue', 'crazylinks' ),
			__( 'Wed', 'crazylinks' ),
			__( 'Thu', 'crazylinks' ),
			__( 'Fri', 'crazylinks' ),
			__( 'Sat', 'crazylinks' ),
			__( 'Sun', 'crazylinks' ),
		][ day - 1 ] ?? ''
	);
}

/**
 * Full weekday name for an ISO weekday (1 = Monday).
 *
 * @param day ISO weekday.
 */
export function weekdayLong( day: number ): string {
	return (
		[
			__( 'Monday', 'crazylinks' ),
			__( 'Tuesday', 'crazylinks' ),
			__( 'Wednesday', 'crazylinks' ),
			__( 'Thursday', 'crazylinks' ),
			__( 'Friday', 'crazylinks' ),
			__( 'Saturday', 'crazylinks' ),
			__( 'Sunday', 'crazylinks' ),
		][ day - 1 ] ?? ''
	);
}

/**
 * Sorted, de-duplicated ISO weekdays.
 *
 * @param days Days.
 */
export function cleanDays( days: unknown ): number[] {
	const list = Array.isArray( days ) ? days : [];
	return [
		...new Set(
			list.filter(
				( d ): d is number =>
					typeof d === 'number' &&
					Number.isInteger( d ) &&
					d >= 1 &&
					d <= 7
			)
		),
	].sort( ( a, b ) => a - b );
}

/**
 * Days as runs: [1,2,3,4] → "Mon–Thu", [1,3,5] → "Mon, Wed, Fri", all seven → "Every day".
 *
 * @param days ISO weekdays.
 */
export function formatDays( days: number[] ): string {
	const list = cleanDays( days );
	if ( list.length === 7 ) {
		return __( 'Every day', 'crazylinks' );
	}
	const runs: Array< [ number, number ] > = [];
	for ( const d of list ) {
		const last = runs[ runs.length - 1 ];
		if ( last && d === last[ 1 ] + 1 ) {
			last[ 1 ] = d;
		} else {
			runs.push( [ d, d ] );
		}
	}
	return runs
		.map( ( [ a, b ] ) =>
			a === b
				? weekdayShort( a )
				: `${ weekdayShort( a ) }–${ weekdayShort( b ) }`
		)
		.join( ', ' );
}

/**
 * One-line summary of a weekly window, e.g. "Mon–Thu, 12:30–14:00, site time".
 *
 * @param weekly Window.
 */
export function weeklySummary(
	weekly: WeeklyWindow | null | undefined
): string {
	if ( ! weekly ) {
		return '';
	}
	const days = cleanDays( weekly.days );
	if ( ! days.length ) {
		return __( 'Pick at least one day.', 'crazylinks' );
	}
	if ( ! isTime( weekly.start ) || ! isTime( weekly.end ) ) {
		return __( 'Enter a start and end time.', 'crazylinks' );
	}
	let times = `${ weekly.start }–${ weekly.end }`;
	if ( weekly.end === weekly.start ) {
		times = sprintf(
			/* translators: %s: start time. */
			__( 'all day from %s', 'crazylinks' ),
			weekly.start
		);
	} else if ( weekly.end < weekly.start ) {
		times = sprintf(
			/* translators: %s: time range such as 22:00–01:00. */
			__( '%s (ends the next day)', 'crazylinks' ),
			times
		);
	}
	return sprintf(
		/* translators: 1: days such as Mon–Thu, 2: times such as 12:30–14:00. */
		__( '%1$s, %2$s, site time', 'crazylinks' ),
		formatDays( days ),
		times
	);
}

/**
 * Adds or removes one weekday.
 *
 * @param weekly Window.
 * @param day    ISO weekday.
 */
export function toggleWeekday(
	weekly: WeeklyWindow,
	day: number
): WeeklyWindow {
	const days = cleanDays( weekly.days );
	return {
		...weekly,
		days: days.includes( day )
			? days.filter( ( d ) => d !== day )
			: cleanDays( [ ...days, day ] ),
	};
}

/**
 * Sets the start or end time ("" from a cleared input is kept so the field stays editable).
 *
 * @param weekly Window.
 * @param which  start | end.
 * @param value  "HH:MM" from <input type="time"> (may carry seconds).
 */
export function setWeeklyTime(
	weekly: WeeklyWindow,
	which: 'start' | 'end',
	value: string
): WeeklyWindow {
	return { ...weekly, [ which ]: value.slice( 0, 5 ) };
}

/**
 * The schedule with the weekly window set, or removed when `weekly` is null.
 *
 * @param schedule Schedule.
 * @param weekly   Window or null.
 */
export function withWeekly(
	schedule: Schedule,
	weekly: WeeklyWindow | null
): Schedule {
	const next: Schedule = { start: schedule.start, end: schedule.end };
	if ( weekly ) {
		next.weekly = weekly;
	}
	return next;
}
