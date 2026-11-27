/**
 * Inline edit panel for one block: generated prop fields plus visibility, pin, schedule, animation,
 * tracking, group membership and block actions.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice, SelectControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import type {
	Animation,
	Block,
	JsonObject,
	JsonValue,
	MappedError,
} from '../types';
import { blockDef } from '../registry/blocks';
import type { FieldDef } from '../registry/blocks';
import { dynamicOptions } from '../registry/dynamicOptions';
import { animationOptions } from '../registry/options';
import BlockActions from './BlockActions';
import Field from './Field';
import ScheduleFields from './ScheduleFields';
import { useEditor, useEditorDispatch } from '../hooks';
import { getPath, setPath } from '../lib/paths';
import { backgroundSamples, sectionIssue } from '../lib/contrast';
import { subMessages } from '../lib/errors';
import { presetFor, resolveColorTokens } from '../lib/theme';
import { boot } from '../boot';
import { blockWarnings } from '../lib/warnings';

interface Props {
	block: Block;
	errors: MappedError[];
	panelId: string;
}

/**
 * Contrast warning for a section's text on its background (light scheme of the page design).
 *
 * @param props       Props.
 * @param props.props The section's props.
 */
function SectionContrast( { props }: { props: JsonObject } ) {
	const design = useEditor( ( s ) => s.getDoc().design, [] );
	const tokens = resolveColorTokens(
		design,
		presetFor( design, boot().themes ).preset
	);
	const issue = sectionIssue(
		props,
		tokens.text,
		backgroundSamples( tokens )
	);
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
 * Picks the section a block sits in ("Not in a section" moves it to the top level).
 *
 * @param props        Props.
 * @param props.block  Block.
 * @param props.groups Every section of the page.
 */
function SectionSelect( { block, groups }: { block: Block; groups: Block[] } ) {
	const { setParent } = useEditorDispatch();
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Section', 'crazylinks' ) }
			value={ block.parent_id ?? '' }
			options={ [
				{
					value: '',
					label: __( 'Not in a section', 'crazylinks' ),
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
	);
}

export default function BlockPanel( { block, errors, panelId }: Props ) {
	const def = blockDef( block.type );
	const groups = useEditor( ( s ) => s.getGroups(), [] );
	const { setBlockProps, updateBlock } = useEditorDispatch();
	const allWarnings = useEditor( ( s ) => s.getWarnings(), [] );
	const warnings = blockWarnings( allWarnings, block.id );
	const [ advanced, setAdvanced ] = useState( false );
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
	const fieldWarning = ( field: string ) =>
		warnings.find( ( w ) => w.field === field )?.message;
	// Errors that belong to no prop field are listed at the top of the panel.
	const blockLevel = errors.filter(
		( e ) => ! e.field.startsWith( 'props/' )
	);

	const visibleFields = def.fields.filter(
		( f ) => ! f.showIf || f.showIf( block.props )
	);
	const basic = visibleFields.filter( ( f ) => ! f.advanced );
	const more = visibleFields.filter( ( f ) => f.advanced );
	const locked = block.type === 'html' && ! caps.unfiltered_html;

	const renderField = ( f: FieldDef ) => {
		const path = f.key.split( '/' );
		const field = `props/${ f.key }`;
		return (
			<Field
				key={ f.key }
				def={ f }
				value={ getPath( block.props, path ) as JsonValue | undefined }
				options={ options[ f.key ] }
				itemOptions={ itemOptions }
				error={ fieldError( field ) }
				subErrors={ subMessages( errors, field ) }
				warning={ fieldWarning( field ) }
				subWarnings={ subMessages( warnings, field ) }
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
					{ e.field ? <code>{ e.field }</code> : null } { e.message }
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
					<SelectControl< Animation >
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Attention animation', 'crazylinks' ) }
						help={ __(
							'Visitors who prefer reduced motion never see it.',
							'crazylinks'
						) }
						value={ block.animation }
						options={ animationOptions() }
						onChange={ ( animation: Animation ) =>
							updateBlock( block.id, { animation } )
						}
					/>
					<ScheduleFields block={ block } errors={ errors } />
					{ block.type !== 'group' && groups.length > 0 && (
						<SectionSelect block={ block } groups={ groups } />
					) }
				</fieldset>
			</details>

			<BlockActions
				block={ block }
				typeLabel={ def.label }
				locked={ locked }
			/>
		</div>
	);
}
