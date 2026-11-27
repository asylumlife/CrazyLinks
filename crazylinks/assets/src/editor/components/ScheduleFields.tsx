/**
 * A block's schedule: "show from" and "hide after" date-times, and a weekly repeating window.
 */
import { __ } from '@wordpress/i18n';
import { Button, TextControl, ToggleControl } from '@wordpress/components';
import type { Block, MappedError, WeeklyWindow } from '../types';
import FieldError from './FieldError';
import { useEditorDispatch } from '../hooks';
import { isScheduled } from '../store/doc';
import {
	DEFAULT_WEEKLY,
	WEEKDAYS,
	isoToLocalInput,
	localInputToIso,
	setWeeklyTime,
	toggleWeekday,
	weekdayLong,
	weekdayShort,
	weeklySummary,
	withWeekly,
} from '../lib/datetime';

interface Props {
	block: Block;
	/** The block's errors. */
	errors: MappedError[];
}

/**
 * "Repeat weekly": weekday chips, start and end times, and a one-line summary.
 *
 * @param props        Props.
 * @param props.block  Block.
 * @param props.errors The block's errors.
 */
function WeeklyFields( { block, errors }: Props ) {
	const { updateBlock } = useEditorDispatch();
	const weekly = block.schedule.weekly ?? null;
	// Typing a time coalesces into one undo step; day chips and the toggle are separate steps.
	const set = ( next: WeeklyWindow | null, undoKey?: string ) =>
		updateBlock(
			block.id,
			{ schedule: withWeekly( block.schedule, next ) },
			undoKey
		);
	const messages = errors
		.filter( ( e ) => e.field.startsWith( 'schedule/weekly' ) )
		.map( ( e ) => e.message );
	const summaryId = `cl-weekly-${ block.id }`;
	return (
		<div className="cl-weekly">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Repeat weekly', 'crazylinks' ) }
				help={
					weekly
						? undefined
						: __(
								'Show only on some days, between two times (site timezone).',
								'crazylinks'
							)
				}
				checked={ !! weekly }
				onChange={ ( on: boolean ) =>
					set( on ? { ...DEFAULT_WEEKLY } : null )
				}
			/>
			{ weekly && (
				<>
					<div
						className="cl-weekly__days"
						role="group"
						aria-label={ __( 'Days', 'crazylinks' ) }
					>
						{ WEEKDAYS.map( ( day ) => {
							const on = weekly.days.includes( day );
							return (
								<Button
									key={ day }
									size="small"
									variant={ on ? 'primary' : 'secondary' }
									className="cl-weekly__day"
									aria-pressed={ on }
									aria-label={ weekdayLong( day ) }
									onClick={ () =>
										set( toggleWeekday( weekly, day ) )
									}
								>
									{ weekdayShort( day ) }
								</Button>
							);
						} ) }
					</div>
					<div className="cl-weekly__times">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="time"
							label={ __( 'From', 'crazylinks' ) }
							value={ weekly.start }
							aria-describedby={ summaryId }
							onChange={ ( v: string ) =>
								set(
									setWeeklyTime( weekly, 'start', v ),
									`${ block.id }:weekly-start`
								)
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="time"
							label={ __( 'Until', 'crazylinks' ) }
							value={ weekly.end }
							aria-describedby={ summaryId }
							onChange={ ( v: string ) =>
								set(
									setWeeklyTime( weekly, 'end', v ),
									`${ block.id }:weekly-end`
								)
							}
						/>
					</div>
					<p
						className="cl-weekly__summary"
						id={ summaryId }
						aria-live="polite"
					>
						{ weeklySummary( weekly ) }
					</p>
				</>
			) }
			{ messages.map( ( message, i ) => (
				<FieldError key={ i } message={ message } />
			) ) }
		</div>
	);
}

export default function ScheduleFields( { block, errors }: Props ) {
	const { updateBlock } = useEditorDispatch();
	const setDate = ( edge: 'start' | 'end', value: string ) =>
		updateBlock( block.id, {
			schedule: { ...block.schedule, [ edge ]: localInputToIso( value ) },
		} );
	return (
		<div className="cl-schedule">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="datetime-local"
				label={ __( 'Show from', 'crazylinks' ) }
				value={ isoToLocalInput( block.schedule.start ) }
				onChange={ ( v: string ) => setDate( 'start', v ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="datetime-local"
				label={ __( 'Hide after', 'crazylinks' ) }
				value={ isoToLocalInput( block.schedule.end ) }
				onChange={ ( v: string ) => setDate( 'end', v ) }
			/>
			<FieldError
				message={
					errors.find( ( e ) => e.field === 'schedule' )?.message
				}
			/>
			<WeeklyFields block={ block } errors={ errors } />
			{ block.type === 'group' && isScheduled( block ) && (
				<p className="cl-muted">
					{ __(
						'Everything inside this section follows its schedule.',
						'crazylinks'
					) }
				</p>
			) }
		</div>
	);
}
