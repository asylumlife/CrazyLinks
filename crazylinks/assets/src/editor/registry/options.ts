/**
 * Option lists used by more than one control (block fields and the Design tab). They are functions
 * so the labels are translated when a control renders, after the translations have loaded.
 */
import { __ } from '@wordpress/i18n';
import type { Animation } from '../types';
import { WEEKDAYS, weekdayLong } from '../lib/datetime';

export interface Option< V extends string = string > {
	value: V;
	label: string;
}

/**
 * Options from `[ value, label ]` pairs.
 *
 * @param pairs Value and label of each option.
 */
export const opts = ( pairs: Array< [ string, string ] > ): Option[] =>
	pairs.map( ( [ value, label ] ) => ( { value, label } ) );

/** Background kinds of the page and of a section. */
export const backgroundTypeOptions = (): Option[] =>
	opts( [
		[ 'solid', __( 'Solid colour', 'crazylinks' ) ],
		[ 'gradient', __( 'Gradient', 'crazylinks' ) ],
		[ 'image', __( 'Image', 'crazylinks' ) ],
		[ 'pattern', __( 'Pattern', 'crazylinks' ) ],
	] );

/**
 * Background patterns.
 *
 * @param dotsValue Value stored for the first pattern: `dots`, or '' where dots is the default.
 */
export const patternOptions = ( dotsValue = 'dots' ): Option[] =>
	opts( [
		[ dotsValue, __( 'Dots', 'crazylinks' ) ],
		[ 'grid', __( 'Grid', 'crazylinks' ) ],
		[ 'stripes', __( 'Stripes', 'crazylinks' ) ],
		[ 'lines', __( 'Lines', 'crazylinks' ) ],
		[ 'checks', __( 'Checks', 'crazylinks' ) ],
		[ 'noise', __( 'Noise', 'crazylinks' ) ],
	] );

/** Button fills of the page design and of a single link. */
export const buttonFillOptions = (): Option[] =>
	opts( [
		[ 'solid', __( 'Solid', 'crazylinks' ) ],
		[ 'outline', __( 'Outline', 'crazylinks' ) ],
		[ 'soft', __( 'Soft', 'crazylinks' ) ],
		[ 'glass', __( 'Glass', 'crazylinks' ) ],
	] );

/** Schema keys of the weekdays, Monday first (the order of `WEEKDAYS`). */
const WEEKDAY_KEYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];

/** Weekdays keyed `mon` … `sun`, Monday first. */
export const weekdayOptions = (): Option[] =>
	WEEKDAYS.map( ( day ) => ( {
		value: WEEKDAY_KEYS[ day - 1 ],
		label: weekdayLong( day ),
	} ) );

/** Attention animations a block can play. */
export const animationOptions = (): Option< Animation >[] => [
	{ value: 'none', label: __( 'None', 'crazylinks' ) },
	{ value: 'pulse', label: __( 'Pulse', 'crazylinks' ) },
	{ value: 'shake', label: __( 'Shake', 'crazylinks' ) },
	{ value: 'wobble', label: __( 'Wobble', 'crazylinks' ) },
	{ value: 'shimmer', label: __( 'Shimmer', 'crazylinks' ) },
];
