/**
 * Inline edit panel for one block: generated prop fields plus visibility, pin, schedule, animation,
 * tracking, group membership and block actions.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import type { Block, JsonObject, JsonValue, MappedError } from '../types';
import { blockDef, SOCIAL_NETWORKS } from '../registry/blocks';
import type { Option } from '../registry/blocks';
import Field from './Field';
import { useEditor, useEditorDispatch } from '../hooks';
import { getPath, setPath } from '../lib/paths';
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
import { blend, sectionIssue } from '../lib/contrast';
import { resolveColorTokens } from '../lib/theme';
import { boot } from '../boot';
import { blockWarnings } from '../lib/warnings';

interface Props {
	block: Block;
	errors: MappedError[];
	panelId: string;
}

function dynamicOptions( type: string ): {
	options: Record< string, Option[] >;
	itemOptions: Record< string, Option[] >;
} {
	const data = boot();
	if ( type === 'socials' ) {
		return {
			options: {},
			itemOptions: {
				network: SOCIAL_NETWORKS.map( ( n ) => ( {
					value: n,
					label: data.networks[ n ] ?? n,
				} ) ),
			},
		};
	}
	if ( type === 'payment' ) {
		const services = Object.entries( data.payments ).map(
			( [ value, p ] ) => ( { value, label: p.label } )
		);
		return {
			options: {
				service: [
					...services,
					{
						value: 'custom',
						label: __( 'Other (custom URL)', 'crazylinks' ),
					},
				],
			},
			itemOptions: {},
		};
	}
	if ( type === 'link' || type === 'heading' ) {
		return {
			options: {
				icon: [
					{ value: '', label: __( 'None', 'crazylinks' ) },
					...Object.entries( data.icons )
						.map( ( [ value, i ] ) => ( {
							value,
							label: i.title,
						} ) )
						.sort( ( a, b ) => a.label.localeCompare( b.label ) ),
				],
			},
			itemOptions: {},
		};
	}
	return { options: {}, itemOptions: {} };
}

/**
 * Contrast warning for a section's text on its background (light scheme of the page design).
 */
function SectionContrast( { props }: { props: JsonObject } ) {
	const design = useEditor( ( s ) => s.getDoc().design, [] );
	const themes = boot().themes;
	const slug =
		typeof design.theme === 'string' && design.theme
			? design.theme
			: 'default';
	const t = resolveColorTokens( design, themes[ slug ] ?? themes.default );
	const samples = t.bgTo
		? [ 0, 0.25, 0.5, 0.75, 1 ].map( ( o ) => blend( t.bgTo!, t.bg, o ) )
		: [ t.bg ];
	const issue = sectionIssue( props, t.text, samples );
	if ( ! issue ) {
		return null;
	}
	return (
		<Notice status="warning" isDismissible={ false }>
			{ sprintf(
				/* translators: 1: contrast ratio. */
				__(
					'Text in this section is hard to read on its background (%1$s:1, needs 4.5:1). Pick another text or background colour.',
					'crazylinks'
				),
				issue.ratio.toFixed( 1 )
			) }
		</Notice>
	);
}

/**
 * "Repeat weekly": weekday chips, start and end times, and a one-line summary.
 */
function WeeklyFields( {
	block,
	errors,
}: {
	block: Block;
	errors: MappedError[];
} ) {
	const { updateBlock } = useEditorDispatch();
	const weekly = block.schedule.weekly ?? null;
	// Typing a time coalesces into one undo step; day chips and the toggle are separate steps.
	const set = ( next: typeof weekly, key?: string ) =>
		updateBlock(
			block.id,
			{ schedule: withWeekly( block.schedule, next ) },
			key
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
						{ WEEKDAYS.map( ( d ) => {
							const on = weekly.days.includes( d );
							return (
								<Button
									key={ d }
									size="small"
									variant={ on ? 'primary' : 'secondary' }
									className="cl-weekly__day"
									aria-pressed={ on }
									aria-label={ weekdayLong( d ) }
									onClick={ () =>
										set( toggleWeekday( weekly, d ) )
									}
								>
									{ weekdayShort( d ) }
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
			{ messages.map( ( m, i ) => (
				<p key={ i } className="cl-field-error">
					{ m }
				</p>
			) ) }
		</div>
	);
}

export default function BlockPanel( { block, errors, panelId }: Props ) {
	const def = blockDef( block.type );
	const groups = useEditor( ( s ) => s.getGroups(), [] );
	const siblingsCount = useEditor(
		( s ) => s.getChildren( block.parent_id ).length,
		[ block.parent_id ]
	);
	const {
		setBlockProps,
		updateBlock,
		removeBlock,
		duplicateBlock,
		moveBlock,
		setParent,
		selectBlock,
	} = useEditorDispatch();
	const allWarnings = useEditor( ( s ) => s.getWarnings(), [] );
	const warnings = blockWarnings( allWarnings, block.id );
	const [ advanced, setAdvanced ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( false );
	const ref = useRef< HTMLDivElement >( null );
	const { options, itemOptions } = dynamicOptions( block.type );
	const caps = boot().caps;

	useEffect( () => {
		// Move focus into the panel when it opens.
		const first = ref.current?.querySelector< HTMLElement >(
			'input, textarea, select, button'
		);
		first?.focus();
	}, [ block.id ] );

	const fieldError = ( field: string ) =>
		errors.find( ( e ) => e.field === field )?.message;
	const blockLevel = errors.filter(
		( e ) => e.field === '' || ! e.field.startsWith( 'props/' )
	);
	const subErrors = ( key: string ) => {
		const out: Record< string, string > = {};
		for ( const e of errors ) {
			const prefix = `props/${ key }/`;
			if ( e.field.startsWith( prefix ) ) {
				out[ e.field.slice( prefix.length ) ] = e.message;
			}
		}
		return out;
	};

	const fieldWarning = ( field: string ) =>
		warnings.find( ( w ) => w.field === field )?.message;
	const subWarnings = ( key: string ) => {
		const out: Record< string, string > = {};
		for ( const w of warnings ) {
			const prefix = `props/${ key }/`;
			if ( w.field.startsWith( prefix ) ) {
				out[ w.field.slice( prefix.length ) ] = w.message;
			}
		}
		return out;
	};

	const visibleFields = def.fields.filter(
		( f ) => ! f.showIf || f.showIf( block.props )
	);
	const basic = visibleFields.filter( ( f ) => ! f.advanced );
	const more = visibleFields.filter( ( f ) => f.advanced );
	const locked = block.type === 'html' && ! caps.unfiltered_html;

	const renderField = ( f: ( typeof def.fields )[ number ] ) => {
		const path = f.key.split( '/' );
		return (
			<Field
				key={ f.key }
				def={ f }
				value={ getPath( block.props, path ) as JsonValue | undefined }
				options={ options[ f.key ] }
				itemOptions={ itemOptions }
				error={ fieldError( `props/${ f.key }` ) }
				subErrors={ subErrors( f.key ) }
				warning={ fieldWarning( `props/${ f.key }` ) }
				subWarnings={ subWarnings( f.key ) }
				imageId={
					f.focalOf
						? ( getPath( block.props, [ f.focalOf ] ) as
								number | undefined )
						: undefined
				}
				onChange={ ( v ) =>
					setBlockProps(
						block.id,
						v === undefined && f.clearsParent && path.length > 1
							? setPath( block.props, [ path[ 0 ] ], undefined )
							: setPath( block.props, path, v ),
						`${ block.id }:${ f.key }`
					)
				}
			/>
		);
	};

	return (
		<div className="cl-block-panel" id={ panelId } ref={ ref }>
			{ blockLevel.map( ( e, i ) => (
				<Notice key={ i } status="error" isDismissible={ false }>
					{ e.field && e.field !== '' ? (
						<code>{ e.field }</code>
					) : null }{ ' ' }
					{ e.message }
				</Notice>
			) ) }
			{ locked && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Only users allowed to post unfiltered HTML can change this block.',
						'crazylinks'
					) }
				</Notice>
			) }
			{ block.type === 'group' && (
				<SectionContrast props={ block.props } />
			) }
			<fieldset disabled={ locked } className="cl-block-panel__fields">
				{ basic.map( renderField ) }
				{ def.fields.length === 0 && (
					<p className="cl-muted">{ def.description }</p>
				) }
			</fieldset>

			<details
				className="cl-block-panel__more"
				open={ advanced }
				onToggle={ ( e ) =>
					setAdvanced( ( e.target as HTMLDetailsElement ).open )
				}
			>
				<summary>
					{ __( 'Visibility, schedule and more', 'crazylinks' ) }
				</summary>
				<fieldset
					disabled={ locked }
					className="cl-block-panel__fields"
				>
					{ more.map( renderField ) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Visible', 'crazylinks' ) }
						checked={ block.visible }
						onChange={ ( v: boolean ) =>
							updateBlock( block.id, { visible: v } )
						}
					/>
					{ block.parent_id === null && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Pin to top', 'crazylinks' ) }
							checked={ block.pinned }
							onChange={ ( v: boolean ) =>
								updateBlock( block.id, { pinned: v } )
							}
						/>
					) }
					{ def.trackable && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Count clicks', 'crazylinks' ) }
							checked={ block.tracking }
							onChange={ ( v: boolean ) =>
								updateBlock( block.id, { tracking: v } )
							}
						/>
					) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Attention animation', 'crazylinks' ) }
						help={ __(
							'Visitors who prefer reduced motion never see it.',
							'crazylinks'
						) }
						value={ block.animation }
						options={ [
							{
								value: 'none',
								label: __( 'None', 'crazylinks' ),
							},
							{
								value: 'pulse',
								label: __( 'Pulse', 'crazylinks' ),
							},
							{
								value: 'shake',
								label: __( 'Shake', 'crazylinks' ),
							},
							{
								value: 'wobble',
								label: __( 'Wobble', 'crazylinks' ),
							},
							{
								value: 'shimmer',
								label: __( 'Shimmer', 'crazylinks' ),
							},
						] }
						onChange={ ( v: string ) =>
							updateBlock( block.id, {
								animation: v as Block[ 'animation' ],
							} )
						}
					/>
					<div className="cl-schedule">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="datetime-local"
							label={ __( 'Show from', 'crazylinks' ) }
							value={ isoToLocalInput( block.schedule.start ) }
							onChange={ ( v: string ) =>
								updateBlock( block.id, {
									schedule: {
										...block.schedule,
										start: localInputToIso( v ),
									},
								} )
							}
						/>
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							type="datetime-local"
							label={ __( 'Hide after', 'crazylinks' ) }
							value={ isoToLocalInput( block.schedule.end ) }
							onChange={ ( v: string ) =>
								updateBlock( block.id, {
									schedule: {
										...block.schedule,
										end: localInputToIso( v ),
									},
								} )
							}
						/>
						{ fieldError( 'schedule' ) && (
							<p className="cl-field-error">
								{ fieldError( 'schedule' ) }
							</p>
						) }
						<WeeklyFields block={ block } errors={ errors } />
						{ block.type === 'group' &&
							( block.schedule.start ||
								block.schedule.end ||
								block.schedule.weekly ) && (
								<p className="cl-muted">
									{ __(
										'Everything inside this section follows its schedule.',
										'crazylinks'
									) }
								</p>
							) }
					</div>
					{ block.type !== 'group' && groups.length > 0 && (
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Section', 'crazylinks' ) }
							value={ block.parent_id ?? '' }
							options={ [
								{
									value: '',
									label: __(
										'Not in a section',
										'crazylinks'
									),
								},
								...groups.map( ( g ) => ( {
									value: g.id,
									label:
										( typeof g.props.heading === 'string' &&
											g.props.heading ) ||
										( typeof g.props.title === 'string' &&
											g.props.title ) ||
										__( 'Untitled section', 'crazylinks' ),
								} ) ),
							] }
							onChange={ ( v: string ) =>
								setParent( block.id, v === '' ? null : v )
							}
						/>
					) }
				</fieldset>
			</details>

			<div className="cl-block-panel__actions">
				<Button
					size="compact"
					icon="arrow-up-alt2"
					disabled={ block.order === 0 }
					onClick={ () => moveBlock( block.id, -1 ) }
				>
					{ __( 'Move up', 'crazylinks' ) }
				</Button>
				<Button
					size="compact"
					icon="arrow-down-alt2"
					disabled={ block.order >= siblingsCount - 1 }
					onClick={ () => moveBlock( block.id, 1 ) }
				>
					{ __( 'Move down', 'crazylinks' ) }
				</Button>
				<Button
					size="compact"
					icon="admin-page"
					disabled={ locked }
					onClick={ () => duplicateBlock( block.id ) }
				>
					{ __( 'Duplicate', 'crazylinks' ) }
				</Button>
				{ confirmDelete ? (
					<span
						className="cl-confirm"
						role="group"
						aria-label={ __( 'Confirm delete', 'crazylinks' ) }
					>
						<span>
							{ block.type === 'group'
								? __(
										'Delete this section and the blocks inside it?',
										'crazylinks'
									)
								: __( 'Delete this block?', 'crazylinks' ) }
						</span>
						<Button
							size="compact"
							variant="primary"
							isDestructive
							onClick={ () => {
								removeBlock( block.id );
								selectBlock( null );
							} }
						>
							{ __( 'Delete', 'crazylinks' ) }
						</Button>
						<Button
							size="compact"
							variant="tertiary"
							onClick={ () => setConfirmDelete( false ) }
						>
							{ __( 'Cancel', 'crazylinks' ) }
						</Button>
					</span>
				) : (
					<Button
						size="compact"
						icon="trash"
						isDestructive
						onClick={ () => setConfirmDelete( true ) }
					>
						{ sprintf(
							/* translators: %s: block type label. */ __(
								'Delete %s',
								'crazylinks'
							),
							def.label.toLowerCase()
						) }
					</Button>
				) }
			</div>
		</div>
	);
}
